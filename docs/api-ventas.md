# Catálogos y ventas — fase 3

Contrato `/api/v1`, autenticación sesión+CSRF o Bearer. Envelope `{data,meta}`.
IDs y versiones string; dinero string con 2 decimales, cantidades con 3; fechas ISO UTC.

## Catálogo

`GET/POST /products`, `GET/PATCH /products/{id}`; equivalentes `/extras`.
Todos consultan activos; ADMIN consulta también inactivos y modifica.
Filtros `limit` (25, máximo100), `offset`, `q`, `active=0|1`; productos `type=PRODUCTO|SERVICIO`.
Producto: `{sku:null,name,description:null,type:"PRODUCTO",unit:"pieza",price:"0.00",active:true}`.
Alta exige name y price. Extra: `{name,price,active:true}`. SKU opcional único (`409 SKU_CONFLICT`).
PATCH exige `version`, `expected_version` o `If-Match: "N"` coherentes.
`PUT /products/{id}/extras {version,extra_ids:["1"]}` reemplaza vínculos con extras activos.
GET producto añade `extra_ids` (solo extras activos para no administradores).

## Ventas

`GET/POST /sales`, `GET/PATCH /sales/{id}`, `POST /sales/{id}/issue`, `POST /sales/{id}/cancel`.
ADMIN ve todas; otros únicamente sus ventas. Crear asigna responsable actual y tipo ORDINARIA.
Filtros `q` (folio), `status`, `type`, `client_id`, `from`, `to` (fecha emisión UTC inclusiva),
`responsible_id` (solo ADMIN), paginación común. Orden id descendente.

Alta `{client_id:null,notes:null,items:[{product_id:"1",quantity:"2.000",extras:[{extra_id:"1",quantity_per_unit:"1.000"}]}]}`; `type:"ORDINARIA"` es opcional, ningún otro tipo se admite.
Se permite BORRADOR vacío (propuesta conservadora); emitir exige al menos una partida.
Concepto libre solo ADMIN: `{product_id:null,name:"Concepto",unit_price:"50.00",quantity:"1.000",extras:[]}`.
Intentar crear un concepto libre sin permiso administrativo devuelve `403 FORBIDDEN` (no error de formato).
No se admiten extras libres, ni extras sobre concepto libre. Cada extra debe estar activo y vinculado al producto.
Se rechazan campos desconocidos, precios en partidas de catálogo y tipo RESERVA público.
PATCH acepta solo client_id, notes e items (reemplazo completo), más versión.
Crear, emitir, cancelar y PATCH con items exigen `Idempotency-Key` (ASCII sin espacios,1–100).
PATCH notes/client_id sin items solo requiere versión. Emitir: `{version}`. Cancelar: `{version,reason}`.
Cancelar solo ADMIN; neto positivo devuelve `409 PAYMENTS_REQUIRE_RESOLUTION`; RESERVA devuelve
`409 RESERVATION_MANAGED`. Nunca registra pagos o devoluciones automáticas.

BORRADOR lee/recalcula catálogo vigente sin escritura; creación y edición guardan valores provisionales.
Emisión bloquea venta, productos ordenados y extras ordenados, recalcula y congela snapshots definitivos.
Cantidad × (precio base + Σ cantidad extra por unidad × precio extra), escala8 y half-up solo al final por partida.
EMITIDA/CANCELADA no admite PATCH (`409 SALE_NOT_DRAFT`). Versión obsoleta: `409 VERSION_CONFLICT`.
Reintentos idempotentes reautorizan acceso actual antes de entregar respuesta almacenada.

## DTO y contrato interno de impresión

`SaleService::show(int $id): array` retorna venta plana:
`{id,folio,type,client_id,responsible_id,status,issued_at,currency,total,notes,version,created_at,updated_at,items,financial_summary}`.
La lista devuelve solo esos campos escalares (sin items ni financial_summary), con total vigente
de borradores resuelto por lote; `meta:{total,limit,offset}`. El detalle contiene el ledger.
Item: `{id,product_id,name_snapshot,quantity,unit_price,line_total,sort_order,extras}`.
Extra: `{id,extra_id,name_snapshot,quantity_per_unit,unit_price}`. IDs de partidas nuevas provisionales
pueden ser null antes de persistir; respuestas HTTP persistidas mantienen sus IDs.
`financial_summary` proviene exclusivamente de PaymentLedger:
`{total,paid_amount,refunded_amount,net_paid,balance,payment_status,billable}`.

`SaleService::ticketData(int $id): array` devuelve el MISMO DTO plano enriquecido con:
- `client`: null o `{id,name,type,contact_name,phone,representative_name,representative_phone}` (sin notas/domicilio).
- `responsible`: `{id,name}`.
- `payments`: lista histórica `{id,folio,amount,effective_at,method,reference,status,voided_at,void_reason}`.
- `refunds`: lista `{id,payment_id,folio,amount,effective_at,reason,status}`. Reembolso no posee medio/referencia separados en el esquema; el medio original está en payments.
- `client.representative_phone` reutiliza phone para BANDA (no hay otro teléfono en el esquema).

show/ticketData exigen policy canViewSale; ningún campo financiero se expone a actor ajeno.
Ticket es lectura pura: no emite ni genera cobros. Historial completo incluso anulados.
Rutas de pagos e impresión pertenecen a módulos separados.
