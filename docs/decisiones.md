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
| D-13 | Avance automático por fases 1–6, incluidas pruebas, commit y push; sin esperar nueva orden. El hosting pendiente no se considera certificado por pruebas locales. | Aprobada por el usuario, 2026-10-28 | 1–6 |
| D-14 | Tareas obligatorias (`is_required = 1` por defecto): solo COMPLETADA satisface la finalización; CANCELADA obligatoria requiere cambiar alcance o excepción ADMIN con motivo auditado. | Propuesta | 2 |
| D-15 | Líder de proyecto = cuenta activa con rol LIDER_PROYECTO; reabrir proyecto terminal exige ADMIN y motivo; ajustes comerciales de documentos con cobros quedan reservados a ADMIN y nunca bajan el total del neto cobrado. Transiciones concretas en `docs/api-proyectos.md`. | Propuesta conservadora | 2 |
| D-16 | Motor monetario actual único: BCMath, disponible y exigido por Composer. No se mantiene un segundo motor simultáneo. Si el inventario real de hosting demuestra que falta BCMath, se sustituirá por centavos exactos con las mismas pruebas antes de desplegar. | Propuesta | 2 |
| D-17 | Proyectos usan contadores anuales `project.AAAA`, bloqueados en transacción; el año integra el folio. Se conservan los cinco nombres base del seed por compatibilidad, pero no se reinician contadores usados. Sustituye para proyectos la propuesta inicial del comentario del seed 003 de contador global. | Propuesta | 2 |
| D-18 | Cancelar ventas: solo ADMIN por §10 (más restrictivo que la matriz previa que permitía líder sin pagos). Motivo obligatorio; con neto cobrado positivo se rechaza hasta resolver explícitamente reembolsos/anulaciones. No se inventan penalizaciones ni devolución automática. | Propuesta conservadora ante contradicción | 3 |
| D-19 | En payments/refunds, `idempotency_key` guarda SHA-256 de `[actor,operation,path,key HTTP]`, conservando la clave HTTP original en el registro de solicitudes. Evita colisiones entre actores de las columnas UNIQUE globales. Folios de ventas/pagos/reembolsos usan secuencias globales del seed. | Propuesta | 3 |
| D-20 | Reserva nace confirmada con una venta RESERVA/EMITIDA y una partida, cantidad 1.000 y precio igual al importe calculado; previsualizar no persiste borradores ni garantiza disponibilidad. No se crea otro estado fuera del esquema. | Propuesta conservadora | 4 |
| D-21 | Reprogramar en el mismo espacio conserva tarifa histórica; cambiar de espacio adopta tarifa vigente del destino. Actualiza la venta/partida en transacción sin reemplazar sus IDs ni bajar del neto cobrado. | Propuesta | 4 |
| D-22 | Sin ventanas de horario no hay disponibilidad. Granularidad desde medianoche local; ventanas nocturnas divididas con extremo 24:00:00. No se inventan tarifa ni horarios del espacio inicial: ADMIN debe configurarlos. | Propuesta conservadora | 4 |
| D-23 | Cancelar reserva cancela su venta: se aplica la restricción ADMIN de §10/D-18 también al coordinador, incluso sin pagos. La matriz previa permitía líder; ante contradicción gana la restricción mayor. Requiere motivo y neto cero sin reembolso automático. | Propuesta conservadora ante contradicción | 4 |
| D-24 | Invitados fijados al crear reunión; PATCH rechaza cambiar lista, sin borrar historial ni inventar revocación. Cambiar inicio/fin restablece todas las respuestas a PENDIENTE (incluidas rechazadas), limpia responded_at e incrementa versiones. | Propuesta conservadora | 5 |
| D-25 | Agenda personal privada incluso para ADMIN. Pendiente/rechazado recibe detalle mínimo de invitación para responder, no incorporación al calendario. Notificaciones persistidas genéricas sin dinero, títulos ni textos privados del objeto. | Propuesta conservadora | 5 |
| D-26 | Cruces personales propios requieren acknowledge_conflicts explícito; se permite continuar sin revelar actividades ajenas. La ocupación de espacio nunca se omite con esa confirmación. Solo cambios temporales reinician aceptaciones; cambios de texto/lugar mantienen respuestas. | Propuesta conservadora | 5 |
| D-27 | Sondeo de avisos cada 60 s mientras la bandeja esté abierta y la pestaña visible; detener al navegar/salir. No hay campana global ni sondeo en segundo plano. | Propuesta | 5 |
| D-28 | Historia comercial append-only por snapshots desde fase 6, vinculados a auditoría. En proyectos, emisión comercial = creación explícita del documento (independiente del estado operativo BORRADOR), preservando los cobros ya permitidos en ese estado. No equivale a cobro ni factura fiscal. Ventas se emiten por su operación existente. | Propuesta para compatibilidad del contrato | 6 |
| D-29 | Los cortes sin historia suficiente se rechazan con HISTORY_INCOMPLETE; no se sustituye por total actual ni se retrofechan snapshots. La migración no fabrica historia. Gastos cancelables solo mientras pendientes; los pagados no se borran ni se convierten a pendientes para ocultar caja. | Propuesta conservadora | 6 |
| D-30 | Gantt: máximo 10.000 tareas, 1.000 etapas, 200.000 celdas y cinco años por descarga; escala diaria hasta 180 días, semanal después. Texto mayor de 32.767 caracteres se rechaza. Reducir/dividir mediante from/to; nunca truncar en silencio. | Propuesta para recursos de hosting pendientes | 6 |

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
