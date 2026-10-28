# Esquema de base de datos

> Documento vivo. Lo mantiene `arquitecto-datos`. El esquema se materializa en
> `database/migrations/`; este archivo explica el *porqué* y lo que no es evidente en el SQL.
> Fuente normativa: `docs/requerimiento-v1.0.md` §14 (y §5-§13 para el significado de cada regla).

Estado: **fase 1 cerrada**. 6 migraciones (`001`–`006`) y 3 semillas aplicadas desde cero en
MariaDB **10.4.32** (`gestion_test`): **34 tablas**, 0 no-InnoDB, todas `utf8mb4_unicode_ci`,
**63 claves foráneas**, **39 `CHECK` explícitos** (+7 que MariaDB genera para columnas `JSON`).
Verificado también que las semillas son idempotentes (segunda pasada sin errores ni duplicados).

Fase 2: migración **007** añade `tasks.is_required`, sin modificar las migraciones aplicadas.
No añade tablas, índices ni estados. El estado de verificación se detalla al final.

## Convenciones

- InnoDB, `utf8mb4_unicode_ci`, PK `BIGINT UNSIGNED`; FK del mismo tipo y sin `CASCADE` hacia
  dinero.
- Importes `DECIMAL(12,2)`, cantidades `DECIMAL(10,3)`, porcentajes `TINYINT UNSIGNED` 0-100.
- `DATETIME` en UTC para instantes; `DATE` para fechas sin hora. `TIME` solo en `space_hours`, y
  con prefijo `local_` porque es hora local del estudio, no UTC.
- Toda tabla tiene `id`, `created_at` y, si es mutable, `updated_at`. Toda entidad editable tiene
  `version INT UNSIGNED NOT NULL DEFAULT 1` para concurrencia optimista.
- `created_by` se usa cuando proceda; en documentos money (`payments`, `refunds`) el campo
  obligatorio es `registered_by`, porque el registrador es un dato contable, no de creación.
- `ON DELETE RESTRICT` en todo lo comercial. `CASCADE` solo en artefactos de autenticación
  (`api_tokens`, `password_resets`), donde la fila hija no tiene valor histórico propio.
- Borrado lógico (`active = 0`) en `users`, `clients`, `products`, `extras`, `spaces`.

### Obligación de la conexión (no se puede imponer desde las migraciones)

```sql
SET SESSION time_zone = '+00:00';
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
```

El servidor de este entorno arranca en modo **no estricto**. Comprobado en 10.4.32: con el
`sql_mode` por defecto, un `ENUM` inválido se guarda como `''`, una fecha imposible como
`0000-00-00` y un `VARCHAR` demasiado largo se trunca, **sin error**. Con `STRICT_ALL_TABLES`
los tres casos fallan con `1265`, `1292` y `1406`. Los `CHECK` sí se aplican en ambos modos
(`Error 4025`).

### Palabras reservadas

`settings.key` e `idempotency_requests.key` se llaman `key` porque así lo fija §14. **Deben
escribirse entre backticks** en todo el SQL del backend: `` `key` ``. El repositorio que lo olvide
recibirá `1064`, no un error silencioso, pero es una trampa frecuente.

## Tablas y propósito

| Tabla | Propósito | Notas |
|---|---|---|
| `schema_migrations` | Versiones aplicadas y su checksum | La escribe el ejecutor, no la migración |
| `roles` | Catálogo cerrado de 3 roles (D-01) | Sin `version`: no se edita desde la API |
| `users` | Cuentas de acceso | `email` único; `password_hash` nunca sale en exportaciones |
| `api_tokens` | Tokens opacos Bearer para Android (D-08) | Se guarda SHA-256, jamás el token |
| `password_resets` | Clave temporal de un solo uso (§6) | Se guarda hash + caducidad + `used_at` |
| `login_attempts` | Intentos de acceso para rate limit (§16) | Sin FK: registra correos inexistentes |
| `audit_logs` | Bitácora append-only | Sin `updated_at`; refs polimórficas informativas |
| `settings` | Configuración clave/valor | `value_json`; importes como cadena |
| `sequences` | Folios por tipo de documento | `next_value` + `last_used`, sin `MAX(id)+1` |
| `idempotency_requests` | Rejugar respuesta ante reintentos | Único por (`actor_id`,`operation`,`key`) |
| `clients` | Clientes NORMAL/BANDA | Banda exige representante y teléfono |
| `projects` | Proyecto interno/externo | Folio único; externo exige cliente e importe > 0 |
| `project_members` | Participantes del proyecto | Único (`project_id`,`user_id`) |
| `project_stages` | Etapas ordenables | `sort_order` no único: reordenar es un `UPDATE` masivo |
| `tasks` | Tarea de etapa o independiente | **Sin** `project_id`: el proyecto se deriva de la etapa |
| `task_comments` | Comentarios cronológicos | Inmutables |
| `project_charges` | Cargos adicionales (D-02) | Suman valor comercial, **nunca** crean cobro |
| `products` | Catálogo productos/servicios | `sku` único cuando existe; sin inventario |
| `extras` | Catálogo de adicionales | Precio vigente; el histórico vive en la venta |
| `product_extras` | Extras permitidos por producto | Reemplazable con `PUT` |
| `sales` | Venta ordinaria o de reserva | Folio único; RESERVA exige cliente; EMITIDA exige `issued_at` |
| `sale_items` | Partidas con snapshot | `name_snapshot`, `unit_price`, `line_total` congelados |
| `sale_item_extras` | Extras por unidad de una partida | Snapshot de nombre y precio |
| `spaces` | Espacios reservables | Tarifa vigente y bloque de 30 min configurable |
| `space_hours` | Disponibilidad semanal | Hora local; `weekday` ISO 1-7 |
| `space_occupancies` | **Única** fuente de ocupación | Reservas, mantenimiento y reuniones escriben aquí |
| `reservations` | Reserva de banda | `occupancy_id` y `sale_id` únicos y obligatorios |
| `space_blocks` | Bloqueo por mantenimiento | `occupancy_id` único |
| `meetings` | Reunión interna | `occupancy_id` opcional y único si usa espacio |
| `meeting_invitations` | Respuesta individual | Único (`meeting_id`,`invitee_id`) |
| `notifications` | Avisos leídos/no leídos | Payload JSON sin datos financieros ajenos |
| `payments` | Cobro manual sobre proyecto **o** venta | XOR, importe > 0, clave de idempotencia única |
| `refunds` | Reembolso real de un pago | No es una anulación; motivo obligatorio |
| `expenses` | Gasto del estudio | `PAGADO` exige `paid_at`; nunca altera el precio |

### Enumeraciones

