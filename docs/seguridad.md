# Seguridad: sesión, CSRF, tokens y permisos (fase 1)

Implementación de §5, §6, §15 y §16. Este documento describe lo que existe, cómo se prueba y
qué contrato deben usar los demás módulos. Cuando una decisión no estaba en el requerimiento se
marcó explícitamente en «Decisiones locales».

## 1. Mapa de archivos

| Archivo | Responsabilidad |
|---|---|
| `src/Auth/SessionManager.php` | Cookie, apertura, regeneración, caducidad por inactividad y absoluta, destrucción |
| `src/Auth/Csrf.php` | Token por sesión y validación con `hash_equals` |
| `src/Auth/LoginThrottle.php` | Rate limit de login por cuenta e IP |
| `src/Auth/Authenticator.php` | Resuelve identidad en cada petición (Bearer o cookie) |
| `src/Auth/TokenService.php` | Emisión, listado y revocación de tokens opacos |
| `src/Auth/TokenAuthenticator.php` | Validación del Bearer contra `api_tokens` y `users` |
| `src/Auth/TemporaryPasswordService.php` | Clave temporal de un solo uso (§6) |
| `src/Auth/AuditLogger.php` | Bitácora saneada en `audit_logs` |
| `src/Auth/AvatarStorage.php` | Validación real, reprocesado GD 512×512 y almacenamiento privado |
| `src/Auth/AuthController.php` | `csrf`, `login`, `logout`, `auth/me` |
| `src/Auth/MeController.php` | `GET/PATCH /me`, `POST /me/password`, `POST/GET /me/avatar` |
| `src/Auth/TokenController.php` | `GET/POST /me/tokens`, `DELETE /me/tokens/{id}` |
| `src/Auth/UserPresenter.php` | Serializador de usuario (lista blanca de campos) |
| `src/Auth/AuthContext.php` | Identidad de la petición; la llena el middleware |
| `src/Auth/Repositories/*` | SQL de cuentas, tokens, intentos, claves y auditoría |
| `src/Policies/Role.php` | Los tres roles (D-01) |
| `src/Policies/Capabilities.php` | Matriz rol → capacidades |
| `src/Policies/Authorizer.php` | `requireCapability`, `requireAdmin`, políticas por objeto de clientes, proyectos y tareas |
| `src/Policies/LastAdminGuard.php` | Protección del último administrador activo |
| `src/Core/middleware.php` | Cadena: identidad → cuenta activa → contraseña → CSRF → router |
| `src/Core/routes/auth.php` | Rutas registradas |
| `src/Core/providers/auth.php` | Servicios en el contenedor |

## 2. Sesión web

- Cookie `gestion_session` (nombre de `session.name`): `HttpOnly`, `Secure` según
  `session.cookie_secure`, `SameSite=Lax`, `path=/`, sin `Max-Age` (cookie de sesión).
- `session.use_strict_mode=1` y `session.use_only_cookies=1`: un ID inventado no se adopta.
- **Nunca se abre sesión si la petición trae `Authorization: Bearer`** (D-08). Un token inválido
  no cae a la cookie: la petición queda anónima y recibe `401`.
- **Regeneración de ID:** al autenticar (`SessionManager::login`), al cambiar la contraseña
  (`regenerate`) y cuando el rol del usuario cambia respecto a la instantánea guardada en la
  sesión (`refreshAuthorization`).
- **Caducidad:** `session.idle_timeout` (30 min) se mide contra `_auth_last_seen`, que se refresca
  en cada petición con sesión válida. `session.absolute_timeout` (12 h) se mide contra
  `_auth_started_at`, que se reinicia al autenticar. Al vencer, la sesión se destruye y la
  petición se trata como anónima (`401`).
- **Cuenta activa en cada petición:** el middleware recarga el usuario de la base; si
  `users.active = 0` destruye la sesión, registra `ACCOUNT_INACTIVE_ACCESS` y devuelve `401`.
  Sesiones ya abiertas pierden el acceso al instante.
- `POST /auth/logout` es el único logout (GET devuelve `405`). Destruye la sesión y caduca la
  cookie. Con Bearer revoca además el token usado.

## 3. CSRF

- `GET /api/v1/auth/csrf` devuelve `{"data":{"token":"<64 hex>"}}` y arranca la sesión si no
  existía (primera petición del navegador).
- Se exige `X-CSRF-Token` (o `_csrf` en el cuerpo) en **toda** petición mutante autenticada por
  cookie, **incluido `POST /auth/login`**. Con `Authorization: Bearer` no se exige.
