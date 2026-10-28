# Pagos — contrato de fase 3 (§10–11)

Todas las rutas llevan `/api/v1`, sesión+CSRF o Bearer. Dinero e IDs son strings;
fechas de salida ISO 8601 UTC. Errores estándar `{error:{code,message,fields},request_id}`.

## Escrituras

- `POST /sales/{id}/payments`, `POST /projects/{id}/payments`: 201.
  Body: `amount` string positivo con dos decimales, `effective_at` ISO 8601 con zona,
  `method` EFECTIVO/TRANSFERENCIA/TARJETA/OTRO; opcionales `reference`, `notes`.
- `POST /sales/{id}/mark-paid`: 200. Mismo body, sin `amount`: calcula saldo bajo lock.
  Es un atajo, no cambia estado a mano; total cero no inserta pago ficticio.
- `POST /payments/{id}/void`: 200. Body `reason` obligatorio (máximo 255).
- `POST /payments/{id}/refunds`: 201. Body `amount`, `effective_at`, `reason` obligatorios.

Todas exigen `Idempotency-Key` (1–100 caracteres ASCII sin espacios). Una versión de
documento es opcional: `expected_version`/`version` o `If-Match: "N"`; si se envía se
compara y debe ser coherente. Los importes y totales no son editables mediante pagos.
Registrar/anular/reembolsar incrementa la versión del documento.

Respuesta de escritura: `{data:{payment:Payment|null,refund:Refund|null,
document:{kind:"sale"|"project",id:"1",version:"2"},financial_summary:Summary}}`.
Summary: `{total:"1000.00",paid_amount:"300.00",refunded_amount:"0.00",
net_paid:"300.00",balance:"700.00",payment_status:"PARCIAL",billable:true}`.
Estados: NO_PAGADO/PARCIAL/PAGADO/SIN_CARGO; no cobrable siempre saldo cero.

## Historial

- `GET /sales/{id}/payments?limit=25&offset=0` → `{data:[Payment],meta:{total,limit,offset}}`.
- `GET /projects/{id}/payments`: ruta preexistente de proyectos, no se duplica.
- `GET /payments/{id}/refunds?limit=25&offset=0` → lista paginada de Refund.

Payment: id, folio, project_id/sale_id nullable, amount, effective_at, method,
reference, notes, status, registered_by, voided_at, voided_by, void_reason, version,
created_at, updated_at. Refund: id, folio, payment_id, amount, effective_at, reason,
status, registered_by, voided_at, voided_by, version, created_at, updated_at.
Nunca se expone la clave idempotente almacenada.

Historial financiero por documento actual (no por registrador). Ventas: ADMIN o
responsable. Proyectos: ADMIN o líder propietario, nunca colaborador miembro.
Nuevos cobros: venta EMITIDA; proyecto cobrable, no CANCELADO, con cliente activo;
FINALIZADO admite cobrar saldo. Anular/reembolsar solo ADMIN. Anular con reembolsos
activos falla `409 PAYMENT_HAS_REFUNDS`; reembolso de anulado falla
`409 PAYMENT_VOIDED`; exceso de devolución `409 REFUND_EXCEEDS_PAYMENT`.
Sobrepago `409 OVERPAYMENT`; versión vieja `409 VERSION_CONFLICT`;
clave reutilizada con otro contenido `409 IDEMPOTENCY_KEY_REUSED`.

Idempotencia y efecto comparten transacción; se reautoriza el objeto actual incluso
en replay. El hash incluye POST+ruta canónica+body normalizado+versión opcional.
D-19: clave en payments/refunds es SHA-256 JSON de `[actor decimal,operation,path,key]`;
el registro de solicitudes conserva la clave HTTP original. Orden: solicitud,
documento, pagos por ID, reembolsos; sin transacciones anidadas. No hay cancelación
automática, tasas, dashboard ni endpoint de anulación de reembolsos.

## Verificación local del módulo

Smoke ejecutado exclusivamente en `gestion_payments_work` con fixtures únicos,
sin modificar configuración ni ejecutar suites compartidas: abonos 300/1000 y
700 restantes, replay, clave cambiada, versión obsoleta, sobrepago, dos reembolsos
sin multiplicación del agregado, límites, anulación, proyecto finalizado, cero sin
asiento, recorte del serializador y replay tras cambio de propietario. Validación
de importes, campos, versiones y fechas con normalización UTC comprobada.
Dos procesos concurrentes cobrando 600 sobre saldo 850: un éxito y OVERPAYMENT.
Dos procesos reembolsando 400 sobre pago 600: un éxito y REFUND_EXCEEDS_PAYMENT.
Estados HTTP persistidos: 201 registrar/reembolsar, 200 mark-paid/anular. Lint de
los doce archivos PHP del módulo correcto. La suite integrada corresponde a QA.
