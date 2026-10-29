# Fases de implementación

Cada fase termina con: pruebas en verde, `docs/fases.md` actualizado, commit y `git push` a
`main`. Una fase no se cierra con criterios de aceptación pendientes sin documentar.

| Fase | Alcance | Agentes principales | Estado |
|---|---|---|---|
| 0 | Esqueleto, contratos de trabajo, agentes y skills | — | **Completada** |
| 1 | Inventario de hosting, esquema, autenticación, roles, perfiles, clientes | arquitecto-datos, seguridad-permisos, backend-php, frontend-web, qa-aceptacion | **Implementada y verificada localmente; hosting pendiente** |
| 2 | Proyectos, etapas, tareas, membresías, tareas independientes | arquitecto-datos, backend-php, frontend-web, qa-aceptacion | **Completada localmente** |
| 3 | Productos, extras, ventas, cobros, tickets de venta | backend-php, documentos-salida, frontend-web, qa-aceptacion | **Completada localmente** |
| 4 | Espacios, reservas transaccionales, agenda | arquitecto-datos, backend-php, frontend-web, qa-aceptacion | **Completada localmente** |
| 5 | Reuniones, invitaciones, notificaciones | backend-php, frontend-web, qa-aceptacion | **Completada localmente** |
| 6 | Dashboard, gastos, Gantt XLSX, auditoría, pruebas, despliegue | backend-php, documentos-salida, seguridad-permisos, qa-aceptacion | **En curso; certificación de hosting bloqueada** |
| 7 | App Android (fuera del alcance inicial; contrato ya preparado) | movil-android | Futuro |

## Fase 0 — Esqueleto (completada)

- [x] `AGENTS.md` con stack, invariantes y flujo de trabajo.
- [x] `opencode.jsonc` con permisos de edición.
- [x] 7 agentes en `.opencode/agents/`.
- [x] 12 skills en `.opencode/skills/`.
- [x] Especificación versionada en `docs/requerimiento-v1.0.md`.
- [x] Registro de decisiones `docs/decisiones.md`.
- [x] Contrato móvil `docs/api-movil.md`.
- [x] `composer.json`, `.htaccess`, front controller, configuración de ejemplo.
- [x] Carpetas de la arquitectura con `.gitkeep`.
- [x] Repositorio git inicializado y publicado.

No incluye: esquema SQL, autenticación ni ninguna funcionalidad de negocio.

## Fase 1 — Base (verificada localmente; cierre de hosting pendiente)

Entregables:
1. **Inventario de hosting** documentado en `docs/hosting.md` (bloqueado: faltan datos reales del hosting).
2. Esquema completo en `database/migrations/` con FK, CHECK e índices del §14. **Hecho** (6 migraciones, 34 tablas, verificado desde cero en `gestion_test`).
3. `database/seeds/` con roles y un administrador inicial por instalador de un solo uso. **Hecho** (`bin/setup.php` se niega a repetirse).
4. Sesión, CSRF, rate limit de login, `must_change_password`. **Hecho** (ver `docs/seguridad.md`).
5. Autenticación por token opaco para móvil (`api_tokens`), decisión D-08. **Hecho** (hash SHA-256, revocación por dispositivo).
6. CRUD de usuarios (solo admin) y de clientes con las reglas del §7. **Hecho**; políticas por objeto en `src/Policies/Authorizer.php`.
7. Perfil propio: datos, contraseña, avatar. **Hecho** (avatar reprocesado con GD).
8. OpenAPI 3.1 inicial en `docs/openapi.yaml`. **Hecho** (usuarios, clientes y autenticación).
9. Pruebas del alcance de fase 1 del criterio **A** y seguridad **H**: **136 pruebas / 782 aserciones en verde**, más **18 comprobaciones de navegador**. La prueba financiera de proyecto ajeno del criterio A se completa en fase 2, cuando exista ese endpoint. Evidencia y comandos en `docs/validacion.md`.

Interfaz web: login, inicio, mi usuario, usuarios y clientes en `public/app.html` + módulos ES.

Verificación local: PHP 8.4.27, MariaDB 10.4.32 real, Chromium headless.
El inventario del hosting sigue bloqueando la certificación de despliegue; **no** se ha
probado Apache/HTTPS ni se presenta el entorno local como equivalente certificado del hosting.
No se añadieron dependencias de producción para las pruebas de navegador: usan CDP nativo
con un Chromium instalado y Node exclusivamente en desarrollo.

Definición de terminado: se puede iniciar sesión, crear/desactivar usuarios, editar el perfil,
crear y consultar clientes, y un colaborador no accede a rutas de administrador ni cambiando IDs.

## Fase 2 — Proyectos y tareas (completada localmente, 2026-10-28)