| Columna | Valores |
|---|---|
| `clients.type` | `NORMAL`, `BANDA` |
| `projects.type` | `INTERNO`, `EXTERNO` |
| `projects.status` | `BORRADOR`, `PLANIFICADO`, `EN_CURSO`, `PAUSADO`, `FINALIZADO`, `CANCELADO` |
| `project_stages.status` | `PENDIENTE`, `EN_CURSO`, `COMPLETADA`, `CANCELADA` |
| `priority` (projects, tasks) | `BAJA`, `MEDIA`, `ALTA`, `URGENTE` |
| `tasks.status` | `PENDIENTE`, `EN_CURSO`, `BLOQUEADA`, `COMPLETADA`, `CANCELADA` |
| `products.type` | `PRODUCTO`, `SERVICIO` |
| `sales.type` / `status` | `ORDINARIA/RESERVA`, `BORRADOR/EMITIDA/CANCELADA` |
| `space_occupancies.kind` / `status` | `RESERVATION/MAINTENANCE/MEETING`, `CONFIRMED/IN_PROGRESS/FINISHED/CANCELLED` |
| `reservations.status` | Igual que la ocupación |
| `meetings.status` | `PROGRAMADA`, `CANCELADA`, `FINALIZADA` |
| `meeting_invitations.response` | `PENDIENTE`, `ACEPTADA`, `RECHAZADA` |
| `payments.status` / `refunds.status` | `REGISTRADO`, `ANULADO` |
| `payments.method` | `EFECTIVO`, `TRANSFERENCIA`, `TARJETA`, `OTRO` |
| `expenses.status` | `PENDIENTE`, `PAGADO` |
| `idempotency_requests.status` | `PROCESSING`, `COMPLETED`, `FAILED` |

Idioma: los estados que la especificación nombra en español se quedan en español; los de
reserva/ocupación van en inglés porque la skill `reservas-concurrencia` los fija así y el
calendario y las pruebas ya los usan. No se traducen en la presentación: la API devuelve el código.

## Invariantes garantizados en BD

Verificados ejecutando las sentencias contra `gestion_test` en 10.4.32.

| Invariante | Implementación | Prueba |
|---|---|---|
| Pago con exactamente un documento | `chk_payments_one_document`: `(project_id IS NULL) <> (sale_id IS NULL)` | Ambos nulos y ambos no nulos → `4025` |
| Pago con importe > 0 | `chk_payments_amount` | `0` y `-5` → `4025` |
| Reembolso > 0 | `chk_refunds_amount` | `-1` → `4025` |
| Gasto > 0 | `chk_expenses_amount` | `0` → `4025` |
| Completar fija 100 % | `chk_tasks_completed_progress` | `COMPLETADA` con 50 → `4025` |
| Progreso 0-100 | `TINYINT UNSIGNED` + `chk_tasks_progress` | 101 → `4025` |
| Obligatoriedad binaria de tarea | `is_required TINYINT(1) NOT NULL DEFAULT 1` + `chk_tasks_is_required` | Solo 0/1; todas las existentes quedan en 1 |
| Fin > inicio con hora | `chk_space_occupancies_range`, `chk_meetings_range` | Iguales o invertidos → `4025` |
| Fin ≥ inicio en fechas | `chk_projects_*_dates`, `chk_project_stages_dates`, `chk_tasks_*_dates` | Invertidas → `4025` |
| Cantidades > 0 | `chk_sale_items_quantity`, `chk_sale_item_extras_quantity`, `chk_project_charges_quantity` | 0 → `4025` |
| Precios/importes ≥ 0 | `chk_*_price`, `chk_project_charges_*`, `chk_sales_total`, `chk_sale_items_line_total` | Negativo → `4025` |
| Tarea sin etapa es independiente y jamás contradice al proyecto | No existe `tasks.project_id`; `stage_id` es la única vía | Imposible por diseño |
| Venta de reserva con cliente | `chk_sales_reservation_client` | RESERVA sin cliente → `4025` |
| Venta emitida con fecha de emisión | `chk_sales_issued_at` | EMITIDA sin `issued_at` → `4025` |
| Proyecto externo con cliente e importe | `chk_projects_external_document` | Sin cliente o importe 0 → `4025` |
| Banda con representante y teléfono | `chk_clients_band_data` | Sin ellos → `4025` |
| Gasto pagado con fecha de pago | `chk_expenses_paid_at` | `PAGADO` sin `paid_at` → `4025` |
| Pago anulado con motivo y responsable | `chk_payments_void_data` | Anulado incompleto → `4025` |
| Invitación respondida con fecha | `chk_meeting_invitations_responded` | `ACEPTADA` sin `responded_at` → `4025` |
| Documento de dinero con folio | `UNIQUE` + `NOT NULL` en `projects/sales/payments/refunds/expenses.folio` | Duplicado → `1062`; ausente → `1364` |
| Una reserva = una ocupación = una venta | `UNIQUE(reservations.occupancy_id)`, `UNIQUE(reservations.sale_id)` | Duplicado → `1062` |
| Un bloqueo/reunión por ocupación | `UNIQUE(space_blocks.occupancy_id)`, `UNIQUE(meetings.occupancy_id)` | Duplicado → `1062` |
| Doble invitación al mismo usuario | `UNIQUE(meeting_id, invitee_id)` | `1062` |
| No duplicar el intervalo exacto de una ocupación bloqueante | Columna generada `blocking_slot` + `UNIQUE` | Repetir `10:00-11:00` → `1062`; tras cancelar se libera |
| Horarios adyacentes permitidos | Intervalos semiabiertos, sin índice sobre bordes | `10-11` y `11-12` conviven |
| No borrar historia comercial | `ON DELETE RESTRICT` | Borrar cliente o usuario con proyecto → `1451` |
| Folio por secuencia | `sequences` + `UPDATE ... LAST_INSERT_ID(next_value)` | Primer folio `1`, sin huecos, reversible con rollback |
| Un solo ajuste por clave de idempotencia | `UNIQUE(payments.idempotency_key)`, `UNIQUE(refunds.idempotency_key)` | Repetir clave → `1062` |

## Lo que la base NO puede garantizar (queda en servicio)

Estas reglas **no** son un `CHECK`; el servicio es la única defensa y deben tener prueba propia.

| Regla | Por qué no cabe en la BD | Qué debe hacer el servicio |
|---|---|---|
| Solapamiento real de ocupaciones del mismo espacio | MariaDB no tiene *exclusion constraints*; el solapamiento es una comparación entre filas | Transacción + `SELECT ... FOR UPDATE` sobre `spaces` (orden de `id`), consultar `space_occupancies` con `start_at < :fin AND end_at > :inicio` y estados bloqueantes |
| Sobrepago y saldo | Suma multi-fila sobre `payments`/`refunds` | Bloquear documento, recalcular neto y rechazar exceso dentro de la transacción |
| Reembolso mayor a lo no reembolsado | Ídem | Sumar reembolsos vigentes del pago antes de insertar |
| Editar un importe por debajo de lo cobrado | Comparación con otras tablas | Rechazar sin ajuste y reembolso administrativo (§8) |
| Último administrador activo | Requiere contar filas | Rechazar desactivación/rol si quedaría 0 administradores |
| Cliente BANDA en `reservations.band_client_id` | FK no puede leer `clients.type` | Validar `type = 'BANDA'` antes de crear ocupación y venta |
| Fechas de etapas dentro del proyecto y de tareas dentro de la etapa | Comparación entre tablas | Validar al crear/editar con las fechas del padre |
| `line_total` = fórmula del §10 | Depende de las filas de extras y del redondeo por partida | Calcular en backend con BCMath; el navegador nunca envía importes |
| Venta de reserva generada junto a la reserva | Coherencia transaccional entre 3 tablas | Crear venta, ocupación y reserva en una transacción |
| Estados y transiciones permitidas | Un `CHECK` no conoce el estado previo | Tabla de transiciones en el servicio; devolver `409`/`422` |
| Finalización con obligatorias completas o excepción administrativa | Requiere leer tareas y rol del actor | Bloquear proyecto, consultar obligatorias y exigir motivo de excepción ADMIN no vacío; guardar auditoría en la misma transacción |
| Asignado activo, colaborador y miembro del proyecto | Requiere leer cuenta, rol y membresía | Validar bajo bloqueo del proyecto; tareas independientes admiten cualquier rol activo (§8) |
| Permisos por rol y por objeto | Vive en PHP | Políticas + pruebas de autorización |
| Contenido de `audit_logs` sin secretos | Semántica del JSON | Serializar en lista blanca; nunca `password_hash` ni tokens |
| Que el total de la venta cubra el total de la reserva | Comparación entre documentos | Validar al emitir y al reprogramar |
| Cifrado en reposo y respaldos | Fuera del motor | Disco cifrado del hosting + respaldo cifrado (§18) |

