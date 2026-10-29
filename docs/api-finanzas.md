# API de finanzas y administración — fase 6

Prefijo `/api/v1`; todos estos recursos exigen ADMINISTRADOR activo (sesión/CSRF o Bearer).
Importes MXN e identificadores son strings. Fechas DATE se interpretan en **UTC**,
intervalos `[from 00:00, to + 1 día)`. `cut_off` incluye el día completo.
Propuesta conservadora: rango inclusivo máximo 366 días; por defecto mes UTC actual.
Paginación `limit=25` (máximo 100), `offset`; metadatos `total,limit,offset`.

## Consultas financieras

`GET /finance/summary`, `/cashflow`, `/receivables`, `/transactions`, `/documents` aceptan
`from,to,cut_off,client_id,responsible_id,source_type` (INTERNO,EXTERNO,ORDINARIA,RESERVA),
`status` (NO_PAGADO,PARCIAL,PAGADO,SIN_CARGO), `limit,offset`.
`cut_off` por defecto `to`. Estado se refiere al estado de pago del documento al corte.
El rango comercial selecciona la primera emisión; no la creación operativa ni el cobro.
Pendientes usa todos los documentos emitidos hasta el corte, sin restringir cohorte.
Los filtros de origen/cliente afectan documentos y sus cobros; gastos generales no tienen
cliente/origen y se excluyen al aplicar esos filtros. Responsable sí filtra gastos.
Con filtro de estado financiero se excluyen gastos (no tienen estado de cobro del documento).
Se rechaza `cut_off < to`; para un corte anterior, reduce también `to`.

Summary devuelve `commercial_total,commercial_sales_total,commercial_projects_total,internal_estimated_value,net_cash,paid_expenses,
pending_expenses,cash_result,receivables_total,distribution` (importes strings).
Distribución incluye ORDINARIA, RESERVA y PROYECTO; reserva se cuenta solo como venta.
Cashflow devuelve filas `{month,net_cash,paid_expenses,cash_result}` agrupadas por mes UTC.
Receivables devuelve `{document_type,document_id,folio,source_type,client_id,responsible_id,
issued_at,total,net_paid,balance,payment_status}` con saldo positivo al corte.
Transactions devuelve eventos `{id,kind,document_type,document_id,folio,effective_at,amount,
source_type,client_id,responsible_id}`: PAYMENT positivo, PAYMENT_VOID negativo,
REFUND negativo (y REFUND_VOID positivo si existiera). Los signos son deliberados.
Las anulaciones corrigen caja en su fecha de anulación sin borrar el cobro de meses pasados.
Reembolsar un pago antiguo afecta el mes de la devolución, no el mes de emisión.

**Respaldo comercial:** `GET /finance/documents` pagina la cohorte de emisión from/to,
con el mismo snapshot, corte y filtros del summary; excluye cancelados al corte.
DTO igual a receivables, más `billable` boolean y `commercial_class`
(`SALE`,`PROJECT`,`INTERNAL_ESTIMATE`). Suma `total` de SALE = commercial_sales_total,
PROJECT = commercial_projects_total; ambas = commercial_total. INTERNAL_ESTIMATE respalda
internal_estimated_value (no se suma como cobro/cartera). Incluye documentos liquidados y
saldo cero: no confundir este respaldo con receivables.

**Respaldo de gastos pagados:** `GET /finance/transactions?kind=expense` pagina exclusivamente
PAGADO por **paid_at**, nunca expense_date. `kind=cash` es el valor predeterminado y conserva
el historial de cobros/anulaciones/reembolsos. Otra clase → 422 VALIDATION_FAILED.
DTO gasto: `{id:"EXPENSE:1",kind:"EXPENSE",expense_id:"1",folio:"GAS-...",project_id:null,
concept:"Material",responsible_id:"1",effective_at:"2026-10-28T12:00:00Z",amount:"-200.00"}`.
La suma negada de `amount` de todas las páginas cuadra con paid_expenses; pagos y reembolsos
no se mezclan en esta modalidad. Se aplican **los mismos** filtros que en summary/cashflow:
responsable filtra; cliente/origen/estado financiero excluyen gastos no asignables, sin
inventar relación con cliente ni origen. Ninguna de estas consultas requiere Idempotency-Key.

