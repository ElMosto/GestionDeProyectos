# Dataset de demostración

Carpeta reservada para los datos de demostración. **Vacía a propósito en la fase 1.**

## Convención obligatoria

- Nombre de archivo: `9XX_demo_<tema>.sql` (por ejemplo `900_demo_studio.sql`).
- Cabecera con la marca `-- DEMO: no aplicar en producción`.
- Todos los folios llevan el prefijo `demo-` (`demo-PRY-2026-000001`), todos los correos usan el
  dominio `@demo.invalid` y los nombres empiezan por `Demo `.
- Nunca usa identificadores de fila de producción; se apoya en `SELECT ... WHERE` por folio o
  correo marcado para ser idempotente.
- No crea usuarios con contraseña utilizable: si necesita cuentas, usa un hash inválido
  (`*` o `!`) y `must_change_password = 1`.
- No incluye tarifas, impuestos ni políticas de cancelación inventadas; los valores demo se
  marcan como `demo` y se sustituyen antes de cualquier uso real.

## Por qué todavía no existe

La fase 1 solo entrega autenticación, roles, perfiles y clientes. Un dataset con proyectos,
ventas, reservas y pagos sin esos módulos implementados produciría filas que ninguna regla de
negocio ha validado todavía. Se crea en la fase 2, cuando exista al menos un flujo real que lo
consuma, y siempre como archivo separado de las semillas iniciales.