Riesgo residual del solapamiento: la BD solo bloquea el intervalo **exactamente** duplicado
(`blocking_slot`). Un bug del servicio puede generar solapamientos parciales (`10:00-11:30` sobre
`10:00-11:00`). Mitigación: prueba de concurrencia con dos conexiones reales (criterio E del §19)
antes de cerrar la fase 4, y revisión periódica con esta consulta:

```sql
SELECT a.id, b.id, a.space_id, a.start_at, a.end_at, b.start_at, b.end_at
  FROM space_occupancies a
  JOIN space_occupancies b
    ON b.space_id = a.space_id AND b.id > a.id
   AND b.start_at < a.end_at AND b.end_at > a.start_at
 WHERE a.status IN ('CONFIRMED','IN_PROGRESS')
   AND b.status IN ('CONFIRMED','IN_PROGRESS');
```

## Folios

`sequences(name, prefix, next_value, last_used)` con una fila por tipo: `project` (`PRY`), `sale`
(`VTA`), `payment` (`PAG`), `refund` (`REE`), `expense` (`GAS`). Consumo atómico:

```sql
UPDATE sequences SET last_used = LAST_INSERT_ID(next_value), next_value = next_value + 1
 WHERE name = 'sale';
SELECT LAST_INSERT_ID();
```

MariaDB evalúa de izquierda a derecha, así que `last_used` guarda el valor anterior: el primer
folio es `000001`. Formato `PREFIJO-AAAA-000000`; el año sale de la fecha UTC del documento y el
contador **no** se reinicia cada año (sin carreras en el cambio de año). El folio se genera dentro
de la misma transacción que el documento: si hay rollback, el número se pierde y se acepta el
hueco antes que un duplicado.

## Índices y su justificación

Todo índice no automático responde a una consulta del proyecto. Los `fk_*` los crea MariaDB donde
la FK no tenía índice propio; existen para validar `RESTRICT` al borrar, no para `SELECT`.

| Índice | Consulta que lo justifica |
|---|---|
| `projects(leader_id, status)` | §14. Proyectos que lidero, filtrados por estado (`GET /projects` del líder) |
| `projects(client_id, status)` | Ficha de cliente: sus proyectos por estado (§7) |
| `projects(status, planned_end)` | Proyectos activos por vencimiento; hitos de agenda (§13) |
| `projects(type, status)` | Dashboard: importe comercial de externos/cobrables (§11) |
| `projects(folio) UNIQUE` | Búsqueda por folio en tickets y exportación (§9, §10) |
| `project_members(user_id, project_id)` | «Mis proyectos» sin recorrer toda la tabla (§5, §15) |
| `project_stages(project_id, sort_order)` | Gantt y listado ordenado de etapas (§9) |
| `tasks(assignee_id, status, end_date)` | §14. Mis tareas, vencidas y próximas (§17 Inicio) |
| `tasks(stage_id, status)` | Avance de la etapa: tareas no canceladas (§8) |
| `tasks(status, end_date)` | Agenda por rango e hitos de proyecto (§13) |
| `task_comments(task_id, created_at)` | Hilo cronológico de la tarea (§15) |
| `project_charges(project_id, charge_date)` | Valor comercial del proyecto = base + cargos vigentes (D-02) |
| `products(active, name)` / `extras(active, name)` | Selector de catálogo activo al armar una venta (§17) |
| `product_extras(product_id, extra_id) UNIQUE` | Extras permitidos de un producto (§10) |
| `product_extras(extra_id, product_id)` | Impacto de desactivar un extra en el catálogo |
| `sales(responsible_id, issued_at)` | §14. Ventas propias por fecha (`GET /sales`) |
| `sales(client_id, issued_at)` | Estado de cuenta del cliente (§11 receivables) |
| `sales(status, issued_at)` | Dashboard: ventas emitidas en un periodo (§11) |
| `sales(type, issued_at)` | Dashboard: separar venta ordinaria y reserva (§11) |
| `sale_items(sale_id, sort_order)` | Ticket y recálculo del total en orden estable (§10) |
| `sale_items(product_id, created_at)` | Histórico por producto (consulta de catálogo) |
| `sale_item_extras(sale_item_id)` | Desglose de extras de la partida en ticket (§10) |
| `spaces(active, name)` | Selector de espacios reservables (§15) |
| `space_hours(space_id, weekday)` | Disponibilidad de un día (`GET /spaces/{id}/availability`) |
| `space_occupancies(space_id, status, start_at, end_at)` | §14. Consulta de conflicto y calendario de espacios (§12) |
| `space_occupancies(start_at, end_at)` | Agenda por rango visible de todas las ocupaciones (§13) |
| `space_occupancies(blocking_slot) UNIQUE` | Defensa contra duplicado exacto de ocupación |
| `reservations(band_client_id, status)` | Reservas de una banda para su ficha y ticket (§10, §12) |
| `reservations(responsible_id, status)` | Reservas a cargo del usuario en su agenda (§13) |
| `reservations(status, created_at)` | Listado operativo de reservas (`GET /reservations`) |
| `space_blocks(created_at)` | Bloqueos recientes (`GET /space-blocks`) |
| `meetings(organizer_id, start_at)` | Reuniones que organizo (§13) |
| `meetings(start_at, end_at)` | Agenda por rango visible (§15 `GET /agenda`) |
| `meeting_invitations(invitee_id, response)` | §14. Invitaciones pendientes/aceptadas de un usuario |
| `notifications(user_id, read_at, created_at)` | §14. Bandeja y contador de no leídas (§13) |
| `notifications(entity_type, entity_id)` | Marcar leídas al cancelarse el evento origen |
| `payments(effective_at, status)` | §14. Cobros reales netos por periodo (§11 dashboard) |
| `payments(project_id)` / `payments(sale_id)` | §14. Saldo de un documento (suma de pagos vigentes) |
| `payments(registered_by, effective_at)` | Pagos registrados por usuario (`GET /finance/transactions`) |
| `payments(idempotency_key) UNIQUE` | §11. Detectar reintento de la misma petición |
| `refunds(payment_id, effective_at)` | Neto reembolsado de un pago y saldo reembolsable (§11) |
| `refunds(effective_at, status)` | Reembolsos del periodo en el dashboard (§11) |
| `expenses(expense_date, status)` | Gastos pagados/pendientes del periodo (§11) |
| `expenses(project_id, expense_date)` | Gastos internos por proyecto (nunca visibles al cliente, §9) |
| `expenses(responsible_id, status)` | Gastos a cargo de un usuario (§8) |
| `audit_logs(entity_type, entity_id, created_at)` | Historial de un documento concreto (§16) |
| `audit_logs(actor_id, created_at)` | Auditoría por usuario (`GET /audit-logs`) |
| `audit_logs(action, created_at)` | Filtro de acciones sensibles (anulaciones, roles) |
| `audit_logs(request_id)` | Depurar una petición HTTP concreta |
| `users(role_id, active)` | Listado de usuarios por rol (`GET /users`) |
| `users(active, name)` | Directorio mínimo para asignaciones e invitaciones (§5) |
| `clients(name)` / `clients(phone)` / `clients(email)` | Advertencia de duplicados (§7) |
| `clients(type, active)` | Bandas activas para el flujo de reservas (§12) |
| `login_attempts(email, created_at)` / `(ip_address, created_at)` | Rate limit de login (§16) |
| `api_tokens(user_id, revoked_at, expires_at)` | Validación del Bearer en cada petición móvil (D-08) |
| `idempotency_requests(actor_id, operation, key) UNIQUE` | Replay de la respuesta original (§15) |
| `idempotency_requests(created_at)` | Purga por cron de claves antiguas |