- [x] Migración 007: tareas obligatorias, CHECK y default conservador (D-14).
- [x] Proyectos INTERNO/EXTERNO, folios seguros y edición versionada.
- [x] Liderazgo y membresías: visibilidad filtrada en SQL y finanzas podadas en servidor.
- [x] Etapas ordenables, tareas con responsable colaborador miembro y fechas anidadas.
- [x] Avance operativo calculado, comentarios, historial y transiciones auditadas.
- [x] Tareas independientes ADMIN para cualquier rol y proyección de agenda propia.
- [x] Cargos internos exactos, separados de pagos y gastos; ajustes bajo neto rechazados.
- [x] Reautorización antes de devolver snapshots idempotentes y rollback si falla auditoría.
- [x] UI con proyectos, detalle, integrantes, tareas y agenda; 25 operaciones OpenAPI nuevas.
- [x] Suite acumulada: **203 pruebas / 1.434 aserciones**; navegador real fase 2:
  **52 comprobaciones**, más **18** de regresión de fase 1. Ver `docs/validacion.md`.

La prueba A de acceso financiero a proyecto ajeno ya se ejecuta contra el endpoint real.
La agenda de esta fase proyecta solo tareas; reservas/reuniones se añadirán en sus fases.
No se han implementado pagos de escritura, gastos ni dashboard por adelantado.
El hosting conserva su bloqueo de certificación, separado del desarrollo local autorizado.

## Fase 3 — Ventas, cobros y tickets (completada localmente)

- [x] Migración 008: cancelar borradores sin inventar fecha de emisión.
- [x] Catálogos administrables, extras permitidos y conceptos libres solo ADMIN.
- [x] Borradores recalculados, snapshots definitivos al emitir y partidas exactas.
- [x] Cobros de ventas/proyectos, atajo de saldo, anulaciones y reembolsos auditados.
- [x] Idempotencia y reautorización; carreras reales sin sobrepago ni exceso de devolución.
- [x] Tickets HTML autorizados 80 mm/A4, escape, historial y aviso no fiscal.
- [x] UI y OpenAPI 0.5.0; **254 pruebas / 4.158 aserciones**, todas verdes.
- [x] Navegador: **56** comprobaciones de fase 3, más **18 + 52** de regresión.

Impresión comprobada con el motor de Chromium, incluidos texto largo y A4 multipágina.
Queda pendiente una impresora térmica física, sin afirmar esa certificación ni la del hosting.
Dashboard, gastos, reservas y XLSX siguen en sus fases correspondientes.

## Fase 4 — Espacios y reservas (completada localmente)

- [x] Revisión del esquema existente: sin migración adicional necesaria.
- [x] Configuración ADMIN de espacios, tarifas, granularidad y horarios locales completos.
- [x] Ocupación mínima compartida sin bandas, notas, responsables ni importes.
- [x] Reserva BANDA con venta RESERVA única y snapshot exacto, sin pago automático.
- [x] Mutex por espacio, IDs ordenados al cambiar sala, intervalos semiabiertos.
- [x] Reprogramación, estados y mantenimiento transaccionales, versionados e idempotentes.
- [x] Cancelación ADMIN coordinada sin borrar historial; ajustes con cobros según D-15.
- [x] Agenda de reservas propias aditiva, con rangos diario/semanal/mensual; tareas intactas.
- [x] Ticket de venta con anexo de reserva autorizado; dependencias por constructor.
- [x] OpenAPI 0.6.0, **301 pruebas / 5.285 aserciones** en dos órdenes aleatorios.
- [x] **40 comprobaciones de navegador** propias; acumulado de fases 1–4: **166**.

El primer espacio real y sus horarios/tarifa se configuran por ADMIN; no se inventan datos
operativos en seeds. Hosting e impresora física conservan las limitaciones anteriores.
Reuniones/notificaciones y dashboard se implementarán en las fases siguientes.

## Fase 5 — Reuniones y notificaciones (completada localmente)

- [x] Esquema revisado sin migración nueva; invitados fijados al crear, D-24.
- [x] Solicitudes privadas, respuestas individuales y versiones de invitación string.
- [x] Cambio de horario restablece todas las invitaciones; versiones antiguas rechazadas.
- [x] Ocupación de reunión coordinada con reservas/mantenimiento, sin venta ni pago.
- [x] Advertencias de cruce personal sin revelar otras actividades; no omiten mutex de espacio.
- [x] Agenda aditiva de reuniones organizadas/aceptadas, privada incluso para ADMIN.
- [x] Notificaciones persistidas propias, leído idempotente y payload genérico regenerado.
- [x] Avisos de tareas/reservas en su misma transacción; fallo de aviso revierte el negocio.
- [x] Bandeja con sondeo visible cada minuto y limpieza al salir; OpenAPI 0.7.0.
- [x] **353 pruebas / 6.929 aserciones**, más **67 comprobaciones** de navegador nuevas.
  Regresión acumulada: **233 comprobaciones** de navegador de fases 1–5.

Hosting sigue pendiente; no se añadieron correo, cron ni servicios externos.

## Riesgos conocidos por fase

- **Fase 1:** extensiones del hosting (sobre todo `gd` y `bcmath`); `DocumentRoot` real.
- **Fase 3:** doble cobro por reintentos; tratamiento de anulaciones en el dashboard.
- **Fase 4:** interbloqueos al cambiar de espacio; estados bloqueantes mal definidos.
- **Fase 6:** límites de Excel en rangos largos; tiempo de ejecución del export.