- Comparación con `hash_equals`. Fallo → `403 CSRF_INVALID` (ver «Decisiones locales»).
- El token sobrevive a la regeneración del ID tras el login: el frontend no necesita pedir uno
  nuevo al autenticarse.

Secuencia mínima para el frontend y para el móvil:

```
GET  /api/v1/auth/csrf          -> token + cookie de sesión
POST /api/v1/auth/login         -> con X-CSRF-Token
POST /api/v1/me/tokens          -> con X-CSRF-Token; devuelve el token en claro UNA vez
(desechar la cookie; usar Authorization: Bearer)
```

Con `must_change_password = 1` el login funciona, pero el resto de rutas responde
`403 PASSWORD_CHANGE_REQUIRED` hasta cambiar la contraseña; por eso un usuario nuevo obtiene su
token móvil después del primer cambio de contraseña.

## 4. Rate limit de login

- `login_attempts` registra **todos** los intentos (éxito y fallo) con correo normalizado, IP y
  `user_agent` recortado a 255 caracteres.
- Se cuentan fallos dentro de `security.login_window` (900 s): por correo normalizado y por IP.
  Al llegar a `security.login_max_attempts` (5) el siguiente intento devuelve
  `429 RATE_LIMITED` con `Retry-After`, sin verificar la contraseña.
- Respuesta de credenciales siempre genérica: `401 INVALID_CREDENTIALS`, el mismo cuerpo para
  correo inexistente, contraseña incorrecta y cuenta desactivada. `password_verify` se ejecuta
  también con un hash de relleno cuando el correo no existe, para no filtrar información por
  temporización.
- Cuenta desactivada: mismo `401`, pero se audita `LOGIN_INACTIVE_ACCOUNT` con el usuario real.

## 5. Tokens opacos para Android (D-08)

- Formato: 32 bytes aleatorios en `base64url` (43 caracteres). En `api_tokens` solo se guarda
  `hash('sha256', $token)`.
- `expires_at` = ahora + `security.token_ttl_days` (90 días por defecto).
- Validación Bearer: token existente, no revocado, no expirado y **usuario activo**. Se actualiza
  `last_used_at` como máximo una vez por minuto y token.
- Revocación individual: `DELETE /me/tokens/{id}`; solo tokens propios (ajeno o inexistente →
  `404`, sin revelar existencia). Revocación masiva: al cambiar contraseña, al emitir una clave
  temporal y al desactivar la cuenta (`TokenService::revokeAll()`).
- Sin JWT: la validez se resuelve en base de datos, con revocación inmediata.

## 6. Contraseña y clave temporal

- `POST /me/password` exige `current_password`, mínimo `security.password_min_length` (12) y que
  la nueva sea distinta de la actual. Actualiza `password_hash` y `password_changed_at`, pone
  `must_change_password = 0`, **revoca todos los tokens**, rota el ID de sesión y audita
  `PASSWORD_CHANGE` sin hashes.
- `must_change_password = 1` permite únicamente:
  `GET /auth/me`, `POST /auth/logout`, `POST /me/password`, `GET /auth/csrf`, `GET /me/avatar`.
  Todo lo demás → `403 PASSWORD_CHANGE_REQUIRED` (también con Bearer).
- `TemporaryPasswordService::issue()` (para `POST /users/{id}/reset-password`, fase 1): genera 20
  caracteres sin ambigüedades, guarda el hash en `password_resets` con caducidad, fija
  `must_change_password = 1` y revoca los tokens. La clave en claro se entrega una sola vez.
  Al cambiarla, `POST /me/password` consume las claves pendientes (`used_at`).

## 7. Avatar

- `POST /me/avatar` (multipart, campo `avatar`): tamaño máximo `uploads.avatar_max_bytes`, MIME
  real con `finfo`, solo `image/jpeg`, `image/png` y `image/webp`, y comprobación con
  `getimagesize`. Se rechazan SVG, extensiones ejecutables (`.php`, `.phtml`, `.phar`, `.sh`,
  `.exe`, `.js`, `.html`, ...) y archivos que no son imagen real, aunque se renombren.
- Se **reprocesa con GD** a 512×512 con recorte centrado (`cover`), lo que descarta EXIF y
  cualquier carga útil incrustada. El nombre lo genera el servidor (32 hex + extensión deducida
  del tipo real) y se guarda en `storage/private/avatars/`, fuera de la raíz pública.