No se indexan columnas de baja cardinalidad por sí solas (`active`, `status`) ni `description`,
`notes` o `body`: no hay consulta que las use como filtro principal y solo encarecerían la
escritura.

## Decisiones de diseño no obvias

1. **`tasks` no tiene `project_id`.** El proyecto se deriva de `stage_id`. Así la base impide por
   construcción una tarea con etapa de otro proyecto o con proyecto y sin etapa (§8). El precio es
   que listar «tareas de un proyecto» exige `JOIN project_stages`.
2. **No hay estado de borrador en `reservations`.** §12 menciona «borradores que no bloquean»; se
   interpreta como una venta en `BORRADOR` sin ocupación. La reserva nace `CONFIRMED` y solo
   entonces ocupa espacio, porque `occupancy_id` y `sale_id` son obligatorios.
3. **`space_occupancies.blocking_slot`** es una columna generada `PERSISTENT` con
   `space_id|start_at|end_at` solo para estados bloqueantes. Es una defensa parcial contra
   duplicados exactos, no contra solapamientos; se eligió `PERSISTENT` para poder indexarla en
   10.4 y `NULL` para que una ocupación cancelada libere el hueco.
4. **`project_charges.status` es `ACTIVO/ANULADO`.** «Cobrado/pendiente» no se almacena: se deriva
   de `payments` (invariante 7 de `spec-gestion`). Un cargo nunca crea un pago (D-02).
5. **`refunds` y `expenses` llevan folio** aunque §14 no lo pida. Son documentos de dinero y el
   resto de documentos financieros lo tienen; sin folio, la trazabilidad del §11 y la auditoría
   dependen del `id`. El folio se genera con las secuencias `REE` y `GAS`.
6. **`payments.idempotency_key` es única en toda la tabla**, no por usuario, tal como pide §14. Si
   dos usuarios usaran la misma clave (improbable con UUID), el segundo recibe conflicto; se
   documenta como comportamiento aceptado antes que duplicar cobros.
7. **`clients` exige representante y teléfono para `BANDA`** (§7). Es más estricto que la API, que
   podría aceptarlo; se prefiere que el dato faltante falle al guardar y no al imprimir el ticket.
8. **`projects` externo exige `base_amount > 0`** (§2.2: «externo exige cliente y precio»). Un
   interno puede tener importe 0. `billable` queda independiente del tipo, como manda §2.2.
9. **`space_hours` no admite cruzar medianoche** (`TIME` no llega a 24:00 y el `CHECK` exige
   `fin > inicio`). Un horario nocturno se modela con dos filas (hasta 23:59:59 y desde 00:00).
10. **`project_stages.sort_order` no es único.** Reordenar con un `UNIQUE(project_id, sort_order)`
    obligaría a pasar por valores temporales y a un `UPDATE` en dos fases; se prefiere índice no
    único y el servicio normaliza el orden (0..n-1) en una transacción.
11. **Sin `version` en tablas de relación** (`project_members`, `product_extras`,
    `task_comments`, `notifications`). No tienen `PATCH` de campos: se crean, se borran o se
    cambia un único campo con `UPDATE` idempotente. `meeting_invitations` sí lleva `version`
    porque su `response` se edita y un cambio de horario la devuelve a `PENDIENTE`.
12. **`CASCADE` solo en `api_tokens` y `password_resets`.** El resto es `RESTRICT`, incluidos los
    comentarios: un usuario o un proyecto con historia no se borra nunca.
13. **`space_occupancies.notes` es `VARCHAR(255)` y no debe contener importes**: el calendario de
    espacios se muestra a todos los usuarios con datos mínimos (§12, §13).
14. **`audit_logs` y `notifications` usan referencias polimórficas sin FK** (§14). No sustituyen a
    las FK financieras: `payments`, `refunds`, `reservations` y `expenses` siempre apuntan por FK.

## Riesgos conocidos (verificados en MariaDB 10.4.32)

- **Modo no estricto por defecto.** Sin `STRICT_ALL_TABLES`, los `ENUM`, `DATE` y `VARCHAR` se
  truncan en silencio (probado: `type='PEDIDO'` → `''`, `'2026-02-30'` → `0000-00-00`,
  `VARCHAR(160)` recortado). Los `CHECK` sí se aplican igual en ambos modos. **Acción:** el
  backend fija `sql_mode` y `time_zone` en cada conexión (ver README de migraciones).
- **`INSERT IGNORE` no evita los `CHECK` en 10.4.32** (probado: `4025`). No se debe usar `IGNORE`
  como técnica de idempotencia; para eso está `idempotency_requests` y `ON DUPLICATE KEY UPDATE`.
- **Los `CHECK` sí validan filas existentes al añadirse**: `ALTER TABLE ... ADD CONSTRAINT CHECK`
  falla si hay datos que lo incumplen. Por eso cualquier restricción nueva exige limpiar datos
  antes de la migración.
- **Sin *exclusion constraints*:** el solapamiento de intervalos es 100 % responsabilidad del
  servicio y de sus pruebas de concurrencia.
- **`JSON` en MariaDB es `LONGTEXT` con `CHECK json_valid`**; no hay indexación funcional de JSON.
  Si el dashboard necesita filtrar por un campo del payload, debe promoverse a columna.
- **Sin `CHECK` sobre subconsultas:** nada que compare dos tablas puede expresarse aquí. Todo lo
  de la tabla «Lo que la base NO puede garantizar» necesita prueba de integración.
- **DDL no transaccional:** si una migración falla a mitad, queda a medias. Se aplica con respaldo
  previo y el ejecutor registra la versión solo al terminar.

## Fase 2: contrato y revisión previa a repositorios (§8, §14, §19.B)

### Migración 007 y finalización

- Única columna nueva: `tasks.is_required TINYINT(1) NOT NULL DEFAULT 1`, dominio `0/1`.
  La API debe tratarla como booleano; en PDO puede llegar como entero/cadena. No cambia
  `version`, FK ni la derivación del proyecto por `stage_id`.
- **Propuesta conservadora:** todas las tareas, incluidas las existentes, son obligatorias
  salvo indicación explícita del líder/administrador al definir alcance. §8 no fija el valor
  predeterminado. Responsable de integración: registrar esta propuesta en `docs/decisiones.md`.
- Una tarea obligatoria no `COMPLETADA` bloquea finalizar, incluida `CANCELADA`; no se presume
  que cancelar equivalga a terminar. Puede hacerse opcional mediante cambio de alcance
  autorizado o usarse excepción administrativa con motivo. Confirmar esta interpretación
  conservadora antes de introducir una política distinta.
