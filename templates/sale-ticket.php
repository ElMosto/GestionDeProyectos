<?php

declare(strict_types=1);

/** @var array $data Already authorized; only explicit display fields are used. */
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ticket de venta <?= $escape($data['folio'] ?? '') ?></title>
    <link rel="stylesheet" href="/assets/css/tickets.css">
    <script type="module" src="/assets/js/ticket.js"></script>
</head>
<body class="ticket-page paper-<?= $escape($paper) ?>" data-paper="<?= $escape($paper) ?>">
<nav class="ticket-controls no-print" aria-label="Opciones de impresión">
    <button type="button" id="print-ticket">Imprimir ticket</button>
    <?php if ($ticketUrl !== null): ?>
        <a href="<?= $escape($ticketUrl) ?>?paper=80mm"<?= $paper === '80mm' ? ' aria-current="page"' : '' ?>>80 mm</a>
        <a href="<?= $escape($ticketUrl) ?>?paper=A4"<?= $paper === 'A4' ? ' aria-current="page"' : '' ?>>A4</a>
    <?php endif; ?>
    <p>Para papel térmico, seleccione un rollo de 80 mm en la impresora y ajuste la longitud al contenido. Para A4, seleccione A4. Puede guardar como PDF desde el diálogo de impresión.</p>
    <noscript>Use la opción Imprimir de su navegador.</noscript>
</nav>
<main class="ticket">
    <header>
        <h1><?= $escape($studioName) ?></h1>
        <?php if (!empty($studio['address'])): ?><p><?= $escape($studio['address']) ?></p><?php endif; ?>
        <?php if (!empty($studio['phone'])): ?><p>Teléfono: <?= $escape($studio['phone']) ?></p><?php endif; ?>
        <h2>Ticket de venta</h2>
        <p>Folio: <strong><?= $escape($data['folio'] ?? 'Sin folio') ?></strong></p>
        <p class="document-status<?= in_array($data['status'] ?? '', ['BORRADOR', 'CANCELADA'], true) ? ' status-warning' : '' ?>">Estado: <?= $escape($data['status'] ?? 'No disponible') ?></p>
        <?php if (($data['status'] ?? '') === 'BORRADOR' || empty($data['issued_at'])): ?>
            <p>Fecha de emisión: sin emitir</p>
        <?php else: ?>
            <p>Fecha de emisión: <?= $escape($date($data['issued_at'])) ?></p>
        <?php endif; ?>
        <p>Zona horaria: <?= $escape($timezone->getName()) ?></p>
        <p>Tipo: <?= $escape($data['type'] ?? 'ORDINARIA') ?></p>
        <p>Usuario responsable: <?= $escape($data['responsible']['name'] ?? 'No disponible') ?></p>
    </header>
    <section aria-labelledby="client-heading">
        <h2 id="client-heading">Cliente</h2>
        <?php if (is_array($data['client'] ?? null)): ?>
            <p><?= $escape($data['client']['name'] ?? '') ?></p>
            <?php foreach (['type' => 'Tipo', 'contact_name' => 'Contacto', 'representative_name' => 'Representante', 'phone' => 'Teléfono'] as $field => $label): ?>
                <?php if (!empty($data['client'][$field])): ?><p><?= $label ?>: <?= $escape($data['client'][$field]) ?></p><?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?><p>Sin cliente</p><?php endif; ?>
    </section>
    <?php if (is_array($data['reservation'] ?? null)): ?>
    <section aria-labelledby="reservation-heading">
        <h2 id="reservation-heading">Reserva</h2>
        <p>Banda: <?= $escape($data['reservation']['band_name'] ?? '') ?></p>
        <p>Espacio: <?= $escape($data['reservation']['space_name'] ?? '') ?></p>
        <p>Inicio: <?= $escape($date($data['reservation']['start_at'] ?? null)) ?></p>
        <p>Fin: <?= $escape($date($data['reservation']['end_at'] ?? null)) ?></p>
    </section>
    <?php endif; ?>
    <section aria-labelledby="items-heading">
        <h2 id="items-heading">Partidas</h2>
        <table>
            <thead><tr><th scope="col">Concepto y detalle</th><th scope="col">Importe</th></tr></thead>
            <tbody>
            <?php foreach ($data['items'] ?? [] as $item): ?>
                <tr>
                    <th scope="row">
                        <span><?= $escape($item['name_snapshot'] ?? '') ?></span>
                        <span class="item-detail">Cantidad: <?= $escape($item['quantity'] ?? '') ?></span>
                        <span class="item-detail">Precio unitario base: <?= $money($item['unit_price'] ?? null) ?></span>
                        <?php if (!empty($item['extras'])): ?>
                        <ul class="extras">
                            <?php foreach ($item['extras'] as $extra): ?>
                            <li>Extra: <?= $escape($extra['name_snapshot'] ?? '') ?>;
                                cantidad por unidad: <?= $escape($extra['quantity_per_unit'] ?? '') ?>;
                                precio unitario: <?= $money($extra['unit_price'] ?? null) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </th>
                    <td class="amount"><?= $money($item['line_total'] ?? null) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <section aria-labelledby="summary-heading">
        <h2 id="summary-heading">Resumen</h2>
        <dl class="totals">
            <?php foreach (['total' => 'Total', 'paid_amount' => 'Pagos válidos', 'refunded_amount' => 'Reembolsado', 'net_paid' => 'Neto cobrado', 'balance' => 'Saldo'] as $field => $label): ?>
            <div><dt><?= $label ?></dt><dd><?= $money($summary[$field] ?? null) ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <p>Estado de pago: <?= $escape($summary['payment_status'] ?? 'No disponible') ?></p>
    </section>
    <section aria-labelledby="payments-heading">
        <h2 id="payments-heading">Historial de pagos</h2>
        <?php if (empty($data['payments'])): ?><p>Sin pagos registrados.</p><?php endif; ?>
        <?php foreach ($data['payments'] ?? [] as $payment): ?>
        <article class="payment">
            <h3>Pago <?= $escape($payment['folio'] ?? '') ?></h3>
            <p>Fecha efectiva: <?= $escape($date($payment['effective_at'] ?? null)) ?></p>
            <p>Medio: <?= $escape($payment['method'] ?? '') ?></p>
            <p>Importe: <?= $money($payment['amount'] ?? null) ?></p>
            <p>Estado: <?= $escape($payment['status'] ?? '') ?></p>
            <?php if (isset($payment['refunded_amount'])): ?><p>Reembolsado: <?= $money($payment['refunded_amount']) ?></p><?php endif; ?>
            <?php if (!empty($payment['reference'])): ?><p>Referencia: <?= $escape($payment['reference']) ?></p><?php endif; ?>
        </article>
        <?php endforeach; ?>
    </section>
    <?php if (!empty($data['refunds'])): ?>
    <section aria-labelledby="refunds-heading">
        <h2 id="refunds-heading">Historial de reembolsos</h2>
        <?php foreach ($data['refunds'] as $refund): ?>
        <article class="payment">
            <h3>Reembolso <?= $escape($refund['folio'] ?? '') ?></h3>
            <p>Fecha efectiva: <?= $escape($date($refund['effective_at'] ?? null)) ?></p>
            <p>Importe: <?= $money($refund['amount'] ?? null) ?></p>
            <p>Estado: <?= $escape($refund['status'] ?? '') ?></p>
            <?php if (!empty($refund['reason'])): ?><p>Motivo: <?= $escape($refund['reason']) ?></p><?php endif; ?>
        </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <footer><p><strong>Comprobante interno; no es factura fiscal</strong></p></footer>
</main>
</body>
</html>
