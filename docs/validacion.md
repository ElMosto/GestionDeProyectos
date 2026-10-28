# Validación y criterios de aceptación

Evidencia local acumulada de fases 1–5.
**No certificado para despliegue**:
falta inventario real del hosting (`docs/hosting.md`). No se ocultan pruebas fallidas ni skips.

## Fase 5 — verificada localmente

- Suite completa aleatoria: **353 pruebas / 6.929 aserciones**, sin fallos ni skips.
  `php vendor/bin/phpunit --order-by=random --random-order-seed=20261028`.
- Criterio **F**: `MeetingsApiTest`, `NotificationsApiTest`, `MeetingConcurrencyTest`,
  `BusinessNotificationsTest`, `MeetingAuthorizerTest` y `MeetingPresenterTest`.
  Aceptar/rechazar es individual; reprogramar reinicia todas las invitaciones y rechaza
  versiones antiguas. ADMIN ajeno tampoco puede consultar detalles privados ni avisos ajenos.
- Cinco carreras reales: reuniones simultáneas, replay, reunión/reserva, reunión/mantenimiento
  y movimientos cruzados de espacios. Se verifican locks y resultados, no llamadas secuenciales.
- Avisos de asignación/reasignación y cambios/cancelaciones de reservas integrados en sus
  transacciones; triggers de fallo demuestran rollback de negocio, auditoría e idempotencia.
- `node tests/Browser/phase5.mjs`: **67 comprobaciones PHP/MariaDB/Chromium** de bandeja,
  marcado leído, invitación pendiente, aceptación/rechazo, agenda, reaceptación, privacidad,
  conflicto con reserva, reunión personal breve y cancelación por organizador.
- El primer smoke descubrió una capacidad mal nombrada en UI (`notifications.view` frente a
  `notifications.viewOwn`). Corregidos navegación, inicio y bandeja; smoke y regresión verdes.
- Regresión de navegador completa: **18 + 52 + 56 + 40 + 67 = 233 comprobaciones**.
- Lint: **198 PHP** y **35 módulos JS**, correctos. OpenAPI **0.7.0**, 0 errores y cinco
  advertencias heredadas. Composer audit sin vulnerabilidades; `git diff --check` limpio.
- La interrupción del agente por cuota no se presenta como entrega completa: el integrador
  ejecutó directamente la suite y los flujos reales y revisó el código guardado antes del cierre.
- El sondeo se limita a bandeja abierta/visible y se limpia al salir; no se implementó correo,
  cron ni campana global. Hosting e impresora física continúan pendientes.

## Fase 4 — verificada localmente

- Revisión de esquema: no requiere migración 009; ver sección fase 4 de `docs/esquema.md`.
- Políticas de reservas implementadas; cancelar coordina una venta y queda reservado a ADMIN
  (D-23). Capacidad del líder alineada con esa regla, sin conceder cancelación por UI.
- QA entregó 37 pruebas de espacios/reservas/concurrencia; integración ejecutada y verde.
- Regresiones adicionales: agenda privada incluso para ADMIN y ajuste de reserva cobrada
  restringido a ADMIN cuando cambia el importe (D-15). Mover solo el horario conserva acceso
  del responsable y no cambia los importes.
- Contrato OpenAPI 0.6.0 ampliado aditivamente; `/agenda` conserva tareas y la nueva ruta
  `/agenda/reservations` proyecta solo reservas propias, sin notas ni importes.
- Suite acumulada: **301 pruebas / 5.285 aserciones**, sin fallos ni skips; repetida con
  semillas aleatorias **20261028** y **20261029**. Comando:
  `php vendor/bin/phpunit --order-by=random --random-order-seed=20261028`.
- Criterio **E**: `SpacesApiTest`, `ReservationsApiTest`, `ReservationConcurrencyTest`,
  `ReservationAgendaTest`, `ReservationPaidAdjustmentTest` y políticas por objeto.
  Las tres carreras reales verifican mismo hueco, replay de la misma clave y reserva contra
  mantenimiento con mutex retenido y dos conexiones observadas en MariaDB. Los huecos
  adyacentes y espacios distintos se comprueban además en la suite funcional.
- `node tests/Browser/phase4.mjs`: **40 comprobaciones reales PHP/MariaDB/Chromium**:
  detalle y notas literales, conflicto con mantenimiento, reprogramación libre, agenda propia,
  rangos día/semana, rechazo de ID ajeno, calendario mínimo, móvil, cancelación ADMIN,
  creación manual de espacio y ventana hasta `24:00:00`.