- `is_required` no filtra el avance: todas las tareas no canceladas pesan igual; sin tareas,
  avance 0 y «sin tareas». Para independientes el flag no participa en ninguna finalización.
- Historial existente suficiente: `audit_logs` con `actor_id`, `action`, `entity_type`,
  `entity_id`, `before_json`, `after_json`, `request_id`, `created_at`. Finalizar con excepción
  debe registrar estado anterior/nuevo y motivo no vacío en JSON de lista blanca. Reabrir
  tareas conserva los comentarios y registra estado/progreso anterior/nuevo en la misma
  transacción; no se necesita otra tabla. No hay garantía declarativa de esta auditoría.

### Consultas e índices existentes (sin índices nuevos)

```sql
-- Mis proyectos: combinar IDs de ambas ramas sin duplicados y luego unir projects.
SELECT id FROM projects WHERE leader_id = :actor_leader
UNION
SELECT project_id FROM project_members WHERE user_id = :actor_member;
-- idx_projects_leader_status; idx_project_members_user(user_id,project_id).
-- Una rama separada por asignación, si la política la requiere, puede derivarse de tasks.

SELECT * FROM tasks
 WHERE assignee_id = :actor AND status = :status
 ORDER BY end_date, id LIMIT 25;
-- idx_tasks_assignee_status_end; el id es sufijo implícito InnoDB.
-- Sin igualdad por status, ordenar por end_date puede requerir filesort: medir, no
-- crear otro índice sin consulta/dataset real que lo justifique.

SELECT t.id FROM project_stages s JOIN tasks t ON t.stage_id = s.id
 WHERE s.project_id = :project AND t.is_required = 1
   AND t.status <> 'COMPLETADA' LIMIT 1;
-- idx_project_stages_project(project_id,sort_order) + idx_tasks_stage_status.
-- is_required residual; no índice booleano independiente.

SELECT user_id FROM project_members WHERE project_id = :project;
-- uq_project_members(project_id,user_id): listado y comprobación de membresía.

SELECT COALESCE(SUM(amount), 0.00) FROM project_charges
 WHERE project_id = :project AND status = 'ACTIVO';
-- idx_project_charges_project(project_id,charge_date); status residual.
```

No contar filas de tareas mediante joins con miembros/cargos: multiplicaría resultados.
El valor comercial es `base_amount + SUM(cargos ACTIVO.amount)`, exacto y sin gastos;
`amount` se calcula con aritmética decimal como redondeo a dos decimales de
`quantity * unit_price`, una vez por cargo. La BD garantiza dominios, **no** esa igualdad.
Dinero por JSON como string. Un cargo nunca crea pago; un interno no cobrable no genera
cuenta por cobrar aunque tenga base/cargos. Si se reduce valor por debajo del neto cobrado,
rechazar sin ajuste/reembolso administrativo (§8); no sobrescribir el historial.

### Orden de bloqueos obligatorio para servicios

1. Comenzar transacción y bloquear `projects` por PK (`SELECT ... WHERE id = ? FOR UPDATE`)
   **antes** de tocar etapas, tareas, miembros o cargos. Si hay varios proyectos, `id` ascendente.
2. Para una tarea, lectura preliminar de `stage_id`/proyecto solo para localizar el padre;
   después del bloqueo releer y comprobar pertenencia. Bloquear las filas hijas necesarias por
   PK en orden ascendente, siempre en orden estable etapas → tareas → miembros → cargos.
   Evitar mover tareas entre proyectos sin bloquear ambos padres y volver a validar.
3. Todas las rutas de escritura hijas (también progreso/comentarios/cambio de membresías)
   siguen el mismo protocolo. Así finalizar y crear/modificar tareas no se intercalan; el
   bloqueo del padre protege también la ausencia de hijos. Usar lectura actual de las tareas
   al decidir finalización (`SELECT ... FOR UPDATE`), no una instantánea anterior.
4. Verificar `version` esperada y actualizar `version = version + 1` en cada entidad mutable.
   En membresías, que carecen de versión propia, usar la versión del proyecto y aumentarla.
   No obliga a incrementar la versión del proyecto por cada comentario/progreso de tarea.
5. Validar fechas contra padre, permisos, rol activo y membresía bajo el bloqueo; coordinar
   desactivaciones/cambios de rol con el servicio de cuentas (lectura bloqueante de usuario
   antes de aceptar asignación). No retirar miembros dejando asignaciones inválidas: rechazar
   o exigir reasignación explícita. No agregar una FK redundante de tarea a membresía.
6. Auditoría y escritura pertenecen a la misma transacción. Dinero/pagos futuros deben usar
   el mismo bloqueo de documento antes de recalcular neto; reintentar solo deadlocks en helper
   central. Para independientes, sin padre, bloquear tarea por PK y su versión.

### Verificación de 007

Verificado el 2026-10-28 en MariaDB **10.4.32**:

- Aplicada por `bin/migrate.php` en `gestion` y `gestion_test`; segunda ejecución:
  «Sin migraciones pendientes», sin diferencias de checksum.
- `SHOW COLUMNS` en ambas bases: `tinyint(1)`, `Null=NO`, `Default=1`.
  `information_schema.CHECK_CONSTRAINTS` confirma `chk_tasks_is_required`.
- Aplicación de 007 a copia temporal de `tasks` sin la columna: filas con omisión/0/1
  aceptadas con valores 1/0/1; 2 y -1 rechazados con **4025**, NULL con **1048**.
- DOWN manual en copia temporal conserva las tres filas y elimina la columna/restricción;
  reaplicar vuelve a marcar las tres como obligatorias. La reversión pierde la clasificación
  opcional, por eso exige respaldo. No se revirtió `gestion`.
- Verificación de integración posterior: instalación **completa 001–007 desde cero** en
  `gestion_phase2_migration_test`, base desechable provisionada exclusivamente para esta prueba.
  `bin/migrate.php` aplicó las siete migraciones y `bin/seed.php` los tres seeds sin errores.
  Resultado: 34 tablas, todas InnoDB, siete checksums registrados y `is_required` con default 1.
  Se eliminaron la base desechable y sus privilegios al terminar; no se reinició `gestion`
  ni `gestion_test`. La aplicación continuó conectándose con su usuario propio, nunca root.

## Fase 3: revisión previa de ventas y cobros (§10, §11, §14)

### Migración 008: contrato DDL y conservación

`008_sales_cancelled_draft.sql` sustituye únicamente `sales.chk_sales_issued_at` por:

```sql
CHECK (status <> 'EMITIDA' OR issued_at IS NOT NULL)
```

No añade columnas, tablas, índices, estados ni políticas. `sales.issued_at` sigue siendo
`DATETIME NULL` UTC: al emitir es obligatorio; al cancelar un borrador queda NULL; al cancelar
una venta emitida se conserva la emisión histórica. El servicio controla la transición y no
borra ni inventa fechas. La restricción anterior de 003 también exigía fecha a CANCELADA y
por tanto impedía cancelar un borrador honestamente. No se modifica 003 ya aplicada.

DOWN restaura el CHECK anterior, **solo si no existen CANCELADA con issued_at NULL**. El bloque
documentado incluye esa consulta previa; no se deben borrar filas ni fabricar emisiones para
revertir. DDL tiene commit implícito: respaldo previo y mantenimiento sin escrituras concurrentes.

