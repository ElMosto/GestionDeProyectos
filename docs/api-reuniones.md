# API de reuniones y notificaciones — fase 5

Contrato aditivo `/api/v1`, sesión+CSRF o Bearer, IDs/versiones string y fechas ISO con segundos/zona.
§13, §15, §19.F; esquema 005 existente, sin migración nueva. No modifica `/agenda` ni
`/agenda/reservations`.

## Reuniones

- `GET /meetings`: propias como organizador **o todas las invitaciones propias**, incluidas
  pendientes/rechazadas y canceladas; no es el calendario. `limit=25` (máx.100), `offset`.
- `POST /meetings`: `title`, `start_at`, `end_at`, `invited_user_ids` (lista única de usuarios
  activos, sin organizador; puede estar vacía); opcionales `description`, `location` (nullable),
  `modality=PRESENCIAL|VIRTUAL|HIBRIDA` (por defecto PRESENCIAL), `space_id` nullable,
  `acknowledge_conflicts` boolean (por defecto false). Organizador siempre actor.
- `GET /meetings/{id}`: organizador/invitado, ni ADMIN puede leer una ajena.
- `PATCH /meetings/{id}`: solo organizador, versión de reunión; campos editables anteriores
  **excepto** `invited_user_ids` (D-24: se rechaza, no se retiran invitados). Campos omitidos
  conservan valor. `space_id:null` libera espacio. No acepta `status`, `organizer_id`, occupancy.
- `POST /meetings/{id}/cancel`: organizador, versión de reunión y `reason` no vacío.
- `POST /meetings/{id}/response`: invitado propio, `response=ACEPTADA|RECHAZADA`
  (también admite ACCEPTED/DECLINED); versión **de invitación**, no reunión;
  `acknowledge_conflicts` opcional. Respuesta `{...meeting, invitation:{id,response,version,
  responded_at}}`; cambiar horario invalida versiones de TODAS las invitaciones.

Todas las escrituras anteriores exigen `Idempotency-Key` ASCII sin espacios 1–100 caracteres.
Ediciones/cancelación/respuesta requieren `If-Match: "N"`, `version` o `expected_version`
coherentes. Replay reautoriza objeto y representación actual; no salta privacidad.
Solo PROGRAMADA es modificable/respondible. Cancelar conserva historia y libera ocupación,
sin ventas ni reembolsos. No hay endpoint finalizar ni automatización de fase 6.

### Representación y privacidad (D-25)

Todos reciben `id,title,start_at,end_at,modality,status,version,organizer:{id,name}`;
el invitado recibe solo su `invitation:{id,response,version,responded_at}`.
Pendiente/rechazado **no** recibe descripción, ubicación/enlace, espacio ni otros invitados.
Organizador e invitado aceptado reciben `description,location,space_id`.
**Solo organizador**, en detalle/escrituras (no en listado), recibe
`invitations:[{id,invitee:{id,name},response,version,responded_at}]`.
No se envían emails, precios ni datos privados de otras actividades.

### Agenda

`GET /agenda/meetings?from=YYYY-MM-DD&to=YYYY-MM-DD&limit=25&offset=0`:
fechas civiles inclusivas en zona del usuario, rango máximo 31 días. Exige ambos extremos.
Solo organizador o invitado ACEPTADA y estado PROGRAMADA; respuesta paginada `data/meta`.
Eventos `{kind:"MEETING",id,title,start_at,end_at,status,modality,location}`;
sin respuestas/listas de otros invitados. Horarios son intervalos semiabiertos `[inicio,fin)`.

### Conflictos y propuestas conservadoras adicionales

**Propuesta:** crear/reprogramar con cruce de actividades propias exige
`acknowledge_conflicts:true`; aceptar un cruce personal también. Sin confirmación:
409 `CONFLICTS_REQUIRE_ACK`, sin IDs/títulos/terceros; con ella se permite. Se consideran
reuniones propias organizadas/aceptadas y reservas propias bloqueantes, tareas propias
no canceladas con fechas (día completo en zona del usuario). No se consulta agenda ajena.
Un espacio de estudio aplica siempre exclusión de ocupación, ventanas y granularidad del
espacio: 409 `RESERVATION_CONFLICT` no se puede omitir con acknowledge. Sin espacio no se
fuerza granularidad de 30 minutos (segundos válidos). Duración positiva y máximo 31 días.
**Propuesta:** cambiar solo espacio/modalidad/lugar/título/descripción conserva aceptaciones;
cambiar inicio O fin resetea todas, incluso rechazadas, y notifica nuevamente. No se implementa
edición de invitados ni recurrencias. Motivo obligatorio al cancelar se audita, no se notifica.

## Notificaciones

- `GET /notifications?limit=25&offset=0&unread=1`: solo propias; `unread` acepta 0/1,
  `meta:{total,limit,offset,unread_count}` (contador global propio, no solo página).
- `POST /notifications/{id}/read`: cuerpo vacío; marca propia, idempotente sin Key/versión,
  conserva primer `read_at`. Objeto ajeno incluso para ADMIN devuelve 404.
- DTO `{id,type,entity_type,entity_id,payload:{message,action},read_at,created_at}`.
  Payload siempre regenerado desde plantillas de servidor, nunca snapshots del objeto.
  Tipos MEETING_INVITED, MEETING_CHANGED, MEETING_CANCELLED, MEETING_RESPONDED,
  TASK_ASSIGNED, TASK_REASSIGNED, TASK_CHANGED, TASK_CANCELLED, RESERVATION_CREATED, RESERVATION_CHANGED,
  RESERVATION_CANCELLED. `action` es ruta interna validada por pareja tipo/entidad.
  Textos genéricos sin título, notas, enlaces privados, importes ni secretos.

Integración interna: `NotificationService::notify(array $userIds,string $type,string
$entityType,int $entityId): void`, sin dependencia de objetos de negocio ni MeetingService;
requiere transacción ya abierta en el mismo Database/PDO. Deduplica destinatarios y omite
inactivos. Falla propagada revierte mutación, auditoría y notificaciones juntas. Sin SMTP,
cron, websocket ni servicio externo. Abrir `action` debe reautorizar siempre el recurso actual.

Errores: 422 VALIDATION_FAILED; 404 NOT_FOUND invisible; 409 VERSION_CONFLICT,
INVALID_STATE, IDEMPOTENCY_KEY_REUSED, CONFLICTS_REQUIRE_ACK, RESERVATION_CONFLICT.
Sin trazas ni detalles privados. Hosting y aceptación final pendientes de verificación global.
