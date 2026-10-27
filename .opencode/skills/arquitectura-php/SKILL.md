---
name: Arquitectura PHP sin framework
description: Estructura por capas, router, inyección de dependencias, envelope JSON, errores, paginación, versionado optimista y transacciones para el backend PHP puro. Cárgala al crear o modificar cualquier endpoint.
---

# Arquitectura PHP sin framework

## Capas y responsabilidades

```
public/index.php   front controller: carga autoload, config, arranca Kernel
Kernel             middleware encadenado: request-id → sesión/token → CSRF → rate limit → router
Router             método + ruta → [Controller, método]; 404/405 JSON
Controller         valida forma, llama al Service, elige código HTTP y serializa
Service            reglas de negocio, transacciones, estados, auditoría
Repository         SQL preparado; única capa que conoce PDO
Policy             autorización por rol y por objeto (vive en src/Policies)
Validation         reglas de campos, listas blancas, mensajes en español
```

Regla mental: si una regla aparece en dos capas, está mal ubicada. El controlador no decide, el
servicio no imprime SQL, el repositorio no valida negocio.

## Arranque

- `declare(strict_types=1);` en todos los archivos.
- Autoload PSR-4 `App\` → `src/`. Sin `require` manuales.
- Configuración como array inmutable cargado desde `config/config.php`, con `config.example.php`
  versionado. Nunca credenciales en el repositorio.
- Zona horaria interna **UTC** (`date_default_timezone_set('UTC')`); la zona del usuario se aplica
  al presentar.
- `display_errors=0`; los errores van a `storage/logs/` sin datos sensibles.

## Router y rutas

- Prefijo real `/api/v1`. Rutas declarativas con parámetros `{id}` y conversión a int validada.
- Rutas registradas en un solo archivo (`src/Core/routes.php`) para poder auditar la superficie.
- Si no hay `mod_rewrite`, `public/index.php` acepta `?r=/api/v1/...`; ambas formas deben funcionar.
- Método no permitido → `405` con `Allow`; ruta inexistente → `404` JSON, nunca HTML.

## Respuestas

Éxito: `{"data": ..., "meta": {...}}`. Error: `{"error":{"code","message","fields"},"request_id"}`.

| Situación | HTTP | `code` |
|---|---|---|
| Validación | 422 | `VALIDATION_FAILED` |
| No autenticado | 401 | `UNAUTHENTICATED` |
| Sin permiso | 403 | `FORBIDDEN` |
| No encontrado / invisible | 404 | `NOT_FOUND` |
| Versión desactualizada | 409 | `VERSION_CONFLICT` |
| Clave idempotente reutilizada | 409 | `IDEMPOTENCY_KEY_REUSED` |
| Conflicto de negocio (solape, sobrepago) | 409 | `RESERVATION_CONFLICT`, `OVERPAYMENT` |
| Rate limit | 429 | `RATE_LIMITED` |

`request_id` se genera por petición y se repite en el log. No se devuelven trazas ni SQL.

## Listas

- `limit` por defecto 25, máximo 100. `offset` acotado.
- Filtros y `sort` con lista blanca por recurso; parámetro desconocido → se ignora (nunca se
  interpola en SQL).
- `meta: { total, limit, offset }`. Conteo con la misma condición del `WHERE`.

## Escrituras

- **Versión optimista:** `If-Match: "N"` o `expected_version`; si no coincide → 409 sin escribir.
  Toda tabla editable lleva `version` y se incrementa en el mismo `UPDATE`.
- **Idempotencia:** helper que, dentro de la transacción del efecto:
  1. `SELECT` por (`actor_id`, `operation`, `key`) `FOR UPDATE`;
  2. si existe y `request_hash` coincide → devuelve `response_json` guardado;
  3. si existe y difiere → `409 IDEMPOTENCY_KEY_REUSED`;
  4. si no existe → ejecuta, guarda resultado y confirma en la misma transacción.
- **Transacciones:** helper `$db->transaction(fn() => ...)` con `beginTransaction`, `commit`,
  `rollBack` en `Throwable`, y reintento acotado (máx. 3) solo para deadlocks (error 1213/1205).

## Validación

- Lista blanca de campos editables por recurso. Un campo sensible no permitido (`role_id`, `total`,
  `status`) se **rechaza**, no se ignora.
- Tipos, longitudes, formatos y rangos en servidor. El frontend valida por comodidad, no por
  autoridad.
- Importes: string decimal con 2 decimales; se convierten a la representación exacta antes de
  operar. Ver skill `dinero-decimales`.

## Rendimiento

- Sin N+1: las listas se resuelven con `JOIN` o carga por lote con `WHERE id IN (...)`.
- Índices justificados por consulta; toda consulta nueva de listado se revisa con `EXPLAIN` cuando
  la tabla pueda crecer.
- Paginación siempre; nunca `SELECT *` sin `LIMIT` en endpoints de lista.

## Lo que no se hace

- Frameworks, ORM, contenedores mágicos, `eval`, variables variables.
- SQL en controladores o plantillas.
- `catch` que se traga excepciones sin registrar.
- Devolver entidades completas con campos que el solicitante no puede ver.