El esquema existente basta para cancelaciones y reembolsos: `audit_logs` registra actor,
acción, entidad, request_id, antes/después y motivo mediante JSON de lista blanca, en la misma
transacción. `payments` ya tiene `voided_at`, `voided_by`, `void_reason`; `refunds` tiene
`reason`, registrador y metadatos de anulación. No se duplican esos datos en nuevas tablas o
columnas de cancelación de venta. Exigir motivo **no vacío** y auditoría, autorización ADMIN,
transiciones y resolución financiera corresponde al servicio: el CHECK de anulación de pagos
solo exige no NULL y no asegura contenido significativo; refunds no tiene CHECK equivalente.

### Idempotencia: compatibilidad sin cambio de columna

Propuesta para integrar en backend y decisiones: mantener `payments.idempotency_key` y
`refunds.idempotency_key` como `VARCHAR(100) NOT NULL UNIQUE`, pero guardar un SHA-256 hexadecimal
de 64 caracteres, no la clave HTTP cruda. Preimagen inequívoca: array JSON ordenado de cuatro
cadenas `[actor_id decimal, operation, canonical_path, raw_key]`, UTF-8 sin escapar Unicode ni
barras. `operation` es el identificador estable de la acción (incluido su método semántico);
`canonical_path` incluye el ID concreto del recurso y excluye host/query string. No concatenar
campos sin delimitación inequívoca. Misma petición produce el mismo hash; dos actores con la
misma clave no colisionan. Los valores históricos no se reescriben.

Esto sustituye **para las nuevas escrituras de fase 3** el comportamiento de colisión entre
usuarios descrito en la decisión de diseño 6 de fase 1. `idempotency_requests` conserva su único
`(actor_id, operation, key)` y la clave HTTP original: no cambia el contrato público. El hash
del contenido para replay debe incluir método y ruta canónica además del cuerpo normalizado;
la misma clave en otra ruta, dentro de igual actor/operación, debe dar conflicto, no devolver
la respuesta de otro recurso. La operación/hash no se usa como autorización. No usar
`INSERT IGNORE`: reservar, comprobar contenido y guardar respuesta con la escritura financiera
en la misma transacción, como en el protocolo existente de proyectos.

### Folios y bloqueos

- Ventas, pagos y reembolsos usan los nombres globales del seed `sale`, `payment`, `refund`
  y prefijos `VTA`, `PAG`, `REE`, sin reinicio anual. Año UTC del documento + contador global;
  mantener longitud máxima de `folio VARCHAR(24)` y rechazar desbordamientos, no truncar.
- Bloquear/actualizar la fila de `sequences` en la misma transacción del documento y obtener
  el número reservado con `LAST_INSERT_ID(next_value)` o lectura bloqueante seguida de UPDATE.
  Nunca `MAX(id)+1`, nunca resetear una secuencia usada. Verificar que existe la fila.
- **Aclaración sobre rollback:** `sequences` es InnoDB: rollback revierte su incremento junto
  con el documento. No se garantiza ausencia de huecos (p. ej. AUTO_INCREMENT); no es cierto
  que ese incremento transaccional se pierda necesariamente como indicaba la explicación
  inicial de Folios. La identidad de un documento confirmado nunca se reutiliza.
- Proyectos conservan el contrato anual ya implementado `project.AAAA` de D-17; no se cambia
  ni se aplica silenciosamente a ventas/cobros. Esta diferencia debe constar en decisiones.
- Orden financiero: documento (`sales` o `projects`) por PK **antes** de sus payments, después
  refunds; cada conjunto por ID ascendente. Para localizar documento desde pago/reembolso,
  la primera lectura solo localiza; tras bloquear el padre releer/verificar pertenencia.
  Cobrar, reembolsar, anular, modificar total y cancelar siguen este mismo orden.
- Leer pagos/reembolsos actuales con `SELECT ... FOR UPDATE` después de adquirir el padre;
  no recalcular usando una instantánea REPEATABLE READ abierta antes de esperar ese bloqueo.
  El padre serializa también el caso de ausencia de pagos. Validar versión esperada y aumentar
  `version` en la entidad modificada; auditoría en la misma transacción, reintento central de
  deadlocks. Adquirir secuencias después de las filas financieras, en orden estable por nombre
  si una operación reserva varios tipos.
- En fase 4, cualquier cancelación/reprogramación de reserva toma primero `spaces` por ID,
  luego documento y filas financieras. No introducir una ruta inversa documento → espacio.
  Una venta RESERVA no puede cancelarse aisladamente liberando solo su estado financiero.

Sobrepago, reembolso excesivo, anular pago ya reembolsado y cancelar con dinero sin resolver
son reglas entre filas: siguen en servicio, no quedan garantizadas por 008. Las operaciones
administrativas deben resolverlas explícitamente, nunca borrar historia ni convertir anulación
en devolución real. El estado de pago es calculado; total cero = SIN_CARGO sin pago ficticio.

### Saldos e índices existentes

No se añaden índices sin una consulta real que demuestre necesidad. Las rutas de esta fase ya
tienen cobertura por responsable/fecha y estado/fecha en sales; payments por sale_id/project_id;
refunds por payment_id/fecha; lectura de partidas por sale_id/orden y extras por sale_item_id.
Las restricciones únicas de folio/idempotencia y los índices FK existentes no cambian.

Consulta de lectura de neto de una venta, sin multiplicar el pago por cada reembolso:

```sql
SELECT COALESCE(SUM(p.amount - COALESCE((
  SELECT SUM(r.amount) FROM refunds r
   WHERE r.payment_id = p.id AND r.status = 'REGISTRADO'
), 0.00)), 0.00) AS net_collected
FROM payments p
WHERE p.sale_id = :sale AND p.status = 'REGISTRADO';
-- idx_payments_sale + idx_refunds_payment(payment_id,effective_at).
-- Para proyecto: reemplazar únicamente sale_id por project_id.
```

Para corte temporal, filtrar `effective_at` en **ambas** ramas; para flujos del periodo sumar
pagos y reembolsos por separado con sus propias fechas efectivas. No sumar payments.amount en
un JOIN directo 1:N a refunds. Para varias ventas, preagregar cada relación a una fila por
padre antes de unir; tampoco unir simultáneamente partidas, extras y pagos para sumar totales.
La consulta anterior es para lectura: no sustituye el protocolo bloqueante de escritura.

### Verificación de 008 (2026-10-28)

MariaDB real **10.4.32**, cliente de aplicación `gestion`:

- `bin/migrate.php --database=gestion` y `--database=gestion_test`: **1 migración aplicada**
  en cada base. Segunda pasada: **Sin migraciones pendientes** en ambas; ocho versiones y
  CHECK nuevo confirmado en information_schema. Ninguna tabla truncada ni datos de desarrollo
  reiniciados; sales tenía 0 filas antes y después en ambas bases.
- Instalación **001–008 desde cero** por el mismo ejecutor en base dedicada desechable
  `gestion_phase3_migration_test`: ocho migraciones, **34 tablas**, **0 no-InnoDB**,
  **0 con collation distinta de utf8mb4_unicode_ci**. Provisionamiento/limpieza mediante
  cuenta local administrativa; migraciones y verificación con el usuario de la aplicación.
- En esa base, restaurado el CHECK antiguo y creadas tres ventas históricas de prueba
  (BORRADOR NULL, EMITIDA con fecha, CANCELADA con fecha). Aplicar 008 conserva las tres:
  `CHECKSUM TABLE sales EXTENDED` **3242122229 → 3242122229**. DOWN en estado compatible
  conserva también checksum **3242122229**; reaplicación correcta.
