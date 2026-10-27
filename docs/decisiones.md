# Registro de decisiones

Formato: una entrada por decisión, con estado. `Aprobada` obliga; `Propuesta` es el valor por
defecto mientras el responsable del estudio no diga lo contrario; `Abierta` bloquea solo la fase
indicada.

| ID | Decisión | Estado | Fase |
|---|---|---|---|
| D-01 | Tres roles: ADMINISTRADOR, LIDER_PROYECTO, COLABORADOR. Sin rol USUARIO. | Propuesta | 1 |
| D-02 | Cargos adicionales de proyectos internos aumentan importe comercial; no crean cobro. Interno no cobrable = valor interno estimado. | Propuesta | 2 |
| D-03 | Pagos manuales. Sin pasarela, sin CFDI. Estado de pago siempre calculado. | Aprobada | 3 |
| D-04 | Toda reserva es de cliente BANDA y genera exactamente una venta tipo RESERVA. | Aprobada | 4 |
| D-05 | Ticket = HTML con `@media print` (80 mm y A4). Sin librería PDF. | Aprobada | 3 |
| D-06 | Repositorio: `https://github.com/ElMosto/GestionDeProyectos`, rama `main`, push al cerrar cada fase. | Aprobada | 0 |
| D-07 | Proyecto alojado en `C:\Users\vgmos\dev\gestion`, fuera de OneDrive. | Aprobada | 0 |
| D-08 | **App Android:** autenticación por tokens opacos (Bearer) para móvil, además de sesión+cookie para web. Sin JWT. | Aprobada | 1 |
| D-09 | Contrato `/api/v1` tratado como API pública desde el día uno; OpenAPI 3.1 es la fuente de verdad. | Aprobada | 1 |
| D-10 | PhpSpreadsheet `^5.10` (requiere PHP ^8.2 y ext-gd). Si el hosting no tiene `gd`, se degrada a exportación CSV con aviso explícito y se replantea. | Propuesta | 6 |
| D-11 | PHPUnit `^13.4` como dependencia de desarrollo (no se sube a producción). | Aprobada | 1 |
| D-12 | Moneda única MXN, sin impuestos ni descuentos en el alcance inicial. | Propuesta | 3 |

## Puntos abiertos heredados del §21

| # | Punto | Valor propuesto mientras no se confirme | Impacto |
|---|---|---|---|
| 1 | ¿Cuarto rol USUARIO? | No se crea. | Ninguno hasta que se pida. |
| 2 | ¿Quién paga los cargos internos? | Nadie por defecto: valor interno. Para cobrar se asigna cliente/pagador y `billable = 1`. | Finanzas |
| 3 | Alcance de cobro de líder/colaborador | Según §5: líder cobra en sus proyectos, colaborador en sus ventas/reservas. | Permisos |
| 4 | Espacios, tarifas, bloques, cancelación | 1 espacio demo, bloques de 30 min, sin penalización automática. | Reservas |
| 5 | ¿Abonos en ventas ordinarias? | Sí (el módulo de pagos ya lo soporta). Sin inventario, IVA ni descuentos. | Ventas |
| 6 | Datos impresos y PDF | Ticket 80 mm + A4 por CSS. PDF vía diálogo del navegador. | Salidas |
| 7 | Capacidades del hosting | **Pendiente del inventario real.** Bloquea el cierre de la fase 1 y D-10. | Despliegue |

## Capacidades de hosting a verificar (bloqueante)

Registrar la salida real de `php -v`, `composer check-platform-reqs`, versión de MariaDB,
`DocumentRoot`, soporte de `.htaccess`, `mod_rewrite`, HTTPS, límite de `memory_limit` y
`max_execution_time`, espacio en disco, acceso a cron y método de respaldo del panel.

Extensiones mínimas requeridas por el stack: `pdo`, `pdo_mysql`, `mbstring`, `fileinfo`,
`session`, `openssl`, `bcmath`, `zip`, `gd`, `dom`, `xml`, `xmlreader`, `xmlwriter`,
`simplexml`, `libxml`, `ctype`, `filter`, `iconv`, `zlib`.

Si falta `bcmath`: usar centavos enteros con una única implementación (ver skill
`dinero-decimales`). Si falta `gd`: PhpSpreadsheet 5.x no instala; ver D-10.
