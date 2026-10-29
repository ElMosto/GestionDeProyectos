# Instalación y actualización

Guía de §18, §19.H y §20. **No certifica producción**: proveedor, Apache, certificado,
cuotas, cron y recursos reales siguen sin inventariar en [hosting.md](hosting.md).
La evidencia local está en [operacion-local.md](operacion-local.md).

## 1. Inventario previo

En una terminal privada, desde la raíz del proyecto:

```sh
php -v
php bin/check-hosting.php --json
composer check-platform-reqs --no-dev
mysql --version
```

El preflight es CLI exclusivo; por HTTP termina con 404, sin diagnóstico. No cargarlo ni
copiarlo a `public/`. Solo lee el manifiesto público y metadatos del entorno: no ejecuta
`config/config.php`, no imprime credenciales, no conecta a BD y no prueba escritura creando
archivos. Código 0 significa requisitos CLI satisfechos **con verificación manual pendiente**;
1, requisito ausente; 2, uso/error. Siempre `hosting_certified=false`.

Exige PHP compatible con `^8.4` (despliegue objetivo 8.4.x), `vendor/autoload.php`, lockfile,
presencia de configuración y permisos de escritura en las rutas predeterminadas privadas.
Las extensiones obligatorias salen de `composer.json`: bcmath, ctype, dom, fileinfo, filter,
gd, iconv, json, libxml, mbstring, openssl, pdo, pdo_mysql, session, simplexml, xml,
xmlreader, xmlwriter, zip y zlib. `intl` se informa como opcional. Composer comprueba también
requisitos transitivos del lockfile, incluido PHP de 64 bits.

`memory_limit`, tiempo, subida y cookies del INI CLI son **mediciones**, no aprobación de
capacidad web. El INI de Apache/FPM puede ser distinto; `SessionManager` configura cookies al
abrir sesión. Revisar privadamente `app.debug=false`, `session.cookie_secure=true`, rutas,
almacenamiento de sesiones aislado/escribible y cookies reales `Secure; HttpOnly; SameSite=Lax`.
El script no carga esa configuración y lo indica como desconocido. Si se personaliza la ruta
de storage, comprobarla manualmente: el preflight solo inspecciona la predeterminada.

Completar con el panel/proveedor: Apache 2.4, DocumentRoot, AllowOverride, mod_rewrite,
mod_headers, HTTPS/certificado/renovación/redirección, MariaDB con CHECK efectivos, límite de
conexiones/disco, SSH, cron y copia externa. Nunca publicar `phpinfo()` ni volcar todo el entorno.

## 2. Exposición web y Apache

Opción preferida, conservando las rutas relativas del código:

```text
/home/usuario/app/
  public/                 <-- único DocumentRoot
  src/ config/ vendor/ database/ templates/ bin/ storage/
```

Configurar el VirtualHost HTTPS con `DocumentRoot /home/usuario/app/public`, deshabilitar
listados y permitir las directivas de `public/.htaccess`. Copiar también ese archivo oculto.
El archivo actual aplica router y cabeceras si los módulos están cargados; **no instala un
certificado ni fuerza HTTPS**. Configurar HTTP→HTTPS y renovación en Apache/panel; verificar
cadena, nombre y vencimiento del certificado. No confiar en `X-Forwarded-*` arbitrarios.
Sin rewrite existe el acceso API `index.php?r=/api/v1/...`, pero la UI usa URLs limpias:
validar su funcionamiento con el proveedor antes de desplegarla; no asumir equivalencia.

Si el proveedor obliga a usar `public_html`, preferir apuntarlo/vincularlo a `app/public`
si el panel lo permite. Si obliga a **copiar** los archivos públicos, usar:

```text
/home/usuario/app/          código privado, vendor, config, storage
/home/usuario/public_html/  solo contenido de public/, incluido .htaccess
```

En esa copia de despliegue de `public_html/index.php`, sustituir únicamente la asignación
`$root = dirname(__DIR__);` por la ruta absoluta privada `$root = '/home/usuario/app';`,
adaptada al home real. El front controller usa esa variable para autoload, configuración y
logs. No copiar el repositorio entero a `public_html`. Esa adaptación no se ha aplicado aquí
porque no se conoce la ruta del proveedor. Mantener un registro del cambio de despliegue.

Verificar desde fuera que `/config/config.php`, `/vendor/`, `/storage/`, `/database/`, `/bin/`,
`/.git/` y respaldos no son accesibles. Una regla de denegación no sustituye su ubicación fuera
de la raíz pública. No subir tests, artefactos temporales ni credenciales a la carpeta pública.