- Cambiar el borrador a CANCELADA conservando NULL: **aceptado, 1 fila**. INSERT de EMITIDA
  NULL y UPDATE a EMITIDA NULL: ambos **ERROR 4025 (23000)**, salida de cliente 1.
- DOWN con el borrador ya cancelado NULL: **ERROR 4025**, no pierde filas ni el CHECK nuevo;
  siguen tres ventas. La reversión condicionada está probada, no se forzó sobre datos válidos.
- Un pago de **120.00** con reembolsos de **20.00** y **30.00**: subconsulta devuelve neto
  **70.00**; un JOIN directo multiplica erróneamente el pago a **240.00** (dos filas).
- Base dedicada y sus privilegios retirados al terminar. No quedan fixtures en gestion ni
  gestion_test. No se ejecutaron ni modificaron pruebas PHP: la integración funcional y de
  concurrencia de fase 3 corresponde al backend/QA antes de cerrar la fase.

## Fase 4: revisión previa de espacios y reservas (§12, §13, §14)

### Resultado y contrato existente (sin migración 009)

004 + 003 + 006, con la corrección de 008, son suficientes. **No se crea 009 vacía**, ni se
modifican migraciones aplicadas. No se añaden estados, índices, tarifas por minuto ni columnas
duplicadas de espacio/fechas en `reservations`. Esta revisión no implementa backend ni pruebas.

Contrato para repositorios (además de los metadatos comunes `id`, `version`, `created_at`,
`updated_at`; PK/FK `BIGINT UNSIGNED`, versión `INT UNSIGNED NOT NULL DEFAULT 1`):

| Tabla | Columnas de negocio y nullabilidad |
|---|---|
| `spaces` | `name VARCHAR(120) NOT NULL UNIQUE`, `description TEXT NULL`, `hourly_rate DECIMAL(12,2) NOT NULL DEFAULT 0.00`, `block_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30`, `active TINYINT(1) NOT NULL DEFAULT 1`, `created_by BIGINT UNSIGNED NULL` |
| `space_hours` | `space_id NOT NULL`, `weekday TINYINT UNSIGNED NOT NULL` (ISO 1–7), `local_start_time TIME NOT NULL`, `local_end_time TIME NOT NULL`; único `(space_id,weekday,local_start_time)` |
| `space_occupancies` | `space_id NOT NULL`, `start_at/end_at DATETIME NOT NULL` UTC, `kind ENUM('RESERVATION','MAINTENANCE','MEETING') NOT NULL`, `status ENUM('CONFIRMED','IN_PROGRESS','FINISHED','CANCELLED') NOT NULL DEFAULT 'CONFIRMED'`, `notes VARCHAR(255) NULL`, `created_by BIGINT UNSIGNED NULL` |
| `reservations` | `occupancy_id`, `band_client_id`, `sale_id`, `responsible_id`: FK NOT NULL; `hourly_rate_snapshot/total DECIMAL(12,2) NOT NULL`; `status` mismo ENUM/default de ocupación; `notes TEXT NULL`, `created_by BIGINT UNSIGNED NULL` |
| `space_blocks` | `occupancy_id NOT NULL UNIQUE`, `reason VARCHAR(255) NOT NULL`, `created_by BIGINT UNSIGNED NOT NULL`; su estado/espacio/fechas se derivan de la ocupación |

`reservations.occupancy_id` y `reservations.sale_id` son **UNIQUE y NOT NULL**. No escribir
`space_occupancies.blocking_slot VARCHAR(80)`: es generada persistente, nullable y única. La BD
comprueba tarifa/total no negativos, bloque > 0 y fin > inicio. Todas las FK comerciales son
RESTRICT. El servicio todavía debe garantizar cliente BANDA activo, responsables autorizados,
coincidencia banda/cliente y responsable de venta/reserva, `kind` correcto y estados sincronizados.
Una FK/UNIQUE no impide reutilizar una ocupación entre tablas de distinto tipo: validar también
el origen; no enlazar ocupaciones arbitrarias suministradas por el cliente.

### Propuestas explícitas para integrar en decisiones y servicios

- **Sin borradores persistidos de reserva.** Crear produce siempre reserva y ocupación
  `CONFIRMED` + venta `RESERVA/EMITIDA`, aun sin pago. El formulario/previsualización y la consulta
  de disponibilidad no garantizan hueco. No introducir `BORRADOR` en el ENUM ni crear ventas de
  reserva huérfanas como borrador. Esta propuesta precisa/sustituye para fase 4 la interpretación
  de borrador como venta persistida descrita en la decisión de diseño 2 y comentario de 004.
- **Cobro exacto:** duración en minutos enteros; calcular `tarifa_snapshot * minutos / 60`
  conservando precisión y redondear half-up a dos decimales **una sola vez al final**. Nunca
  redondear previamente una tarifa por minuto ni convertir horas a `DECIMAL(10,3)` para cobrar.
  Ejemplo: `250.00 * 90 / 60 = 375.00`. Validar límites de `DECIMAL(12,2)` antes de escribir.
- **Partida automática única:** `sale_items.product_id = NULL`, `quantity = '1.000'`,
  `unit_price = line_total = reservations.total`, sin extras, `sort_order = 0`;
  `name_snapshot VARCHAR(160)` identifica el servicio de reserva/espacio y duración. La tarifa
  histórica reside en `hourly_rate_snapshot`; no inventar un producto obligatorio ni aplicar el
  permiso general de concepto libre ADMIN a este concepto generado por el servidor. La venta
  conserva folio `VTA`, moneda configurada y `issued_at` UTC. Este modelo respeta la fórmula
  de partida sin aproximar minutos a horas decimales. El pago se registra **solo por sale_id**
  (`project_id = NULL`); nunca existe un pago directo de reserva ni otro ingreso duplicado.
- **Tarifa al reprogramar, propuesta:** mismo espacio conserva `hourly_rate_snapshot`, aunque
  cambie el catálogo; cambio de espacio toma la tarifa vigente del destino bajo bloqueo y
  sustituye el snapshot. Recalcular total por duración, conservar IDs de reserva/ocupación/
  venta/partida y auditar antes/después (incluida tarifa). Actualizar también snapshot descriptivo,
  precio y total de la partida, total y versión de venta **aunque esté EMITIDA**: únicamente
  mediante el coordinador de reservas, nunca habilitando edición genérica de ventas emitidas.
  No cambiar folio ni `issued_at` histórico. `total nuevo >= neto cobrado` siempre; con cobros,
  un ajuste comercial exige ADMIN según D-15. El estado de pago se recalcula, no se almacena.
- **Cancelación:** coordinada, sin borrar filas, con reserva/ocupación `CANCELLED` y venta
  `CANCELADA`. Por §10/D-18 cancelar la venta exige ADMIN incluso sin cobros; con pagos exige
  además resolución manual previa mediante reembolsos efectivos hasta neto cero y motivo
  auditado. No emitir reembolso automático, anular un cobro para simular devolución ni inventar
  penalizaciones. Una venta RESERVA no puede cancelarse por la ruta ordinaria aislada.

### Protocolo único de bloqueos

1. BEGIN; reservar/bloquear `idempotency_requests` **antes de cualquier espacio**, con la misma
   semántica de hash/replay de fase 3. Aplicar a creación/reprogramación/cancelación y bloqueos;
   también a reuniones con espacio cuando se implementen. Replay no vuelve a crear documentos.
