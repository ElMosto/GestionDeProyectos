# Fases de implementación

Cada fase termina con: pruebas en verde, `docs/fases.md` actualizado, commit y `git push` a
`main`. Una fase no se cierra con criterios de aceptación pendientes sin documentar.

| Fase | Alcance | Agentes principales | Estado |
|---|---|---|---|
| 0 | Esqueleto, contratos de trabajo, agentes y skills | — | **Completada** |
| 1 | Inventario de hosting, esquema, autenticación, roles, perfiles, clientes | arquitecto-datos, seguridad-permisos, backend-php, frontend-web, qa-aceptacion | **Implementada y verificada localmente; hosting pendiente** |
| 2 | Proyectos, etapas, tareas, membresías, tareas independientes | arquitecto-datos, backend-php, frontend-web, qa-aceptacion | Pendiente |
| 3 | Productos, extras, ventas, cobros, tickets de venta | backend-php, documentos-salida, frontend-web, qa-aceptacion | Pendiente |
| 4 | Espacios, reservas transaccionales, agenda | arquitecto-datos, backend-php, frontend-web, qa-aceptacion | Pendiente |
| 5 | Reuniones, invitaciones, notificaciones | backend-php, frontend-web, qa-aceptacion | Pendiente |
| 6 | Dashboard, gastos, Gantt XLSX, auditoría, pruebas, despliegue | backend-php, documentos-salida, seguridad-permisos, qa-aceptacion | Pendiente |
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

## Riesgos conocidos por fase

- **Fase 1:** extensiones del hosting (sobre todo `gd` y `bcmath`); `DocumentRoot` real.
- **Fase 3:** doble cobro por reintentos; tratamiento de anulaciones en el dashboard.
- **Fase 4:** interbloqueos al cambiar de espacio; estados bloqueantes mal definidos.
- **Fase 6:** límites de Excel en rangos largos; tiempo de ejecución del export.