- Regresión secuencial completa de navegador: **18 + 52 + 56 + 40 = 166** comprobaciones.
- Se corrigió el alias de referencia pendiente al limpiar workers y se añadió confirmación
  explícita de recepción del resultado financiero por sockets Windows. El fallo intermitente
  inicial no se ocultó: tras corregirlo, cinco ejecuciones aisladas y dos suites aleatorias verdes.
- Lint: **180 archivos PHP**, **30 módulos JS**, correctos. OpenAPI **0.6.0** válido
  con cinco avisos heredados; Composer audit sin vulnerabilidades; diff sin errores.
- Certificación de hosting e impresora térmica física: pendientes documentados, no inferidos
  de las pruebas locales. Configurar el espacio real, tarifas y horarios mediante ADMIN antes
  de utilizar reservas; no se insertaron valores comerciales ficticios en desarrollo.

## Fase 3 — verificada localmente

- Suite completa aleatoria: **254 pruebas / 4.158 aserciones**, sin fallos ni skips.
  Comando: `php vendor/bin/phpunit --order-by=random --random-order-seed=20261028`.
- `CatalogApiTest`, `SalesApiTest`, `PaymentsApiTest`, `PaymentsContractTest` y
  `FinancialConcurrencyTest` cubren §19.D: fórmula 120.00, redondeo por partida,
  snapshots al emitir, PARCIAL/PAGADO, SIN_CARGO sin asiento, permisos e idempotencia.
- Tres carreras con procesos PHP y conexiones MariaDB independientes verifican cobros
  600+600 sobre 1000, replay simultáneo y reembolsos limitados. La barrera mantiene
  el documento bloqueado hasta observar ambos workers ejecutando SQL. El monitor admite
  tanto `Query` como `Execute` (consultas preparadas); no es una prueba secuencial simulada.
- Versiones de documentos/pagos/reembolsos normalizadas a strings, con regresión de contrato.
- **56 comprobaciones de navegador** en `node tests/Browser/phase3.mjs`: catálogo y extras,
  venta de 120.00, emisión sin cobro, abono y saldo, marcar pagada, snapshot histórico,
  acceso ajeno rechazado, devolución/anulación ADMIN y proyecto finalizado todavía cobrable.
- Ticket autorizado coincide con API, aviso fiscal presente y reimpresión sin movimientos;
  texto largo conserva última partida en 80 mm/A4, sin desbordamiento horizontal en medio
  de impresión; PDF de prueba de Chromium válido y A4 multipágina. Artefactos de diagnóstico
  fuera del repositorio: `%TEMP%/opencode/gestion-ticket-phase3-{80mm,A4}.pdf`.
  **No se probó impresora térmica física**: esa verificación queda para aceptación en el estudio.
- Regresión de navegador: fase 1 **18**, fase 2 **52**, ambas verdes.
- Lint: **151 PHP**, **25 módulos JS**, correctos; `git diff --check` limpio.
- OpenAPI **0.5.0** válido con los cinco avisos ya documentados. Cobertura automática
  contrasta todas las operaciones registradas, incluidos catálogos con rutas literales.
- Composer audit: sin avisos de vulnerabilidades. Hosting continúa sin certificar.

## Fase 2 — verificada localmente (línea base)

- Instalación limpia 001–007 y seeds: correcta en base desechable independiente; 34 tablas
  InnoDB, columna `tasks.is_required` con CHECK y valor predeterminado 1.
- Políticas por objeto de proyectos/tareas y pruebas unitarias actualizadas: correctas.
- QA añadió pruebas reales de proyectos, tareas, cargos, fechas, roles, idempotencia y agenda.
  Integración ejecutada contra MariaDB real, sin pruebas omitidas ni fallos.
- Pruebas adicionales de integración: rollback ante fallo de auditoría, importe no inferior
  a lo cobrado y reautorización de snapshots idempotentes tras transferir liderazgo.
- Interfaz y OpenAPI 0.4.0 integrados; **52 comprobaciones en Chromium contra PHP/MariaDB**:
  líder crea proyecto/cargo/tarea; miembro ve sin finanzas, actualiza solo su avance y consulta
  agenda; miembro no asignado comenta sin editar avance; ajeno es rechazado; ADMIN crea
  independiente para líder y aparece en su agenda. XSS literal y vista móvil verificados.