2. Lectura preliminar solo para localizar IDs actuales; bloquear **todos** los `spaces` afectados
   por PK ascendente (origen y destino si cambia). Después bloquear `sales` existente por PK,
   luego `reservations`, luego `space_occupancies`, cada conjunto por ID ascendente. En creación
   no hay padre financiero previo: crear venta/ocupación/reserva bajo el espacio ya bloqueado.
   Bloqueos de mantenimiento siguen espacios → space_blocks → ocupación, sin venta.
3. Releer y comprobar pertenencia/versión bajo esos locks. Si una reprogramación concurrente
   cambió el espacio respecto a la lectura preliminar, rollback y reinicio acotado del protocolo;
   **no adquirir tardíamente otro espacio fuera del orden**. Conflicto de versión → 409.
4. Si se necesita saldo, bloquear después `payments` y luego `refunds` por ID ascendente, con
   lecturas actuales `FOR UPDATE`; no usar una instantánea anterior a esperar el padre.
   Toda ruta que modifique ocupación sigue **espacios → venta → reserva/ocupación → pagos →
   reembolsos**. Las rutas puramente financieras pueden seguir venta → pagos → reembolsos si
   nunca adquieren espacios después; no llamar desde ahí a un coordinador que invierta el orden.
5. Bajo el espacio releer conflictos, ventanas y configuración. El espacio serializa también la
   ausencia de ocupaciones. Validar estado, permisos y versiones; escribir los documentos y
   auditoría en la misma transacción, incrementando las versiones modificadas. Secuencias al
   final, en orden estable por nombre. COMMIT. Reintentos de deadlock solo en helper central.

Cambios de tarifa, granularidad, horarios y desactivación también bloquean el espacio antes de
sus hijos. No invalidar reservas futuras existentes silenciosamente: rechazar el cambio hasta
resolverlas explícitamente. Reprogramar modifica la ocupación original, no crea otra fila.

### UTC, disponibilidad y medianoche

`DATETIME` de ocupación es UTC; interpretar `weekday`/`TIME` en la zona IANA del estudio
(`settings.timezone`, no la zona personal del actor). Convertir cada tramo local a UTC con las
reglas de esa fecha; no usar un desfase constante. Validar fecha/hora ambigua o inexistente.
Sin ventanas configuradas el espacio está **no disponible** (propuesta conservadora).

**Corrección de la decisión de diseño 9:** MariaDB `TIME` sí representa `24:00:00`; no usar
`23:59:59` como final de jornada, porque deja un hueco artificial. Ventana nocturna en dos filas:
lunes `[22:00:00,24:00:00)` y martes `[00:00:00,02:00:00)`. El CHECK actual permite ese extremo;
no necesita DDL. El servicio limita inicio a `[00:00,24:00)` y fin a `(00:00,24:00]`, normaliza
24:00 como medianoche del día siguiente y verifica cobertura continua de todos los tramos.
El tipo TIME por sí solo admite negativos y horas >24, y el CHECK solo compara fin/inicio:
**el dominio de reloj local y la ausencia de ventanas solapadas quedan en servicio**.

Granularidad propuesta: segundos cero, duración múltiplo de `spaces.block_minutes` y comienzo
alineado a ese bloque desde medianoche local (conversión UTC posterior). Validar las ventanas
al configurarlas, fusionar ventanas adyacentes para cobertura y rechazar solapadas; para un
cruce de día comprobar ambos días. No asumir que el CHECK de bloque >0 impone estas reglas.
No admitir reservas retrospectivas salvo ADMIN con motivo auditado, como manda §12.

### Consultas de rango e índices (sin índices nuevos)

```sql
-- Tras bloquear spaces: conflicto real; excluir la propia ocupación al reprogramar.
SELECT id FROM space_occupancies
 WHERE space_id = :space AND status IN ('CONFIRMED','IN_PROGRESS')
   AND start_at < :new_end AND end_at > :new_start AND id <> :own_occupancy
 FOR UPDATE;
-- idx_space_occupancies_conflict(space_id,status,start_at,end_at).
-- En creación omitir exclusión (no existe own_occupancy).

-- Calendario de espacios, con proyección mínima sin cliente, notas ni importes.
SELECT id, space_id, start_at, end_at, kind, status FROM space_occupancies
 WHERE start_at < :visible_end AND end_at > :visible_start
 ORDER BY start_at, id;
-- idx_space_occupancies_range(start_at,end_at).
-- Con filtro space_id y estados bloqueantes: índice de conflictos.

-- Reservas propias dentro del rango visible (no cargar todo el histórico).
SELECT r.id, o.space_id, o.start_at, o.end_at, r.status
 FROM reservations r JOIN space_occupancies o ON o.id = r.occupancy_id
 WHERE r.responsible_id = :actor
   AND o.start_at < :visible_end AND o.end_at > :visible_start
 ORDER BY o.start_at, r.id;
-- idx_reservations_responsible + PK de ocupación, o rango de ocupación
-- + uq_reservations_occupancy; el optimizador elige según cardinalidad.

SELECT weekday, local_start_time, local_end_time FROM space_hours
 WHERE space_id = :space AND weekday IN (:day1, :day2)
 ORDER BY weekday, local_start_time;
-- uq_space_hours(space_id,weekday,local_start_time) / idx_space_hours_space.
```

`end_at` suele ser filtro residual: un B-tree no resuelve simultáneamente ambos extremos del
intervalo. Ordenar agenda puede requerir filesort; no justifica otro índice con tablas vacías.
La BD solo evita duplicados **exactos** bloqueantes, no solapamientos parciales. Sigue siendo
riesgo del servicio; requiere la prueba de dos conexiones reales del criterio E antes del cierre.

### Comprobación de lectura y bloqueos externos (2026-10-28)

- MariaDB local real **10.4.32**, usuario de aplicación: metadatos de las cinco tablas coinciden
  en `gestion` y `gestion_test`, todas InnoDB/utf8mb4_unicode_ci, tipos/defaults y únicos indicados.
  Las cinco tablas tienen **0 filas en ambas bases** en el momento de revisar. No se ejecutó
  DDL, DML, seeds, cambios de configuración ni pruebas de escritura/concurrencia.
- `SELECT CAST('24:00:00' AS TIME)` devuelve `24:00:00` y 86400 segundos. `SHOW CREATE TABLE`
  confirma CHECK de orden y únicos de reserva. Esto verifica representación, no una inserción.
- EXPLAIN en `gestion`: conflictos usa `idx_space_occupancies_conflict`; rango global usa
  `idx_space_occupancies_range` con filesort; agenda propia elige ocupaciones y lookup único
  de reserva. Con tablas vacías esos planes no certifican rendimiento a volumen real.
- La sesión del cliente SQL directo sigue `time_zone=SYSTEM` y modo no estricto: la conexión
  de aplicación debe imponer UTC/modo estricto. Las pruebas locales previas de CHECK no
  certifican la versión/privilegios/configuración del hosting.
- **Pendiente de operación:** configurar al menos un espacio real, sus tarifas/horarios y
  confirmar zona IANA, granularidad y reglas propuestas. No sembrar una sala/tarifa ficticia
  como producción. El dataset demo sigue separado. El inventario real del hosting y sus
  restricciones CHECK, configuración UTC, BCMath y capacidad transaccional siguen sin validar;
  no se consideran resueltos por esta revisión local. Registrar propuestas en decisiones antes
  de integrar políticas distintas.