## 3. Código, dependencias y configuración

1. Para actualizar, detener escrituras y respaldar BD y privados antes de empezar; ver
   [restauracion.md](restauracion.md). Registrar versión, fecha UTC y operador.
2. Subir una versión revisada con su `composer.lock`. No sobrescribir configuración ni storage.
3. Instalar dependencias de producción, sin actualizar versiones:

   ```sh
   composer install --no-dev --optimize-autoloader --no-interaction
   composer check-platform-reqs --no-dev
   ```

   Sin SSH, generar `vendor/` en un equipo compatible y subirlo junto con el mismo lockfile;
   comprobar requisitos en el PHP del hosting mediante panel/soporte. No se necesita Node,
   npm, Docker ni procesos permanentes. No ejecutar `composer update` en producción.
4. Copiar `config/config.example.php` a `config/config.php` y completar **privadamente** los
   datos reales. El ejemplo no contiene secretos. No versionar, enviar por chat ni imprimir
   la configuración real. Establecer entorno de producción, debug desactivado y Secure activo.
5. Crear `storage/private`, `storage/logs` y privados necesarios con propietario del servicio PHP;
   dar escritura solo allí. Código/vendor/config no deben ser escribibles por PHP. Evitar 777;
   permisos exactos dependen del usuario/grupo del hosting. Configuración legible solo por quien
   la necesita. `display_errors=Off`, `log_errors=On`, logs fuera de la web, con rotación y cuota.

## 4. MariaDB, migraciones y primer administrador

Crear base dedicada InnoDB/utf8mb4 y cuentas no root. Usar una cuenta de mantenimiento para DDL,
migraciones, seeds y triggers; ejecutar temporalmente con esos permisos y retirarlos después.
La cuenta web debe tener permisos de datos mínimos, **sin DROP/ALTER/CREATE/TRIGGER**: TRUNCATE
eludiría los triggers inmutables de historia. La mecánica de cambio de credencial/permisos se
acuerda con el proveedor sin dejar secretos en argumentos o historial de shell.

```sh
php bin/migrate.php
php bin/seed.php
php bin/setup.php --name="Administrador real" --email="admin@tu-estudio.example"
```

El migrador aplica **001–009** en orden y verifica SHA-256 de archivos ya aplicados. No editar
migraciones existentes. Revisar la precondición de 009: gastos no PAGADO no pueden tener
`paid_at`. Los DDL de MariaDB no ofrecen rollback global; ante un fallo no borrar historia ni
marcar versiones manualmente. Conservar el respaldo y analizar antes de reintentar.
`--dry-run` **no es estrictamente solo lectura**: el ejecutor crea `schema_migrations` si falta.

Los seeds son catálogos técnicos (roles, secuencias y ajustes), no datos comerciales de demo.
No insertar usuarios, espacios, tarifas ni horarios ficticios en producción. ADMIN debe capturar
los espacios y valores **reales proporcionados por el estudio** antes de reservar; si faltan,
reservas no está listo para operar.

El setup crea el primer ADMIN, muestra una clave temporal una sola vez y obliga a cambiarla.
No pasar `--password` en una terminal compartida ni guardar su salida. Se niega si ya existe
ADMIN activo; después **retirar/restringir `bin/setup.php`** y su ejecución en el despliegue.
La recuperación posterior es mediante ADMIN, no reejecutando el instalador. Evitar instalaciones
simultáneas. No hay registro público ni acceso de clientes.

## 5. Verificación y puesta en servicio

- Abrir `https://dominio-real/app.html`; login y cambio obligatorio; confirmar zona horaria.
- Comprobar cierre de sesión, cuenta desactivada, rechazo CSRF y autorización por objeto.
- Alta de cliente/proyecto y un flujo comercial **de aceptación autorizado**, no ventas ficticias
  confundidas con operación real. Validar en entorno separado antes de producción.
- Comprobar reserva sin doble ocupación, pago idempotente y ticket sin nuevo movimiento.
- Revisar logs privados y copias externas. Restaurar una copia en destino aislado antes de aceptar.
- XLSX debe abrir sin reparación en **Excel y LibreOffice de escritorio**; esas aplicaciones no
  están disponibles localmente y esta aceptación sigue pendiente, al igual que impresora física.
- Medir listas de 25 con dataset y recursos pactados y 20 usuarios concurrentes: la meta propuesta
  de <2 s no está validada ni prometida. Verificar también límites de exportación/rango.

No ejecutar PHPUnit ni smokes destructivos contra producción. No dar §19.H por cerrado sin
instalación equivalente, inventario real, restauración y verificaciones pendientes documentadas.
