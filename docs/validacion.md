# Validación y criterios de aceptación

Evidencia local del 2026-10-27. Fase 1 implementada; **no certificada para despliegue**:
falta inventario real del hosting (`docs/hosting.md`). No se ocultan pruebas fallidas ni skips.

## Resultado ejecutado

| Nivel | Resultado |
|---|---|
| PHPUnit unitarias | **100 pruebas, 436 aserciones, OK** |
| PHPUnit integración MariaDB + HTTP real | **36 pruebas, 346 aserciones, OK** |
| Total PHPUnit | **136 pruebas, 782 aserciones, OK** |
| Chromium headless / CDP nativo | **18 comprobaciones, OK; sin excepciones JS** |
| Sintaxis PHP y módulos ES | `php -l` y `node --check`, correctos |
| Recursos públicos | 15 archivos JS/CSS/SVG con HTTP 200 en servidor local |
| Composer | `validate` y `check-platform-reqs`, correctos |
| OpenAPI 3.1 / Redocly | Válido, sin errores; 5 avisos descritos abajo |

Entorno real: PHP 8.4.27, MariaDB 10.4.32 (no se simula PDO ni InnoDB), Chromium instalado,
Node 24 solo como ejecutor de pruebas de desarrollo. La aplicación no requiere Node en producción.

## Criterio → evidencia

| Criterio §19 | Pruebas | Estado |
|---|---|---|
| A — ADMIN crea los tres roles; sin registro público | `AuthHttpTest`, `UserServiceTest`, `CapabilitiesTest` | Verde para fase 1 |
| A — Cliente BANDA no tiene credenciales | `AuthHttpTest::test_A_client_cannot_login_and_failed_logins_are_rate_limited` | Verde |
| A — Desactivar invalida sesión y tokens | `AuthHttpTest::test_A_cookie_login_csrf_and_deactivation_of_open_session`, `UserServiceTest` | Verde |
| A — Perfil no permite cambiar rol/estado | `AuthHttpTest`, `UserValidationTest` | Verde |
| A — Último ADMIN protegido; cambios de rol auditados | `UserServiceTest`, `RoleTest` | Verde |
| A — Tokens opacos, sin hash en salida y revocables | `AuthHttpTest`, `UserServiceTest` | Verde |
| A — Cambio temporal obligatorio y revocación al cambiar contraseña | `AuthHttpTest` y navegador | Verde |
| A — Datos sensibles y edición por creador de cliente | `ClientsApiTest`, `AuthorizerTest` | Verde |
| A — APIs financieras de proyecto ajeno alterando IDs | Políticas futuras deniegan por defecto (`AuthorizerTest`) | Prueba del endpoint real diferida a fase 2; aún no existe |
| §7 — BANDA, duplicados, versiones, baja lógica y reservas futuras | `ClientValidationTest`, `ClientsApiTest` | Verde |
| H — InnoDB, FK y CHECK aplicadas, migraciones limpias | `SchemaIntegrityTest`, `SchemaTool` | Verde local |
| H — CSRF, origen, rate limit por cuenta/IP no falseable | `AuthHttpTest` | Verde local |
| H — SQL preparado; secretos fuera de respuestas/auditoría | `ClientsApiTest`, `AuditLoggerSanitizeTest`, `UserServiceTest` | Verde local |
| H — Avatar PNG real reprocesado 512×512; SVG/PHP renombrado/>2 MiB rechazados | `AuthHttpTest::test_H_avatar_is_reprocessed_and_rejects_active_or_oversized_content` | Verde HTTP real |
| H/UI — Login, cambio obligatorio, crear líder, clientes, perfil, logout, colaborador | `tests/Browser/smoke.mjs` | Verde Chromium |
| H/UI — XSS literal y perfil sin desbordamiento a 390 px | `tests/Browser/smoke.mjs` | Verde Chromium; no sustituye auditoría completa de accesibilidad |
| B — Proyectos | Fase 2 | No implementado |
| C/D — Impresión, ventas y pagos | Fases 3 y 6 | No implementado |
| E — Reservas/concurrencia | Fase 4 | No implementado |
| F — Reuniones/agenda | Fase 5 | No implementado |
| G — Finanzas | Fase 6 | No implementado |
| H — Apache/HTTPS, restauración y rendimiento con dataset acordado | Hosting / fase 6 | Pendiente con motivo: sin inventario ni dataset acordado |

## Reproducir

