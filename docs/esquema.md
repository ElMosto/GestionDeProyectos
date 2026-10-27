# Esquema de base de datos

> Documento vivo. Lo mantiene `arquitecto-datos`. El esquema se materializa en
> `database/migrations/`; este archivo explica el *porqué* y lo que no es evidente en el SQL.
> Fuente normativa: `docs/requerimiento-v1.0.md` §14 (y §5-§13 para el significado de cada regla).

Estado: **fase 1 cerrada**. 6 migraciones (`001`–`006`) y 3 semillas aplicadas desde cero en
MariaDB **10.4.32** (`gestion_test`): **34 tablas**, 0 no-InnoDB, todas `utf8mb4_unicode_ci`,
**63 claves foráneas**, **39 `CHECK` explícitos** (+7 que MariaDB genera para columnas `JSON`).
Verificado también que las semillas son idempotentes (segunda pasada sin errores ni duplicados).

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
