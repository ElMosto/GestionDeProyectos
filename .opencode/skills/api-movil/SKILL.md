---
name: Contrato de API para Android
description: Reglas de compatibilidad de /api/v1, autenticación por token opaco, tipos JSON, idempotencia y checklist para que la futura app Android no obligue a refactorizar el backend.
---

# Contrato de API para Android

Documento completo y vinculante: `docs/api-movil.md`. Esta skill es el resumen operativo.

## Lo que no se negocia

1. `/api/v1` es un **contrato**: no cambia forma ni significado dentro de la versión.
2. **Tipos:** importes y cantidades como **string**; IDs como **string**; fechas ISO 8601 con zona;
   enums en `MAYÚSCULAS_SNAKE`.
3. **Errores:** `code` estable en inglés (para ramificar) + `message` en español (para el usuario).
4. **Idempotencia obligatoria** en ventas, pagos, reembolsos y reservas.
5. **Paginación** con `limit` (máx. 100) + `offset`, y filtros con lista blanca.
6. **Sin HTML de negocio.** Solo `/ticket` devuelve HTML de impresión.

## Autenticación (D-08)

| Cliente | Mecanismo |
|---|---|
| Web | Sesión + cookie `Secure; HttpOnly; SameSite=Lax` + CSRF |
| Android | `Authorization: Bearer <token opaco>` |

- Token de 32 bytes `base64url`, guardado **hasheado** (SHA-256) en `api_tokens`.
- Se muestra una sola vez al crearlo; revocable por dispositivo.
- Al desactivar la cuenta o cambiar contraseña → revocación inmediata.
- Con Bearer no se exige CSRF; con cookie sí, sin excepciones.
- **No JWT.** Nada de permisos embebidos en el token.

## Checklist para cada endpoint nuevo

- [ ] Documentado en `docs/openapi.yaml` (permisos, ejemplos, errores, paginación).
- [ ] Funciona sin cookie (con Bearer) y sin Bearer (con cookie), si aplica a ambos.
- [ ] Autorización por rol y por objeto probada con IDs ajenos.
- [ ] Campos sensibles filtrados también en la respuesta del token móvil.
- [ ] Sin estado de UI en el servidor (no "pantalla actual", no sesión navegacional).
- [ ] Sin supuestos de navegador: nada de redirecciones 302 a HTML en rutas de API.
- [ ] Si crea dinero o reserva espacio: `Idempotency-Key` + transacción.
- [ ] Si edita algo versionado: `If-Match`/`expected_version` y `409 VERSION_CONFLICT`.
- [ ] Tiempo de respuesta acotado; listas siempre paginadas.

## Capacidades

`GET /auth/me` incluye `capabilities` (mapa plano de acción → boolean) para que la app oculte UI.
El backend **reautoriza** siempre; `capabilities` no es un permiso.

## Evolución

- Añadir campos: opcionales, compatibles.
- Quitar o cambiar tipo: prohibido en `v1`; requiere `/api/v2`.
- Deprecación: marcada en OpenAPI y mantenida al menos una versión menor.
- `X-Client` y `X-Client-Version` para diagnóstico; `426 UPGRADE_REQUIRED` si hay que forzar
  actualización.

## Errores comunes que rompen un cliente móvil

- Devolver `302` a login en vez de `401`.
- Exigir CSRF a peticiones con Bearer.
- Importes como número (`150.0` en lugar de `"150.00"`).
- IDs numéricos que exceden el entero seguro de JavaScript.
- Endpoints de lista sin `LIMIT`.
- Reglas en JavaScript del frontend que el móvil no puede replicar.