- `GET /me/avatar` sirve solo el avatar del usuario autenticado, con `Content-Type` correcto,
  `Cache-Control: private` y validación estricta de la ruta guardada (anti path traversal).

## 8. RBAC

Tres roles y ninguno más: `ADMINISTRADOR`, `LIDER_PROYECTO`, `COLABORADOR` (D-01). La matriz vive
en `src/Policies/Capabilities.php` y está alineada con
`.opencode/skills/permisos-rbac/references/matriz-permisos.md`.

Reglas:

1. **Deny by default.** Si una capacidad no está en la matriz, es `false`. Toda ruta privada sin
   identidad devuelve `401`; toda acción sin capacidad explícita, `403`.
2. **Dos preguntas.** `requireCapability()` responde la pregunta global (rol). Las políticas por
   objeto responden la segunda: proyectos/tareas ya comprueban liderazgo, membresía y
   asignación. `is_member` es un booleano calculado por el repositorio, nunca del payload.
   `canManageSale` sigue cerrado hasta fase 3.
3. **`capabilities` es informativo.** Lo que viaja en `GET /auth/me` solo sirve para ocultar UI;
   el backend reautoriza en cada petición.
4. **Último administrador.** `LastAdminGuard` lanza `409 LAST_ADMIN_PROTECTED` al desactivar o
   degradar al último ADMINISTRADOR activo.
5. **Nunca se serializa de más.** `UserPresenter` usa lista blanca; el contexto de autenticación
   elimina `password_hash` incluso si un repositorio lo devolviera por error.

Capacidades mínimas de fase 1 y a quién se conceden:

| Capacidad | A | L | C |
|---|---|---|---|
| `users.manage`, `audit.view`, `settings.manage` | ✔ | — | — |
| `finance.dashboard`, `expenses.view`, `expenses.manage` | ✔ | — | — |
| `catalog.manage` | ✔ | — | — |
| `catalog.view` | ✔ | ✔ | ✔ |
| `clients.create`, `clients.view`, `clients.editOwn` | ✔ | ✔ | ✔ |
| `clients.editAny`, `clients.deactivate`, `clients.convert` | ✔ | — | — |
| `me.edit`, `me.avatar`, `me.tokens` | ✔ | ✔ | ✔ |
| `projects.create` | ✔ | ✔ | — |
| `projects.viewAny` / `manageAny` / `viewFinanceAny` | ✔ | — | — |
| `projects.viewOwn` / `manageOwn` / `viewFinanceOwn` | ✔ | ✔ | — |
| `projects.viewMember` | ✔ | — | ✔ |
| `sales.create`, `sales.issueOwn`, `sales.viewOwn`, `sales.ticketOwn` | ✔ | ✔ | ✔ |
| `sales.cancelWithoutPaymentsOwn` | ✔ | ✔ | — |
| `sales.cancelWithPayments`, `sales.cancelWithoutPaymentsAny` | ✔ | — | — |
| `payments.registerOwn`, `reservations.create`, `reservations.viewOwn` | ✔ | ✔ | ✔ |
| `payments.void`, `payments.refund`, `reservations.cancelAny`, `reservations.retroactive` | ✔ | — | — |
| `reservations.cancelOwn` | ✔ | ✔ | — |
| `spaces.manage` | ✔ | — | — |

## 9. Auditoría

`AuditLogger` escribe en `audit_logs` (`action`, `entity_type`, `entity_id`, `before_json`,
`after_json`, `request_id`, `ip_address`) con el actor tomado del contexto. Un fallo al auditar no
rompe la operación: se anota en `storage/logs/php-error.log`.

Acciones de fase 1: `LOGIN_SUCCESS`, `LOGIN_FAILED`, `LOGIN_BLOCKED`, `LOGIN_INACTIVE_ACCOUNT`,
`ACCOUNT_INACTIVE_ACCESS`, `LOGOUT`, `PASSWORD_CHANGE`, `PASSWORD_RESET_ISSUED`, `PROFILE_UPDATE`,
`AVATAR_UPDATE`, `TOKEN_CREATED`, `TOKEN_REVOKED`, `TOKENS_REVOKED_ALL`.

Saneado antes de escribir: se sustituye por `[omitido]` toda clave `password*`,
`*_password` (salvo `must_change_password`), `token`, `*_token`, `*_hash`, `*_secret`, `csrf`,
`authorization`, `card*`, `cvv`, `pan`. Nunca se guardan contraseñas, hashes ni tokens; los
indicadores (`must_change_password`, `tokens_revoked`) sí, porque son parte de la trazabilidad.

## 10. Rutas registradas

