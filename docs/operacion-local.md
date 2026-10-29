# Evidencia operativa local — fase 6

Verificación observada **2026-10-28**, Windows, PHP **8.4.27**, MariaDB
**10.4.32-MariaDB**, `mysqldump 10.19 Distrib 10.4.32-MariaDB Win64`.
Referencia: §18, §19.H, §20. No es prueba de Apache/HTTPS ni certificación del hosting.

## Preflight de solo lectura

```powershell
& 'C:\Users\vgmos\dev\tools\php.cmd' bin/check-hosting.php --json
& 'C:\Users\vgmos\dev\tools\composer.cmd' check-platform-reqs --no-dev
& 'C:\Users\vgmos\dev\tools\php.cmd' vendor/bin/phpunit tests/Unit/Core/HostingCheckTest.php
```

Resultados: JSON versión 1, `status=manual_verification_required`, `hosting_certified=false`,
salida 0. Todas las extensiones requeridas presentes; `intl` presente pero opcional.
Autoload/lockfile/config presentes y storage privado/logs escribibles según metadatos.
Composer: todos los requisitos de producción correctos, incluido `php-64bit`.
Pruebas propias: **3 pruebas / 16 aserciones, sin fallos**; no conectan a BD ni cargan config.

Valores **CLI**, no promesa de capacidad web: memoria `512M`, ejecución `0` (sin límite CLI),
subida `2M`, POST `8M`, `display_errors=1`, `log_errors=1`, `session.cookie_secure=0`,
`cookie_httponly`/`cookie_samesite` vacíos, strict_mode `0`, only_cookies `1`; zona UTC.
El front controller desactiva mostrar errores y SessionManager configura cookies en ejecución.
No se cargó configuración real para inventariarla; Secure/debug/rutas privadas permanecen
como comprobación manual. No se infiere seguridad de producción del INI local.

## Restauración real, aislada y cifrada

Ensayo terminado **2026-10-28T19:21:08Z**. Creación exclusiva de:

- `gestion_restore_source_work`: migraciones **001–009** y seeds mediante los CLI existentes
  con `--database=gestion_restore_source_work` (sin editar configuración).
- `gestion_restore_work`: destino inicialmente vacío de importación del SQL descifrado.

El administrador local solo creó/concedió privilegios en esas bases y los retiró al finalizar;
la carga, dump, importación y comprobación usaron la cuenta de aplicación no root. No se tocaron
`gestion`, `gestion_test` ni `gestion_finance_work`. Los datos de ensayo fueron ficticios:
usuario sin hash utilizable, cliente `.invalid`, venta/partida, BASELINE comercial con enlace a
auditoría, pago, devolución y gasto pagado. No se usó la fixture para afirmar cobertura G.

Procedimiento ejecutado por script PHP temporal privado, fuera del repositorio:

1. Crear ambas bases solo si no existen; migrar/sembrar la fuente y agregar fixture por SQL.
2. Crear un archivo marcador privado en un directorio temporal dedicado.
3. `C:\xampp\mysql\bin\mysqldump.exe` con `--defaults-extra-file` privado, transacción,
   triggers, hex-blob, sin tablespaces y `--result-file`. Directorio con ACL exclusiva del usuario;
   no contraseña en argumentos ni configuración de producción modificada.
4. Empaquetar SQL y marcador como JSON/base64; cifrar mediante PHP OpenSSL **AES-256-GCM**,
   clave aleatoria efímera de 32 bytes solo en memoria, nonce de 12 bytes, tag y AAD de formato.
5. Leer el cifrado del disco, autenticar/descifrar e importar por stdin binario a
   `C:\xampp\mysql\bin\mysql.exe` en el destino aislado; restaurar archivo privado.
   Un tag alterado fue rechazado. No se imprimió ni guardó la clave.
6. Comparar todas las filas/columnas de todas las tablas con strings, JSON canónico por fila,
   orden lexicográfico y SHA-256 (incluidas vacías); comparar metadatos y ejercer restricciones.
7. Conservar evidencia no sensible y retirar bases, grants y temporales exactos creados.

### Resultados comprobados

| Comprobación | Resultado |
|---|---|
| Tablas | **35**, conteos y SHA-256 por tabla idénticos en origen/destino |
| Migraciones | 9 filas, versiones 001–009 |
| Fixture y seeds | 29 filas totales; tablas vacías incluidas en comparación |
| Restricciones | **187** metadatos de restricciones coincidentes; **68** FK revisadas sin referencias colgantes |
| Triggers | `trg_commercial_events_no_update` y `trg_commercial_events_no_delete`, definición coincidente |
| Validación activa | Rechazo de FK inexistente, reembolso negativo y gasto PAGADO sin paid_at |
| Inmutabilidad | UPDATE incluso sin cambio y DELETE del evento comercial rechazados |
| Auditoría | `audit_log_id="1"`, acción `RESTORE_BASELINE`, enlace conservado |
| Decimales exactos | Venta `1000.10`, pago `300.05`, devolución `50.02`, gasto `20.03`, strings idénticos |
| Privados | **1 archivo**, SHA-256 idéntico tras descifrar/restaurar |
| Tiempo observado | 1,451 s para esta fixture pequeña; **no** mide RTO de producción |

SHA-256 del manifiesto canónico (tabla → conteo/hash):
`fd00754b0cb22713feb433bc50f9f14e88fb170fd3daf50267c310dd92b62ee4`.

SHA-256 del archivo privado:
`4bd9ac8cfc98bf8203f60f75512da8ec1b1c1c121bba601b5a43e4406b87cee0`.

SHA-256 del cifrado utilizado (retirado después del ensayo):
`afd27e6941274965b893f942ae0d01eac84b1055232056f894c308d693b1038f`.

La evidencia detallada no sensible, con conteos y hashes de las 35 tablas, se conserva localmente
en `C:\Users\vgmos\AppData\Local\Temp\opencode\gestion-restore-20261028-6a3e\evidence.json`.
Es un artefacto local temporal, no un respaldo recuperable ni dependencia de la aplicación.
No se conservó una clave de recuperación: la custodia operativa queda por definir con el proveedor.
La comprobación posterior, guardada junto a ella como `cleanup-evidence.json`, confirmó **0 bases
y 0 grants de esquema remanentes**, sin archivo de credenciales, SQL, cifrado ni marcadores.
También se retiraron los dos scripts temporales exactos del ensayo. Lint de los dos PHP nuevos:
correcto; `git diff --check` de los archivos asignados: sin errores.

## Límites que no se dan por resueltos

- No se dispone de proveedor/panel, inventario Apache, DocumentRoot, HTTPS, certificado,
  cron, cuotas ni restauración desde una copia externa del hosting. Repetir allí con datos
  autorizados y volumen real antes de aceptar RPO/RTO propuestos.
- Excel y LibreOffice **de escritorio no están instalados**; no se instalaron ni adquirieron
  licencias. La apertura sin reparación en ambos sigue pendiente, aunque haya pruebas del XML.
- Impresora física de 80 mm/A4: pendiente. PDF/navegador no sustituyen impresión real.
- Dataset y recursos no acordados para 20 usuarios concurrentes y listas de 25 en <2 s.
  No se ejecutó ni se declara benchmark de hosting; el tiempo de la fixture no es esa medición.
- Finanzas y salidas de fase 6 estaban en integración paralela durante esta entrega. Este
  documento no afirma regresión completa, cierre de G/H ni fin de fase. Ver evidencia del
  integrador en [validacion.md](validacion.md), no extrapolar estas tres pruebas a toda la suite.
