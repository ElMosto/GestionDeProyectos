<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Exports\SaleTicket;
use App\Services\ProjectAudit;
use App\Services\SaleTicketService;
use App\Validation\ProjectInput;

/** §10/§16: imprimir es leer una vista autorizada, nunca registrar un movimiento. */
final class SaleTicketsController
{
    public function __construct(
        private readonly SaleTicketService $tickets,
        private readonly SaleTicket $ticket,
        private readonly ProjectAudit $audit,
    ) {
    }

    public function show(Request $request): Response
    {
        $paper = $request->queryParam('paper', '80mm');
        if (!is_string($paper) || !in_array($paper, ['80mm', 'A4'], true)) {
            throw ApiException::validation(['paper' => 'Selecciona 80mm o A4.']);
        }
        $id = ProjectInput::id($request->param('id'));
        // ticketData comprueba permisos actuales y entrega solo los campos permitidos.
        $data = $this->tickets->data($id);
        $html = $this->ticket->render($data, $paper);
        $this->audit->bind($request);
        $this->audit->record('SALE_TICKET_VIEW', 'sale', $id, null, ['paper' => $paper]);
        return Response::html($html);
    }
}
