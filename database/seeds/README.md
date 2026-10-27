# Semillas de base de datos

Dos categorías **separadas** que nunca se mezclan en producción:

| Carpeta | Contenido | ¿Se aplica en producción? |
|---|---|---|
| `seeds/00X_*.sql` | Datos iniciales mínimos: roles, settings, secuencias. Sin usuarios ni contraseñas. | Sí, una vez tras las migraciones. |
| `seeds/demo/9XX_demo_*.sql` | Dataset de demostración, claramente marcado y con prefijo `demo-`. | Nunca. Solo entornos de desarrollo. |

## Orden de aplicación

1. `database/migrations/001_core_auth.sql`
2. `database/migrations/002_clients_projects.sql`
3. `database/migrations/003_catalog_sales.sql`
4. `database/migrations/004_spaces_reservations.sql`
5. `database/migrations/005_meetings_notifications.sql`
6. `database/migrations/006_finance.sql`
7. `database/seeds/001_roles.sql`
8. `database/seeds/002_settings.sql`
9. `database/seeds/003_sequences.sql`

## Reglas

- Las semillas son **idempotentes** (`ON DUPLICATE KEY UPDATE` sin sobrescribir datos
  editables) porque el ejecutor puede repetirlas al desplegar.
- Ningún seed contiene contraseñas, hashes ni tokens. El primer administrador se crea con el
  instalador de un solo uso del §18, que se desactiva después.
- El dataset de demostración lleva el prefijo `demo-` en folios y correos, y valores
  reconocibles, para poder purgarlo sin tocar datos reales.
- Los seeds no crean espacio, tarifa ni política de cancelación: son datos del estudio y no se
  inventan (§21 punto 4).
