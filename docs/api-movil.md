# Contrato de API para la futura app Android

La app Android no está en el alcance inicial (§20), pero el contrato se diseña ahora para que
añadirla no obligue a refactorizar. Estas reglas son vinculantes para todo endpoint.

## 1. Principios

1. **El servidor es la única autoridad.** La app es un cliente delgado: no reimplementa cálculo
   de importes, permisos, disponibilidad ni estados. Si una regla vive en JavaScript, habrá que
   reescribirla en Kotlin y divergirá.
2. **Contrato primero.** OpenAPI 3.1 en `docs/openapi.yaml` es la fuente de verdad. El cliente
   Android se valida contra ese documento, no contra el código.
3. **Sin HTML de negocio.** Los únicos endpoints que devuelven HTML son las vistas de impresión
   (`/ticket`). La app nunca *parsea* HTML del servidor.
4. **Estable por defecto.** Cambiar la forma de una respuesta existente es un cambio incompatible
   y exige `/api/v2`. Añadir campos opcionales sí es compatible.

## 2. Autenticación (D-08)

Dos mecanismos conviviendo, misma capa de autorización:

| Cliente | Mecanismo | Transporte |
|---|---|---|
| Web | Sesión PHP + cookie `Secure; HttpOnly; SameSite=Lax` + CSRF | Cookie |
| Android | Token opaco de 32 bytes (`base64url`), guardado **hasheado** (SHA-256) en `api_tokens` | `Authorization: Bearer <token>` |

Reglas de los tokens:

- Se crean desde "Mi usuario → Dispositivos" o por el administrador; se muestran **una sola vez**.
- Columnas mínimas: `id, user_id, name, token_hash, last_used_at, expires_at, revoked_at, created_at`.
- Revocables individualmente (pérdida del teléfono) y en bloque al desactivar la cuenta.
- Con Bearer **no se exige CSRF**, pero sí se valida `Origin`/`User-Agent` de forma informativa y
  se aplica rate limit. Con cookie **sí se exige CSRF**, sin excepciones.
- Sin JWT: el token es opaco y su validez se resuelve en base de datos, lo que permite revocación
  inmediata.
- Un token hereda rol y permisos del usuario; no se emiten permisos dentro del token.
- Caducidad propuesta: 90 días con renovación por uso, y revocación total al cambiar contraseña.

## 3. Forma de las respuestas

```json
{ "data": { }, "meta": { } }
```

```json
{ "error": { "code": "VALIDATION_FAILED", "message": "El correo ya está registrado.", "fields": { "email": "Duplicado" } }, "request_id": "req_01H..." }
```

- `code` es estable, en inglés y **nunca traducido**: la app decide con él.
- `message` es texto para el usuario final, en español.
- `fields` mapea campo → mensaje, para pintar errores junto al input.
- `request_id` se muestra en el detalle del error y sirve para soporte; también va en logs.

Códigos HTTP: `200`, `201`, `204`, `400`, `401`, `403`, `404`, `409` (conflicto de versión,
reserva solapada, sobrepago), `422` (validación), `429` (rate limit), `500`.

## 4. Tipos

| Dato | JSON | Nota |
|---|---|---|
| Importe | `"150.00"` (string) | Nunca número: evita pérdida de precisión en Kotlin/JS |
| Cantidad | `"2.000"` (string) | `DECIMAL(10,3)` |
| Fecha-hora | `2026-10-27T18:30:00-06:00` | ISO 8601 con zona; se guarda en UTC |
| Fecha | `2026-10-27` | Sin hora, sin zona |
| ID | `"123"` (string) | BIGINT puede exceder el entero seguro de JS |
| Booleano | `true` | Nativo |
| Enumeraciones | `"EN_CURSO"` | MAYÚSCULAS_SNAKE, estables |

## 5. Escrituras

- **Idempotencia:** `Idempotency-Key` obligatorio en emisión de ventas, pagos, reembolsos y
  reservas. Misma clave + mismo cuerpo → se devuelve la respuesta original (mismo `200`). Misma
  clave + cuerpo distinto → `409 IDEMPOTENCY_KEY_REUSED`.
- **Concurrencia optimista:** `If-Match: "3"` o `expected_version` en el cuerpo. Desactualizado →
  `409 VERSION_CONFLICT` con la versión actual en `error.fields.version`.
- **Rutas explícitas para estados:** `POST /sales/{id}/issue`, `/cancel`, `/transitions`. Nunca
  `PATCH {status}` para cambios con reglas.
- **PATCH parcial:** solo campos enviados se modifican; lista blanca por recurso.

## 6. Listas y sincronización

- Paginación por `limit` (máx. 100, por defecto 25) + `offset`, y `meta: { total, limit, offset }`.
- Filtros y orden solo con lista blanca; un parámetro desconocido se ignora, nunca se traduce a SQL.
- Para agenda y notificaciones, consulta por rango (`from`, `to`) y sondeo moderado; no se asume
  WebSocket (§ alcance inicial).
- Notificaciones: endpoint incremental (`?since=<timestamp>`) para no recargar todo en móvil.

## 7. Capacidades y permisos en el cliente

`GET /auth/me` devuelve, además del perfil:

```json
{
  "data": {
    "user": { "id": "3", "name": "...", "role": "LIDER_PROYECTO", "timezone": "America/Mexico_City" },
    "capabilities": {
      "projects.create": true,
      "projects.viewFinance": false,
      "sales.create": true,
      "finance.dashboard": false,
      "tasks.createStandalone": false
    }
  }
}
```

La app usa `capabilities` para ocultar UI. **El backend vuelve a autorizar en cada petición**:
`capabilities` es conveniencia de interfaz, no un permiso.

## 8. Archivos

- Avatar: `multipart/form-data`, máx. 2 MB, JPG/PNG/WebP, MIME real verificado, reprocesado en
  servidor, nombre generado. Se rechaza SVG y cualquier ejecutable.
- Descargas (`gantt.xlsx`): `Content-Disposition: attachment` con nombre `proyecto_FOLIO_gantt.xlsx`.
  En Android se descarga a almacenamiento propio, no se intenta renderizar en WebView.
- Tickets: HTML de impresión para el navegador. Para Android, en el futuro, se evaluará un endpoint
  JSON del mismo contenido; no se hará *scraping* del HTML.

## 9. Compatibilidad y evolución

- Un campo nunca cambia de tipo ni de significado dentro de `/api/v1`.
- Campos nuevos: opcionales y con valor por defecto del lado del cliente.
- Deprecación: se marca en OpenAPI, se mantiene al menos una versión menor.
- Cliente Android: `minSdk` y versión de app se envían en `X-Client` y `X-Client-Version` para
  diagnóstico; el servidor puede exigir actualización devolviendo `426 UPGRADE_REQUIRED`.

## 10. Checklist para quien añada un endpoint

- [ ] Documentado en `docs/openapi.yaml` con permisos, ejemplos y errores.
- [ ] Autorización por rol **y** por objeto verificada con una prueba.
- [ ] Campos sensibles filtrados también para quien no debe verlos.
- [ ] Importes como string, fechas ISO 8601, IDs como string.
- [ ] `Idempotency-Key` si crea dinero o bloquea espacio.
- [ ] `If-Match`/`expected_version` si edita algo versionado.
- [ ] Probado con `curl` sin cookie, con cookie y con Bearer.