Todos incluyen `meta.coverage=COMPLETE,generated_at,cut_off,timezone=UTC`.
Sin evidencia suficiente: **409 HISTORY_INCOMPLETE**, nunca cero ni precios actuales.
La comprobación cruza todas las fuentes actuales, antes de aplicar filtros, para no ocultar
documentos migrados sin evidencia. También rechaza caja retrofechada anterior a la primera
evidencia comercial disponible. Esto es conservador: un documento legacy puede bloquear
la consulta completa aunque el filtro parezca excluirlo.
Snapshots comerciales append-only enlazados a auditoría: PROJECT_CREATE emite incluso BORRADOR
(D-28), SALE_ISSUE emite ventas. Cambios/cancelaciones afectan cortes posteriores, nunca
reescriben cortes previos. BASELINE no inventa emisión histórica y bloquea cobertura (D-29).
`cash_result` es resultado de caja, **no utilidad contable**.

## Gastos

`GET/POST /expenses`, `GET/PATCH /expenses/{id}`. POST exige Idempotency-Key:
`{"concept":"Material","amount":"200.00","expense_date":"2026-10-28",
"responsible_id":"1","status":"PENDIENTE","project_id":null,"notes":null}`.
PAGADO requiere `paid_at` ISO8601 con segundos y zona, normalizado UTC; no se inventa fecha.
PATCH exige versión (`If-Match: "1"` o `expected_version`) e Idempotency-Key.
Pendiente puede editarse/pagarse/cancelarse; pagado es inmutable y cancelado terminal.
No cambia el total comercial ni las cuentas por cobrar. DTO expone campos del gasto, IDs
y versión string, fechas ISO UTC. Lista filtra from/to por expense_date, status,
responsible_id,project_id; límite/rango igual al resto.
Se rechaza `paid_at` futuro. Los snapshots completos de gasto se auditan atómicamente;
pendiente histórico usa el último snapshot de auditoría hasta el corte, su expense_date
y el paid_at real (si ya hay pago). Un cambio/cancelación posterior no reescribe su importe
pendiente anterior. Gastos legacy sin EXPENSE_CREATE también producen HISTORY_INCOMPLETE.

## Configuración y auditoría

GET `/settings` devuelve `studio_name,timezone,currency,version`. Versión es digest SHA256
del estado canónico permitido, no un contador. PATCH solo `studio_name,timezone` y
`expected_version` (digest), o `If-Match: "digest"`. MXN es solo lectura. Zona IANA;
cambiar zona con ocupaciones futuras devuelve 409 SETTINGS_TIMEZONE_IN_USE.

GET `/audit-logs`: filtros `action,entity_type,actor_id,from,to,limit,offset`.
DTO solo `id,actor_id,action,entity_type,entity_id,request_id,ip_address,created_at`;
**no** entrega before_json/after_json ni notas/títulos/lugares privados, tampoco a ADMIN.
Errores comunes: 401 UNAUTHENTICATED,403 FORBIDDEN,404 NOT_FOUND,
409 VERSION_CONFLICT/IDEMPOTENCY_KEY_REUSED,422 VALIDATION_FAILED.

## Verificación local y casos para QA (§19.G/H)

Smoke solo en `gestion_finance_work`, sin reset ni pruebas sobre BD compartidas:
14 comprobaciones funcionales más 8 de historia/rollback y 3 de conciliación detalle/summary
correctas. Conciliación: gastos pagados 200.00 vs eventos -200.00, comercial ventas 1000.00
y proyectos 0.00 vs documentos de emisión, y filtro cliente sin gasto asignable devuelve cero
en ambas fuentes. Lint de los 20 archivos
PHP del módulo correcto. EXPLAIN de cartera con CTE ejecutado (23 filas de plan);
**no** es una medición de rendimiento con dataset pactado.

Casos para suite del integrador: repetir clave de gasto devuelve mismo DTO y una sola fila;
otra cantidad con la misma clave da 409 sin auditoría parcial; versión vieja no escribe;
pagado no admite reescritura/reversión/cancelación; gasto no aumenta comercial/cartera;
BORRADOR de proyecto sí crea ISSUE; campos irrelevantes no crean otro snapshot; ajuste,
cancelación y reapertura solo afectan cortes futuros; BASELINE no infiere emisión;
fallo al insertar snapshot revierte documento/auditoría/idempotencia; venta septiembre
con abono octubre y anulación noviembre conserva +300 octubre/-300 noviembre, cartera
700 al corte octubre; devolución de cobro antiguo sale en mes de devolución; RESERVA
se suma una vez; no ADMIN falla en cada endpoint/replay; auditoría nunca expone reuniones;
settings digest viejo falla; zona con ocupación futura falla; Bearer y sesión/CSRF equivalentes.
La suite completa, OpenAPI y verificación HTTP quedan para integración principal/QA.
