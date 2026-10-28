# API de espacios y reservas — fase 4

Contrato para UI/QA (§12, §13, §19.E; D-20–23). Prefijo `/api/v1`.
IDs y versiones son strings; importes strings decimales de dos posiciones. Instantes ISO8601
con zona obligatoria en entrada, UTC `Z` en salida. Sesión+CSRF o Bearer.

## Recursos

- `GET/POST /spaces`, `GET/PATCH /spaces/{id}`. Crear exige `name,hourly_rate`;
  opcionales `description,block_minutes` (30), `active` (true). PATCH admite esos campos y versión.
- `PUT /spaces/{id}/hours`: `{version,hours:[{weekday:1,local_start_time:"09:00:00",local_end_time:"24:00:00"}]}`.
  Sin horarios no hay disponibilidad; filas adyacentes sí, solapadas no. Configuración ADMIN.
- `GET /spaces/{id}/availability?from=ISO&to=ISO`: rango máximo 31 días, semiabierto;
  `{space_id,timezone,windows:[{start_at,end_at}],occupancies:[{id,space_id,start_at,end_at,kind,status}],guaranteed:false}`.
  No contiene banda, responsable, notas ni importes. No garantiza el hueco.
  Más de 5000 ocupaciones en el rango exige reducirlo (422), nunca trunca silenciosamente.
- `GET/POST /reservations`, `GET/PATCH /reservations/{id}`. POST exige
  `band_client_id,space_id,start_at,end_at`, opcionales `notes,reason`. Responsable = actor;
  `responsible_id`, precios, totales y estado enviados se rechazan. PATCH **solo** `notes` y versión.
- `POST /reservations/{id}/reschedule`: `{version,space_id,start_at,end_at,reason?}`.
- `POST /reservations/{id}/transitions`: `{version,status:"IN_PROGRESS"|"FINISHED",reason?}`.
  Transiciones CONFIRMED→IN_PROGRESS→FINISHED; cancelación separada.
- `POST /reservations/{id}/cancel`: `{version,reason}`; exclusivamente ADMIN, neto cobrado cero.
- `GET/POST /space-blocks`: POST `{space_id,start_at,end_at,reason}`; ADMIN.
- `POST /space-blocks/{id}/cancel`: `{version,reason?}`; ADMIN.

Todas las escrituras de reservas/bloqueos exigen `Idempotency-Key`; edición exige `version`,
`expected_version` o `If-Match: "N"` coherentes. PATCH de notas también es idempotente.
Listas: `limit` 25 (máximo 100), `offset`, `q`; reservas además
`from,to` (instantes ISO con zona), `status,band_client_id,space_id`. Filtro intervalo por solape.
Respuesta `{data:[],meta:{total,limit,offset}}`. ADMIN lista todas; demás solo propias.

Reserva DTO: `id,occupancy_id,band_client_id,band_name,space_id,space_name,sale_id,
responsible_id,hourly_rate_snapshot,total,status,notes,version,start_at,end_at,duration_minutes,created_at,updated_at`;
`duration_minutes` es entero, calculado por diferencia real UTC (no persistido).
detalle añade `financial_summary` (mismo ledger de ventas). No incluye contactos privados.
Espacio DTO: `id,name,description,block_minutes,active,version,hours`; `hourly_rate` solo ADMIN.
Bloque DTO ADMIN: `id,occupancy_id,space_id,start_at,end_at,status,reason,version`.

## Reglas

Crear confirma y emite exactamente una venta RESERVA/EMITIDA con partida cantidad 1.000;
no registra pago. Cobrar siempre por `sale_id` existente. Misma sala conserva tarifa histórica;
cambiar adopta tarifa actual. Reprogramar conserva IDs/folio/emisión y nunca baja del neto pagado.
Con pagos REGISTRADO (incluso reembolsados), cambiar total o tarifa requiere ADMIN (D-15);
el responsable sí puede mover horario sin cambios de valor/tarifa. `q` en bloqueos busca motivo,
solo por la lista ADMIN, nunca por el calendario mínimo.
No hay reembolso ni penalización automática. Retrospectiva solo ADMIN con motivo auditado.
Segundos cero, extremos alineados desde medianoche local y duración UTC múltiplo del bloque.
Todo intervalo individual de reserva/mantenimiento se limita conservadoramente a 31 días.
La zona del estudio determina horarios; extremos DST ambiguos/inexistentes de ventanas se rechazan.
No se desactiva ni cambia granularidad/horarios con ocupaciones futuras bloqueantes sin resolver.

`GET /agenda/reservations` (controlador y ruta aditivos integrados por el coordinador) usa
`ReservationService::agenda(array $query): array`, recibe `from,to` DATE inclusivos en zona
personal del actor (máximo 31 días) y paginación. Devuelve página `{data,meta}` de **propias**,
también para ADMIN, sin canceladas, eventos `{id,reservation_id,kind:"RESERVATION",
title,start_at,end_at,status,space_id}`; título genérico con nombre del espacio, sin notas ni dinero.
El integrador fusiona explícitamente esta proyección; este módulo no altera `/agenda`.

Errores 422 `VALIDATION_FAILED`; 409 `VERSION_CONFLICT`, `RESERVATION_CONFLICT`,
`SPACE_INACTIVE`, `SPACE_HAS_FUTURE_OCCUPANCIES`, `RESERVATION_STATE_CONFLICT`,
`OVERPAYMENT`, `PAYMENTS_REQUIRE_RESOLUTION`, `IDEMPOTENCY_KEY_REUSED`; invisibles 404.
