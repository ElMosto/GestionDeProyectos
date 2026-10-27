---
name: Despliegue en hosting compartido
description: Estructura public_html, Apache, vendor, migraciones, respaldo y restauración, cron idempotente y verificación de extensiones para el hosting del estudio.
---

# Despliegue en hosting compartido

## Antes de escribir código (fase 1, bloqueante)

Registrar en `docs/hosting.md` la salida real de:

```bash
php -v
php -m                      # extensiones cargadas
php -i | grep -E 'memory_limit|max_execution_time|upload_max_filesize|post_max_size'
composer check-platform-reqs
mysql --version             # o la del panel
```

Y del panel: `DocumentRoot`, soporte de `.htaccess`, `mod_rewrite`, HTTPS, acceso a cron, cuotas de
disco, SSH y método de respaldo.

Extensiones mínimas: `pdo`, `pdo_mysql`, `mbstring`, `fileinfo`, `session`, `openssl`, `bcmath`,
`zip`, `gd`, `dom`, `xml`, `xmlreader`, `xmlwriter`, `simplexml`, `libxml`, `ctype`, `filter`,
`iconv`, `zlib`.

- Sin `bcmath` → skill `dinero-decimales`, plan de centavos.
- Sin `gd` → PhpSpreadsheet 5.x no instala (D-10): replantear exportación y documentarlo.
- Sin `mod_rewrite` → router por `index.php?r=...`.

## Estructura en el servidor

```
/home/usuario/
├── app/                 # código, config, vendor, storage — FUERA de la raíz web
│   ├── src/ config/ database/ storage/ vendor/ templates/ tests/
└── public_html/         # solo lo público
    ├── index.php        # front controller con rutas a ../app
    ├── .htaccess
    └── assets/
```

- Si el hosting exige `public_html`, se sube el contenido de `public/` ahí y el resto fuera.
- Nunca dejar `config/`, `storage/` ni `vendor/` accesibles por URL. Los nombres de carpeta no
  protegen nada: la protección es la ubicación.
- `storage/` escribible por PHP, con permisos mínimos (`755` directorios, `644` archivos;
  escritura solo donde haga falta).
- `display_errors=0`, `log_errors=1`, errores a `storage/logs/` fuera de la raíz web.

## `.htaccess` mínimo

```apache
Options -Indexes
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]

<IfModule mod_headers.c>
  Header always set X-Content-Type-Options "nosniff"
  Header always set X-Frame-Options "DENY"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
  Header always set Content-Security-Policy "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'"
</IfModule>
```

HTTPS obligatorio; redirección desde HTTP. Cookies `Secure`.

## Dependencias

- `composer install --no-dev --optimize-autoloader` en local, luego subir `vendor/` (si no hay SSH).
- `composer.lock` versionado: la versión que se prueba es la que se despliega.
- Nunca `composer update` en producción.

## Despliegue

1. Respaldo de base y archivos **antes** de tocar nada.
2. Subir código; no sobrescribir `config/config.php` ni `storage/`.
3. Aplicar migraciones pendientes en orden.
4. Verificar escritura en `storage/logs`, `storage/private`.
5. Instalador de un solo uso para crear el primer administrador; luego **desactivarlo** (borrar o
   marcar en `settings`).
6. Verificación funcional: login, crear cliente, crear proyecto, exportar.
7. Registrar versión desplegada y fecha.

## Respaldo y restauración

- Diario: `mysqldump` + archivos privados, cifrado, copia fuera del hosting.
- Retención propuesta 30 días; RPO 24 h, RTO 4 h (sujeto al proveedor).
- **Probar la restauración**, no solo el respaldo. Sin prueba, no hay respaldo.
- Sin cron: los flujos principales funcionan igual; no prometer recordatorios ni respaldos
  automáticos que el panel no ofrece.

## Cron (si existe)

- Tareas idempotentes: pueden ejecutarse dos veces sin duplicar efectos.
- Lock por tabla o archivo para evitar ejecuciones simultáneas.
- Log de ejecución con inicio, fin y resultado; sin datos personales.

## Después de cada fase

`composer check-platform-reqs`, migraciones en base limpia, pruebas, y push a
`https://github.com/ElMosto/GestionDeProyectos`.