Requiere `composer install`, MariaDB activo, `config/config.php` local y permisos para una base
dedicada llamada `gestion_test`. **Las pruebas recrean esa base lógica y truncan sus datos**;
hay comprobaciones para impedir que el ejecutor apunte a otra base. No usan `gestion`.

```powershell
& 'C:\Users\vgmos\dev\tools\php.cmd' vendor\bin\phpunit --testsuite unit
& 'C:\Users\vgmos\dev\tools\php.cmd' vendor\bin\phpunit --testsuite integration
& 'C:\Users\vgmos\dev\tools\php.cmd' vendor\bin\phpunit
& 'C:\Users\vgmos\dev\tools\php.cmd' vendor\bin\phpunit --order-by=random --random-order-seed=20261027
& 'C:\Users\vgmos\dev\tools\composer.cmd' validate --no-check-publish
& 'C:\Users\vgmos\dev\tools\composer.cmd' check-platform-reqs
npx --yes @redocly/cli lint docs/openapi.yaml
```

HTTP: `AuthHttpTest` levanta y cierra su propio PHP `cli-server` en un puerto efímero usando
`tests/Support/http-router.php`. Verifica cookies, cabeceras, multipart y middleware reales.
`InProcessApi` se usa para otros casos de API; no sustituye las comprobaciones de transporte.

Navegador, **secuencialmente después de PHPUnit, nunca en paralelo**, porque reinicia `gestion_test`:

```powershell
$env:PHP_BINARY = 'C:\Users\vgmos\dev\tools\php84\php.exe'
$env:CHROMIUM_BINARY = 'C:\Users\vgmos\AppData\Local\ms-playwright\chromium-1248\chrome-win64\chrome.exe'
node tests\Browser\smoke.mjs
```

El smoke usa CDP y WebSocket nativo de Node, sin Playwright como dependencia ni instalación npm
del proyecto. Chromium debe estar instalado; la ruta es sustituible por variable de entorno.
Usuarios ficticios `@test.local`, cookies en memoria y avatares bajo almacenamiento temporal
dedicado. No se modifican credenciales de desarrollo ni se publican los helpers de pruebas.
Redocly se ejecuta como herramienta externa de desarrollo con `npx`; no añade dependencias al
proyecto ni al hosting.

Se repitió la suite completa en orden aleatorio con semilla `20261027`: mismo resultado,
136 pruebas / 782 aserciones, sin errores, warnings ni deprecaciones PHPUnit.

## Hallazgos resueltos

- Se recuperó la cáscara HTML que el agente había dejado en un archivo de staging y se eliminó
  ese artefacto público. Las vistas ya cargan sus módulos y CSS desde `public/app.html`.
- La prueba de capacidades esperaba erróneamente membresía para LIDER: corregida a proyectos
  propios, según matriz vinculante; documentación alineada, sin ampliar permisos.
- Comparación de tablas independiente de la colación de `information_schema`.
- `canDeactivateClient` ahora se aplica también en el servicio, no solo en el controlador.
- `Request` deja de confiar en `X-Forwarded-For`; regresión probada con seis cuentas e IPs
  declaradas diferentes. La IP del peer sigue limitando los intentos.
- Se valida Origin/Fetch Metadata en escrituras web y se mantiene CSRF.
- Logout no simula éxito ante error de red; vistas con carga tardía no pisan la navegación nueva;
  skip-link enfoca el contenido sin activar una ruta inexistente.
- La interfaz ya no promete cerrar otras sesiones web al cambiar contraseña ni pide motivos
  que el contrato de desactivación no almacena.
- OpenAPI incluye ahora autenticación, perfil, contraseña, avatar y dispositivos, además de
  usuarios y clientes (versión documental 0.3.0, sin cambiar `/api/v1`).

## Limitaciones explícitas

- Fase 1 tiene implementación local verificada, pero no se cierra la validación de hosting.
- El criterio A financiero se completa con proyectos en fase 2, no con un 404 de una ruta vacía.
- No se ha realizado auditoría exhaustiva de lectores de pantalla ni contraste de cada elemento.
- Cinco avisos informativos de Redocly: falta licencia elegida por el propietario; `health` y
  `auth/csrf` no declaran errores 4xx artificiales; componentes `IdempotencyKey` e `IfMatch`
  reservados todavía sin uso. No se inventan respuestas ni licencia para silenciar el linter.
- Configurar proxies de confianza y el esquema HTTPS depende del hosting; no se confía en
  encabezados reenviados arbitrarios. Las sesiones web ajenas no se invalidan al cambiar clave.
