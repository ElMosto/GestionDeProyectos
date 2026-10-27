# Fases de implementación

Cada fase termina con: pruebas en verde, `docs/fases.md` actualizado, commit y `git push` a
`main`. Una fase no se cierra con criterios de aceptación pendientes sin documentar.

| Fase | Alcance | Agentes principales | Estado |
|---|---|---|---|
| 0 | Esqueleto, contratos de trabajo, agentes y skills | — | **Completada** |
| 1 | Inventario de hosting, esquema, autenticación, roles, perfiles, clientes | arquitecto-datos, seguridad-permisos, backend-php, qa-aceptacion | Pendiente |
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
- [x] 11 skills en `.opencode/skills/`.
- [x] Especificación versionada en `docs/requerimiento-v1.0.md`.
- [x] Registro de decisiones `docs/decisiones.md`.
- [x] Contrato móvil `docs/api-movil.md`.
- [x] `composer.json`, `.htaccess`, front controller, configuración de ejemplo.
- [x] Carpetas de la arquitectura con `.gitkeep`.
- [x] Repositorio git inicializado y publicado.

No incluye: esquema SQL, autenticación ni ninguna funcionalidad de negocio.

## Fase 1 — Base (siguiente)

Entregables:
1. **Inventario de hosting** documentado en `docs/hosting.md` (bloquea el resto).
2. Esquema completo en `database/migrations/` con FK, CHECK e índices del §14.
3. `database/seeds/` con roles y un administrador inicial por instalador de un solo uso.
4. Sesión, CSRF, rate limit de login, `must_change_password`.
5. Autenticación por token opaco para móvil (`api_tokens`), decisión D-08.
6. CRUD de usuarios (solo admin) y de clientes con las reglas del §7.
7. Perfil propio: datos, contraseña, avatar.
8. OpenAPI 3.1 inicial en `docs/openapi.yaml`.
9. Pruebas: criterio **A** del §19 completo.

Definición de terminado: se puede iniciar sesión, crear/desactivar usuarios, editar el perfil,
crear y consultar clientes, y un colaborador no accede a rutas de administrador ni cambiando IDs.

## Riesgos conocidos por fase

- **Fase 1:** extensiones del hosting (sobre todo `gd` y `bcmath`); `DocumentRoot` real.
- **Fase 3:** doble cobro por reintentos; tratamiento de anulaciones en el dashboard.
- **Fase 4:** interbloqueos al cambiar de espacio; estados bloqueantes mal definidos.
- **Fase 6:** límites de Excel en rangos largos; tiempo de ejecución del export.