| Método | Ruta | Autorización |
|---|---|---|
| GET | `/api/v1/auth/csrf` | pública |
| POST | `/api/v1/auth/login` | pública + CSRF |
| POST | `/api/v1/auth/logout` | autenticado |
| GET | `/api/v1/auth/me` | autenticado |
| GET | `/api/v1/me` | autenticado, `me.edit` |
| PATCH | `/api/v1/me` | autenticado, `me.edit` |
| POST | `/api/v1/me/password` | autenticado (permitida con contraseña temporal) |
| POST | `/api/v1/me/avatar` | autenticado, `me.avatar` |
| GET | `/api/v1/me/avatar` | autenticado (permitida con contraseña temporal) |
| GET | `/api/v1/me/tokens` | autenticado, `me.tokens` |
| POST | `/api/v1/me/tokens` | autenticado, `me.tokens` |
| DELETE | `/api/v1/me/tokens/{id}` | autenticado, `me.tokens` (solo propios) |

Públicas sin sesión: `GET /` y `GET /api/v1/health`.

## 11. Contrato para `backend-php`

Cualquier controlador nuevo debe inyectar estos servicios y autorizar **antes** de ejecutar el
servicio de negocio:

```php
use App\Auth\Authenticator;
use App\Policies\Authorizer;
use App\Policies\Capabilities;

public function __construct(
    private readonly Authorizer $authorizer,
    private readonly Authenticator $auth,
) {}

public function index(Request $request): Response
{
    // 1) rol: capacidad global (lanza 403 si no la tiene, 401 si no hay identidad)
    $this->authorizer->requireCapability(Capabilities::USERS_MANAGE);

    // 2) objeto: además del rol, verificar la relación del actor con el registro.
    // if (!$this->authorizer->canViewProject($project)) { throw ApiException::forbidden(); }

    $actor = $this->authorizer->user();   // array público, sin password_hash
    $actorId = $this->authorizer->id();   // int|null
}
```

- `$authorizer->requireAdmin()` para operaciones de alto impacto (§5).
- `$authorizer->requireUser()` devuelve el usuario o lanza `401`.
- `$authorizer->can($cap)` para decisiones de UI/serializador, nunca como única defensa.
- `$auth->user()` es equivalente a `$authorizer->user()` (misma identidad de la petición).
- Listados: filtrar por relación **en la consulta SQL**, no en PHP después de cargar todo.
- `403` no revela existencia; `404` solo si el actor podría ver el objeto.
- Para desactivar cuentas o cambiar roles:
  `LastAdminGuard::assertCanDeactivate($id)` / `assertCanChangeRole($id, $newRoleId)`, dentro de
  la transacción, antes del `UPDATE`.
- Al desactivar una cuenta: `TokenService::revokeAll($userId, 'account_deactivated')`.
- Al restablecer acceso: `TemporaryPasswordService::issue($userId, $actorId)` y devolver la clave
  una sola vez, sin registrarla en auditoría ni en logs.
- Serializadores de otros módulos deben usar `Authorizer::capabilities()` (o `can()`) para
  recortar precios, pagos, gastos y ticket financiero también en `.xlsx`, agenda y errores.

## 12. Decisiones locales

1. **CSRF inválido devuelve `403 CSRF_INVALID`, no `419`.** El contrato móvil (§3 de
   `docs/api-movil.md`) solo documenta 400/401/403/404/409/422/429 y la app decide con `code`.
   Ambos códigos estaban permitidos por la especificación.
2. **El token CSRF no se rota al iniciar sesión.** Se conserva a través de la regeneración del ID
   para que el cliente no tenga que pedir uno nuevo justo después del login. Sí se rota, en la
   práctica, al destruir la sesión (logout).
3. **`GET /me/avatar` emite el binario con `readfile()` y termina la petición**, porque el
   contrato de `Response` del núcleo solo serializa JSON. No se modifica `src/Core/Response.php`
   por alcance; si se añade `Response::stream()` en el núcleo, este controlador debe migrarse.
4. **`PATCH /me` rechaza campos desconocidos además de los sensibles.** Un campo no previsto es
   `422`, no se ignora: la lista blanca es explícita.
5. **`must_change_password` se aplica también a los tokens móviles.** Una clave temporal no sirve
   para operar desde la app sin cambiarla primero.
6. **El correo no es editable desde `PATCH /me`.** Cambiar el identificador de acceso queda
   pendiente de un flujo propio con verificación y auditoría; no existe hoy ese endpoint.
