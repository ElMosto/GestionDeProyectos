# Respaldo y restauración

Procedimiento de §18 y §19.H. La prueba local efectuada se describe en
[operacion-local.md](operacion-local.md); no equivale a recuperación del hosting real.

## Política propuesta, aún sujeta al proveedor

Respaldo **diario**, cifrado, con copia fuera del hosting; retención inicial **30 días**,
objetivos **RPO 24 h / RTO 4 h**. No son un SLA: faltan cuotas, ancho de banda, cron, método
del panel y prueba en el proveedor. Asignar responsable y sustituto y registrar ejecuciones
fallidas. Sin cron confirmado, usar panel o ejecución manual documentada: no prometer automatismo.
Una copia sin restauración probada no se considera respaldo operativo.

## Crear una copia consistente

1. Autorizar ventana y destino privado. Suspender escrituras durante el conjunto BD + privados;
   `--single-transaction` da consistencia InnoDB, pero no sincroniza por sí solo archivos ni DDL.
   No ejecutar migraciones durante la copia. No se afirma que exista un botón de mantenimiento:
   restringir temporalmente acceso desde Apache/panel conforme a la operación del proveedor.
2. Anotar fecha UTC, versión de aplicación/lockfile, versión MariaDB, migraciones aplicadas,
   tamaños y hashes; no registrar credenciales ni datos personales. Incluir todas las tablas,
   auditoría, `commercial_document_events`, secuencias e idempotencia. No exportar solo saldos.
3. Volcar con cuenta autorizada de respaldo, nunca la cuenta root de la aplicación:

   ```sh
   mysqldump --defaults-extra-file=/ruta/privada/backup.cnf --single-transaction --triggers --hex-blob --no-tablespaces --result-file=/ruta/privada/backup.sql BASE_REAL
   ```

   `--defaults-extra-file` debe ir primero. Reemplazar rutas y base por valores verificados;
   no copiar literalmente placeholders. Archivo de opciones accesible solo al operador
   (0600 en Unix o ACL exclusiva en Windows), sin contraseña en argumentos. Confirmar con el
   proveedor permisos SELECT/SHOW VIEW/TRIGGER y demás que su versión necesite. Si hay rutinas
   o eventos propios del despliegue, inventariarlos y respaldarlos explícitamente; no se presume
   que existan. No usar `--databases` si la copia debe importarse con otro nombre aislado.
4. Copiar `storage/private/` conservando nombres/rutas relativos y permisos; incluir manifiesto
   SHA-256 de cada archivo. Guardar configuración real por un canal cifrado separado y seguro,
   no en repositorio ni web. Guardar versión de código/lockfile; logs privados según política de
   privacidad/retención, no como sustituto de auditoría en BD. No copiar sesiones activas como
   si fueran archivos de negocio ni prometer que un restore las preserve.
5. Cifrar SQL y privados con herramienta aprobada y cifrado autenticado; conservar claves fuera
   del hosting y separadas de la copia. Registrar formato y procedimiento de descifrado; probar
   que autenticación falla con tag/archivo alterado. AES-256-GCM exige clave de 32 bytes, nonce
   único por clave y tag, además de formato/versión. **No reutilizar nonces**, no publicar claves.
   OpenSSL `enc` no es un sustituto directo del formato GCM probado aquí. Acordar herramienta
   operativa y custodia/recuperación de claves antes de producción.
6. Transferir el cifrado a ubicación externa autorizada, verificar SHA-256 y acceso de recuperación.
   Retirar solo temporales exactos creados por ese trabajo tras comprobar la copia. Nunca borrar
   carpetas compartidas ni aplicar una limpieza amplia. Rotar según retención una vez confirmada.

## Restaurar sin sobrescribir producción

1. Seleccionar punto de recuperación y autorizar alcance. Conservar respaldo actual antes de
   cualquier cambio. Medir tiempos para evaluar RPO/RTO, sin extrapolar una fixture pequeña.
2. Preparar base **nueva, vacía y dedicada**, usuario no root y árbol privado aislado; nunca usar
   `gestion`, `gestion_test` ni la base en servicio para una prueba. En la evidencia local se usó
   exclusivamente `gestion_restore_work`, con fuente `gestion_restore_source_work` desechable.
3. Verificar hash del cifrado, descifrar de forma autenticada a área restringida. Si falla tag/hash,
   detener: no importar un SQL parcial ni ignorar errores. Validar nombres relativos de archivos
   contra traversal; no extraer encima de directorios compartidos.
4. Importar SQL completo con `mysql`, usando cuenta de restauración y archivo de opciones privado:

   ```sh
   mysql --defaults-extra-file=/ruta/privada/restore.cnf --binary-mode BASE_AISLADA < /ruta/privada/backup.sql
   ```

   El ejemplo de redirección es para shell POSIX/cmd; en PowerShell preferir `cmd /c` con rutas
   cuidadosamente entrecomilladas o un proceso con entrada estándar binaria, como en la prueba.
   No enviar secretos como parámetros. La cuenta de restauración necesita DDL/TRIGGER solo en
   esa base. Revisar `DEFINER` de triggers con el proveedor: no retirar triggers para ocultar un
   error de privilegios. No ejecutar seeds/setup contra una copia restaurada de negocio.
5. Restaurar privados al árbol aislado y comprobar hashes/permisos. Usar la misma versión de
   código, dependencias y esquema del respaldo. Si después se actualiza, respaldar y aplicar solo
   migraciones pendientes; no aplicar primero el esquema sobre una importación completa.
6. Comparar **todas** las tablas: conteos y SHA-256 canónico de todas las filas/columnas, orden
   estable y decimales tratados como strings. Incluir tablas vacías. Comparar metadatos de
   FK/CHECK, índices y triggers; buscar referencias colgantes y comprobar restricciones con
   cambios inválidos dentro de transacciones revertidas. No confiar solo en que el import terminó
   con código 0: el dump puede desactivar temporalmente FK durante la carga.
7. Verificar pago, devolución, gasto, documento comercial, enlace a auditoría y archivo privado;
   comprobar netos mediante servicios sin inventar movimientos. La fixture de BASELINE local
   verifica conservación, **no** cobertura histórica financiera: no rellenar emisiones antiguas
   con fechas/precios supuestos. `HISTORY_INCOMPLETE` exige revisar evidencia, no silenciarlo.
8. Arrancar una instancia aislada, validar permisos/login/exportación; no enviar notificaciones
   externas. En recuperación real, planear cierre de sesiones/revocación de tokens: una copia
   puede contener hashes de tokens anteriores y no debe reactivar accesos sin revisión.
9. Solo tras aprobación, detener escrituras y efectuar cambio controlado de destino/credenciales.
   Mantener rollback verificable; no fusionar a mano dos historiales financieros. Registrar
   operador, versión, tiempos, comprobaciones, incidencias y autorización de reapertura.
10. Eliminar únicamente bases, permisos y temporales creados para la prueba, identificados por
    nombre exacto y después de conservar evidencia no sensible. Nunca ejecutar DROP sobre un
    nombre suministrado sin comprobar que es el destino aislado autorizado.

## Prueba periódica y límites

Programación propuesta: ensayo mensual y antes de actualizaciones de alto impacto; pendiente
de acuerdo operativo. Guardar evidencia sin SQL, claves, tokens ni datos reales. La prueba local
de 2026-10-28 ejercitó una copia cifrada con clave aleatoria efímera en memoria y la destruyó al
terminar: demuestra la cadena técnica, **no** una solución de custodia de claves para producción.
Repetir desde una copia externa real y medir recuperación del volumen acordado antes de aceptar
los objetivos de servicio.
