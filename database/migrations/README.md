# Migraciones de base de datos

Esquema MariaDB del Sistema de Gestión para Estudio Musical. Fuente normativa:
`docs/requerimiento-v1.0.md` §14 y la skill `esquema-mariadb`. El *porqué* de cada decisión está en
`docs/esquema.md`.

## Orden obligatorio

| # | Archivo | Crea |
|---|---|---|
| 001 | `001_core_auth.sql` | `schema_migrations`, `roles`, `users`, `api_tokens`, `password_resets`, `login_attempts`, `audit_logs`, `settings`, `sequences`, `idempotency_requests` |
| 002 | `002_clients_projects.sql` | `clients`, `projects`, `project_members`, `project_stages`, `tasks`, `task_comments`, `project_charges` |
| 003 | `003_catalog_sales.sql` | `products`, `extras`, `product_extras`, `sales`, `sale_items`, `sale_item_extras` |
| 004 | `004_spaces_reservations.sql` | `spaces`, `space_hours`, `space_occupancies`, `reservations`, `space_blocks` |
| 005 | `005_meetings_notifications.sql` | `meetings`, `meeting_invitations`, `notifications` |
| 006 | `006_finance.sql` | `payments`, `refunds`, `expenses` |

Después, en este orden: `database/seeds/001_roles.sql`, `002_settings.sql`, `003_sequences.sql`.

Las migraciones son **correlativas e inmutables**: una vez aplicadas en un entorno con datos no se
editan ni se renumeran. Cualquier corrección es un archivo nuevo `007_...sql`. Los archivos son
idempotentes en el sentido de `CREATE TABLE IF NOT EXISTS`, pero el control real lo lleva
`schema_migrations` (el ejecutor inserta `version`, `filename`, `checksum` y `applied_at`; si el
`checksum` no coincide con el archivo, debe abortar: alguien editó una migración aplicada).

## Cómo aplicar

Requisitos: cliente `mysql` y una base vacía con `utf8mb4_unicode_ci`.

```powershell
$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$db = 'gestion'   # en desarrollo/pruebas: gestion_test

& $mysql -h 127.0.0.1 -u gestion -p gestion $db -e "source database/migrations/001_core_auth.sql"
# ... 002 a 006, y luego los seeds 001..003
```

Unix:

```bash
mysql -h 127.0.0.1 -u gestion -p gestion < database/migrations/001_core_auth.sql
```

En producción se aplican **con respaldo previo** y con la aplicación en mantenimiento. El ejecutor
de migraciones del backend (§18) debe:

1. Leer `schema_migrations` y aplicar solo las versiones pendientes, en orden ascendente.
2. Ejecutar cada archivo en su propia transacción cuando el motor lo permita (aquí todo es DDL
   InnoDB; MariaDB no hace DDL transaccional, así que **el respaldo es la red de seguridad**).
3. Registrar la fila en `schema_migrations` al terminar.

### Configuración de sesión obligatoria

La base guarda fechas en UTC y necesita modo estricto para que los `ENUM`/`DATE`/`VARCHAR` no se
trunquen en silencio. **El servidor de este entorno arranca en modo no estricto**:

```
sql_mode = NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION
```

Con ese modo, `INSERT` con un `ENUM` inválido guarda `''` y una fecha imposible guarda
`0000-00-00` **sin error** (verificado en 10.4.32). Por eso la conexión de la aplicación y la de
las migraciones debe fijar:

```sql
SET SESSION time_zone = '+00:00';
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
```

No se puede fijar desde una migración de forma permanente por base (sería `GLOBAL`, requiere
privilegios y afecta a otros esquemas). Es una obligación del backend y del panel del hosting.

## Verificación tras aplicar

```sql
-- Debe devolver 0 filas
SELECT TABLE_NAME FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND ENGINE <> 'InnoDB';

-- Todo utf8mb4_unicode_ci
SELECT TABLE_COLLATION, COUNT(*) FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() GROUP BY TABLE_COLLATION;

-- Restricciones CHECK nombradas
SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
 WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY TABLE_NAME;
```

En una base limpia (10.4.32) el resultado esperado es: **34 tablas**, 0 no-InnoDB,
34 en `utf8mb4_unicode_ci`, **63 claves foráneas** y **39 `CHECK` explícitos** (más los 7 que
MariaDB crea para las columnas `JSON`).

## Folios por secuencia (nunca `MAX(id)+1`)

```sql
-- Dentro de la transacción que crea el documento:
UPDATE sequences
   SET last_used  = LAST_INSERT_ID(next_value),
       next_value = next_value + 1
 WHERE name = 'project';

SELECT LAST_INSERT_ID();          -- p. ej. 123
-- Folio = prefix + '-' + año(UTC) + '-' + LPAD(numero, 6, '0')
```

MariaDB evalúa las asignaciones de un `UPDATE` de izquierda a derecha, así que `last_used` recibe
el valor **anterior** al incremento: el primer folio es `000001` y no se salta ninguno. El contador
es único por tipo de documento y **no se reinicia cada año**; el año solo forma parte del texto.

## Reversión

Cada archivo termina con un bloque `DOWN` comentado con los `DROP TABLE` en orden inverso. No se
ejecuta automáticamente. Antes de revertir: respaldo completo, ventana de mantenimiento y
verificación de que no existen datos que solo viven en ese esquema. Revertir en producción sin
respaldo es una pérdida de datos garantizada porque el DDL no es transaccional.

## Lo que la base NO verifica

Resumen (detalle en `docs/esquema.md`):

- Solapamiento real de `space_occupancies`: exige transacción + `SELECT ... FOR UPDATE` sobre
  `spaces`. La BD solo impide el intervalo **exactamente** duplicado de una ocupación bloqueante.
- Sobrepago, exceso de reembolso y edición de importes por debajo de lo cobrado (sumas multi-fila).
- Último administrador activo, permisos por rol/objeto, transiciones de estado permitidas.
- `reservations.band_client_id` debe apuntar a un cliente `BANDA` (la FK no puede mirar otra tabla).
- Fechas de etapas/tareas dentro del proyecto/etapa (comparación entre tablas).
- Coherencia de `line_total` con la fórmula de §10 (depende de las filas de extras).
- Que el texto de `audit_logs.before_json`/`after_json` no contenga secretos.
- Cifrado en reposo: el hosting debe aportar disco cifrado y respaldos cifrados (§18).