7. **IP del rate limit:** se toma `REMOTE_ADDR`, nunca `X-Forwarded-For` sin una lista explícita
   de proxies de confianza. La configuración de proxy/HTTPS debe revisarse al inventariar hosting.
8. **Origen web:** con cookie se rechaza `Sec-Fetch-Site: cross-site` y un `Origin` distinto
   de esquema/host/puerto del servidor. Sin Origin sigue siendo obligatorio el token CSRF.
9. **Cambio de contraseña:** rota la sesión actual y revoca tokens; no invalida otras sesiones
   web existentes. La interfaz no promete ese comportamiento no implementado.

## 13. Verificación inicial ejecutada (curl contra `127.0.0.1:8080`)

La tabla conserva la evidencia inicial de autenticación, antes de integrar usuarios/clientes.
Para la verificación actual automatizada y sus resultados, ver `docs/validacion.md`.

| Caso | Esperado | Obtenido |
|---|---|---|
| `GET /auth/csrf` sin sesión | 200 + cookie `HttpOnly; SameSite=Lax` | 200 |
| `POST /auth/login` sin `X-CSRF-Token` | 403 `CSRF_INVALID` | 403 |
| `POST /auth/login` con CSRF y credenciales correctas | 200 + `must_change_password` | 200 |
| `POST /auth/login` con contraseña incorrecta | 401 `INVALID_CREDENTIALS` genérico | 401 |
| `POST /auth/login` con correo inexistente | 401 idéntico | 401 |
| 5 fallos seguidos y 6.º intento | 429 `RATE_LIMITED` | 429 |
| `GET /auth/me` sin sesión | 401 | 401 |
| `GET /auth/me` con cookie | 200 | 200 |
| `GET /api/v1/users` con `must_change_password=1` | 403 `PASSWORD_CHANGE_REQUIRED` | 403 |
| lo mismo tras cambiar la contraseña | ya no 403 | 404 (ruta de fase 2) |
| `PATCH /me` con `role_id` / `active` / `password_hash` | 422 `VALIDATION_FAILED` | 422 |
| `PATCH /me` válido | 200, `version` +1 | 200 |
| `PATCH /me` con `version` obsoleta | 409 `VERSION_CONFLICT` | 409 |
| `POST /me/tokens` | 201 + token en claro una vez | 201 |
| `GET /auth/me` con Bearer | 200 | 200 |
| `POST /me/tokens` con Bearer y sin CSRF | 201 | 201 |
| `DELETE /me/tokens/{id}` | 204 | 204 |
| reutilizar el token revocado | 401 | 401 |
| `POST /me/password` con actual incorrecta | 422 | 422 |
| `POST /me/password` con clave corta | 422 | 422 |
| `POST /me/password` correcta | 200, `tokens_revoked >= 1`, sesión rotada | 200 (`tokens_revoked: 1`) |
| avatar PNG real | 200 y archivo 512×512 | 200, `512x512 image/png` |
| avatar SVG | 422 | 422 |
| PHP real renombrado a `.png` | 422 | 422 |
| PNG real renombrado a `.php` | 422 | 422 |
| `GET /me/avatar` | 200 `image/png`, `Cache-Control: private` | 200 |
| `GET /auth/logout` | 405 | 405 |
| `POST /auth/logout` | 204 | 204 |
| `GET /auth/me` tras logout | 401 | 401 |
| sesión abierta + `active = 0` en BD | 401 inmediato | 401 |
| login con cuenta desactivada | 401 genérico + auditoría | 401 |

Estado final del administrador de pruebas: contraseña `CambiarEstaClave2026!`,
`must_change_password = 1`, sin tokens, sin avatar. `login_attempts` y `password_resets` vacías;
`audit_logs` conserva el rastro de las pruebas (append-only).

## 14. Pendientes conocidos

- Política por objeto de ventas: pendiente de fase 3. Proyectos y tareas ya implementadas y
  verificadas contra endpoints reales; replay idempotente reautoriza antes de entregar datos.
- `Response::stream()` en el núcleo para dejar de emitir el avatar con `exit`.
- Rate limit general por IP para el resto de endpoints (hoy solo login, según §16).
- `POST /users/{id}/reset-password` y `POST /users/{id}/deactivate` ya están integrados en
  fase 1 con `TemporaryPasswordService`, `TokenService::revokeAll` y `LastAdminGuard`,
  cubiertos por `UserServiceTest` y `AuthHttpTest`.
- Rotación de `session.save_path` y limpieza de sesiones vencidas por cron en el hosting (§18).
