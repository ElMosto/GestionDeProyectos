# Inventario del hosting

> **Bloqueante de la fase 1.** Sin esta tabla completa no se fija la versión de dependencias ni se
> garantiza el despliegue. Rellenar con la salida real, no con la documentación del proveedor.

Proveedor: _pendiente_
Panel: _pendiente_
Fecha del inventario: _pendiente_

## PHP

| Dato | Valor real | Requerido | Estado |
|---|---|---|---|
| Versión (`php -v`) | | 8.4.x | |
| SAPI | | Apache module / FPM | |
| `memory_limit` | | ≥ 256M para exportaciones | |
| `max_execution_time` | | ≥ 60s para XLSX | |
| `upload_max_filesize` | | ≥ 2M para avatar | |
| `post_max_size` | | ≥ 4M | |
| `display_errors` | | Off | |
| Zona horaria | | UTC | |

## Extensiones

| Extensión | Requerida por | Presente |
|---|---|---|
| pdo, pdo_mysql | Backend | |
| mbstring, fileinfo, iconv, ctype, filter | General | |
| session, openssl | Autenticación | |
| bcmath | Dinero exacto (D-10) | |
| zip, zlib | PhpSpreadsheet | |
| gd | PhpSpreadsheet 5.x (**crítica**) | |
| dom, xml, xmlreader, xmlwriter, simplexml, libxml | PhpSpreadsheet | |

Salida de `composer check-platform-reqs`: _pendiente_

## MariaDB

| Dato | Valor real |
|---|---|
| Versión | |
| ¿Soporta `CHECK` aplicado? | |
| ¿`utf8mb4` por defecto? | |
| Límite de conexiones | |
| Tamaño máximo de base | |
| ¿Usuario con privilegios mínimos? | |

## Apache / red

| Dato | Valor real |
|---|---|
| Versión de Apache | |
| `DocumentRoot` | |
| `.htaccess` permitido | |
| `mod_rewrite` | |
| `mod_headers` | |
| HTTPS disponible | |
| ¿HTTPS forzado? | |
| Ruta absoluta del home | |

## Operación

| Dato | Valor real |
|---|---|
| Acceso SSH | |
| Cron disponible | |
| Cuota de disco | |
| Método de respaldo del panel | |
| ¿Respaldo fuera del hosting? | |
| Retención | |
| ¿Restauración probada? | |

## Consecuencias

- Si falta **bcmath** → skill `dinero-decimales`, modo centavos enteros.
- Si falta **gd** → PhpSpreadsheet 5.x no instala → revisar D-10 en `docs/decisiones.md`.
- Si no hay **mod_rewrite** → router por `index.php?r=...` (ya soportado).
- Si no hay **SSH** → subir `vendor/` generado en local.
- Si no hay **cron** → no prometer recordatorios ni respaldos automáticos.