- Hallazgos resueltos: PATCH operativo sin clave obligatoria; reautorización transaccional
  antes de devolver replay financiero; formularios normalizan opcionales vacíos a null;
  prueba de seeds admite contadores anuales; miembros pueden comentar sin gestionar avance.
- `composer audit --locked --no-interaction`: sin avisos de vulnerabilidades.

| Nivel acumulado | Resultado actual |
|---|---|
| Unitarias | **129 pruebas / 700 aserciones**, OK |
| Integración y HTTP real | **74 pruebas / 734 aserciones**, OK |
| Total | **203 pruebas / 1.434 aserciones**, OK |
| Navegador fase 1 | **18 comprobaciones**, OK |
| Navegador fase 2 | **52 comprobaciones**, OK |
| OpenAPI 0.4.0 | Válido; mismos cinco avisos informativos de la línea base |

### Criterios cubiertos al cerrar fase 2

- **A:** aislamiento financiero contra `GET /projects/{id}/payments` real; miembro no recibe
  importes y líder ajeno no puede reutilizar snapshots históricos tras transferir liderazgo.
- **B:** `ProjectsApiTest`, `TasksApiTest`, `ProjectChargesApiTest`, `ProjectIntegrityTest`,
  `AuthorizerTest`, `MoneyTest`, `ProjectValidationTest` y `tests/Browser/phase2.mjs`.
  Cubren validación externa/interna, asignación, presupuesto protegido, fechas, avance,
  tareas independientes, cargos sin cobros y gastos sin modificar precio comercial.
- No cobrable devuelve saldo `0.00` y rótulo de valor interno estimado; el dashboard de
  cuentas por cobrar aún no existe y se probará globalmente con los criterios G en fase 6.
- **H parcial:** rollback real ante fallo de auditoría, idempotencia y cobertura automática
  de todas las rutas registradas en OpenAPI (`ApiContractCoverageTest`).

Reproducir navegador de fase 2 con las mismas variables del smoke de fase 1:

```powershell
node tests\Browser\phase2.mjs
```

También reinicia únicamente `gestion_test`: ejecutar secuencialmente, no junto con PHPUnit.

## Resultado ejecutado de fase 1 (línea base)

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
| A — APIs financieras de proyecto ajeno alterando IDs | `ProjectsApiTest`, `ProjectIntegrityTest` | Verde fase 2, endpoint real |
| §7 — BANDA, duplicados, versiones, baja lógica y reservas futuras | `ClientValidationTest`, `ClientsApiTest` | Verde |
| H — InnoDB, FK y CHECK aplicadas, migraciones limpias | `SchemaIntegrityTest`, `SchemaTool` | Verde local |
| H — CSRF, origen, rate limit por cuenta/IP no falseable | `AuthHttpTest` | Verde local |
| H — SQL preparado; secretos fuera de respuestas/auditoría | `ClientsApiTest`, `AuditLoggerSanitizeTest`, `UserServiceTest` | Verde local |
| H — Avatar PNG real reprocesado 512×512; SVG/PHP renombrado/>2 MiB rechazados | `AuthHttpTest::test_H_avatar_is_reprocessed_and_rejects_active_or_oversized_content` | Verde HTTP real |
| H/UI — Login, cambio obligatorio, crear líder, clientes, perfil, logout, colaborador | `tests/Browser/smoke.mjs` | Verde Chromium |
| H/UI — XSS literal y perfil sin desbordamiento a 390 px | `tests/Browser/smoke.mjs` | Verde Chromium; no sustituye auditoría completa de accesibilidad |
| B — Proyectos | Suites de fase 2 descritas arriba | Verde local; agregados globales se completan en fase 6 |
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
- El criterio A financiero se verificó en fase 2 contra rutas reales, no con un 404 de ruta vacía.
- No se ha realizado auditoría exhaustiva de lectores de pantalla ni contraste de cada elemento.
- Cinco avisos informativos de Redocly: falta licencia elegida por el propietario; `health` y
  `auth/csrf` no declaran errores 4xx artificiales; componentes `IdempotencyKey` e `IfMatch`
  reservados todavía sin uso. No se inventan respuestas ni licencia para silenciar el linter.
- Configurar proxies de confianza y el esquema HTTPS depende del hosting; no se confía en
  encabezados reenviados arbitrarios. Las sesiones web ajenas no se invalidan al cambiar clave.
