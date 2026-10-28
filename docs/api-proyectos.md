# API fase 2: contrato de proyectos

Prefijo `/api/v1`; sesión+CSRF o Bearer. Envelope `{data,meta}`. IDs y versiones
como strings, progreso entero, importes/cantidades strings. Fechas DATE reales
`YYYY-MM-DD` o null; timestamps UTC ISO8601. Campos desconocidos rechazados.
Listas paginadas `limit` (25, máximo100), `offset`, `meta.total/limit/offset`.

## Versiones y dinero
Toda edición, transición, reemplazo de miembros y reordenación exige `version`
o `expected_version` o `If-Match: "N"`; varios deben coincidir. Conflicto 409
`VERSION_CONFLICT`. Crear/editar proyecto con importe o cargos exige
`Idempotency-Key` (1–100 caracteres ASCII imprimibles sin espacios). Se guarda
respuesta y efecto en la misma transacción; repetir misma clave/cuerpo devuelve
resultado original, cambiar cuerpo da409 `IDEMPOTENCY_KEY_REUSED`.
Antes de devolver cualquier snapshot idempotente se bloquea y reautoriza el
proyecto actual; transferir su liderazgo invalida el acceso del antiguo líder
también al replay. PATCH exclusivamente operativo no exige clave.
Mutaciones de etapas/tareas/cargos incrementan también versión del proyecto.
Comentarios no incrementan versión. Respuestas de miembros/reordenación incluyen
`meta.project_version`; el resto devuelve el recurso con su versión.

## Proyectos
- `GET /projects`: filtros `q,type,status,leader_id,client_id`.
- `POST /projects`: `{name,description,type,leader_id?,client_id?,billable?,base_amount?,priority?,planned_start?,planned_end?,actual_start?,actual_end?,notes?}`.
  Estado BORRADOR, moneda MXN. ADMIN selecciona líder activo LIDER_PROYECTO;
  LIDER crea solo bajo su liderazgo. EXTERNO exige cliente activo, precio positivo
  y billable=true; INTERNO permite cero y no cobrable por defecto.
- `GET/PATCH /projects/{id}`: PATCH mismos campos más versión, nunca estado.
- `POST /projects/{id}/transitions`: `{version,status,reason?,exception?}`.
  BORRADOR→PLANIFICADO→EN_CURSO↔PAUSADO; EN_CURSO/PAUSADO→FINALIZADO.
  Cualquier no terminal→CANCELADO con motivo. Reabrir terminal a EN_CURSO solo
  ADMIN con motivo. Finalizar exige tareas obligatorias COMPLETADA, incluso
  canceladas; excepción ADMIN con reason no vacío y exception=true.
- `GET/PUT /projects/{id}/members`: PUT `{version,user_ids:["id",...]}` reemplaza
  lista completa; activos; no retirar asignado a tarea no cancelada.
- `GET /projects/{id}/payments`: historial solo lectura y `meta.summary` con
  base_amount,charges_total,total,paid_amount,refunded_amount,net_paid,balance,
  payment_status,billable,value_kind. No POST pagos en fase2.

Proyecto serializado: campos de negocio anteriores, id,folio,status,currency,
version,created_at,updated_at,task_count,completed_tasks,progress,has_tasks.
Solo ADMIN/líder dueño recibe notes,base_amount y `financial_summary`.

## Etapas
- `GET/POST /projects/{id}/stages`: POST `{name,description?,start_date?,end_date?}`.
- `PATCH /stages/{id}`: `{version,name?,description?,start_date?,end_date?,status?}`.
  Estados PENDIENTE,EN_CURSO,COMPLETADA,CANCELADA.
- `POST /projects/{id}/stages/reorder`: `{version,stage_ids:["id",...]}`; conjunto
  completo sin duplicados. Devuelve etapas ordenadas.

## Tareas y agenda
- `GET/POST /stages/{id}/tasks`: POST `{title,description?,assignee_id,priority?,start_date?,end_date?,is_required?}`.
- `POST /standalone-tasks`: mismo cuerpo; solo ADMIN, asignado activo cualquier rol.
  En proyecto asignado COLABORADOR activo miembro. No aceptar stage_id/project_id.
- `GET /tasks`: mis tareas por defecto; filtros `q,status,project_id,stage_id,assignee_id,standalone`.
- `GET/PATCH /tasks/{id}`: PATCH `{version,title?,description?,assignee_id?,priority?,start_date?,end_date?,actual_start?,actual_end?,is_required?,progress?}`.
  Responsable solo progress; fechas/alcance/asignación ADMIN/líder dueño.
- `POST /tasks/{id}/transitions`: `{version,status,reason?}`; PENDIENTE,
  EN_CURSO,BLOQUEADA,COMPLETADA,CANCELADA; completar fija100 y fechas reales,
  reapertura terminal a EN_CURSO exige motivo y conserva historial.
- `GET/POST /tasks/{id}/comments`: POST `{body}`; comentarios inmutables visibles
  solo con tarea visible, autor y fecha de servidor.
- `GET /agenda?from=YYYY-MM-DD&to=YYYY-MM-DD`: proyección **solo tareas propias**
  de fase2, paginada, máximo366 días; devuelve tareas con `all_day:true`.
  Sin reuniones/reservas hasta sus fases. Tareas sin fechas no aparecen.

## Cargos
- `GET/POST /projects/{id}/charges`: POST `{concept,quantity,unit_price,charge_date}`.
- `PATCH /project-charges/{id}`: `{version,concept?,quantity?,unit_price?,charge_date?,status?}`.
  Status ACTIVO/ANULADO; quantity string3, unit_price string2; amount calculado.
  Solo proyectos INTERNO, ADMIN/líder dueño. No generan pagos ni gastos.

Fechas contenidas en padre; cambios de ancestros comprueban descendientes.
Prioridades BAJA,MEDIA,ALTA,URGENTE. Progreso completadas/no canceladas entero,
sin tareas0 y has_tasks=false. Proyectos terminales no editables (`PROJECT_CLOSED`).
Ajustes comerciales con cobros solo ADMIN; nunca total menor que neto cobrado
(`TOTAL_BELOW_PAID`). Cliente inactivo no sirve para nuevas operaciones.
Errores 422 `VALIDATION_FAILED`,403 `FORBIDDEN`,404 `NOT_FOUND`,409
`INVALID_TRANSITION`,`REQUIRED_TASKS_INCOMPLETE`,`MEMBER_HAS_TASKS`.
También `TASK_CLOSED` y `STAGE_CLOSED` protegen recursos terminales.

Propuestas conservadoras: líder de rol LIDER_PROYECTO; tareas canceladas requeridas
impiden finalizar; reapertura proyecto ADMIN; ajustes con cobros ADMIN; BCMath
requisito sin motor alternativo simultáneo. Interno no cobrable es valor estimado,
no cuenta por cobrar ni ingreso; pagos y gastos nunca modificados por esta API.
