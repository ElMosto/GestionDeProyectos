<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Exports\ProjectGantt;
use App\Exports\ProjectTicket;
use App\Services\ProjectAudit;
use App\Services\ProjectOutputService;
use App\Support\Dates;
use App\Validation\ProjectInput;
use InvalidArgumentException;

final class ProjectOutputsController
{
    public function __construct(
        private readonly ProjectOutputService $projects,
        private readonly ProjectGantt $gantt,
        private readonly ProjectTicket $ticket,
        private readonly ProjectAudit $audit,
    ) {
    }

    public function gantt(Request $request): Response
    {
        $id = ProjectInput::id($request->param('id'));
        $from = Dates::date($request->queryParam('from'), 'from');
        $to = Dates::date($request->queryParam('to'), 'to');
        Dates::range($from, $to);
        $data = $this->projects->data($id);
        try {
            $xlsx = $this->gantt->render($data, $from, $to);
        } catch (InvalidArgumentException $error) {
            throw ApiException::validation(['range' => $error->getMessage()]);
        }
        $this->audit->bind($request);
        $this->audit->record('PROJECT_GANTT_VIEW', 'project', $id, null, ['from' => $from, 'to' => $to]);
        return Response::download($xlsx, 'proyecto_' . $data['project']['folio'] . '_gantt.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function ticket(Request $request): Response
    {
        $id = ProjectInput::id($request->param('id'));
        $paper = $request->queryParam('paper', '80mm');
        if (!is_string($paper) || !in_array($paper, ['80mm', 'A4'], true)) {
            throw ApiException::validation(['paper' => 'Selecciona 80mm o A4.']);
        }
        $data = $this->projects->data($id, true);
        $html = $this->ticket->render($data, $paper);
        $this->audit->bind($request);
        $this->audit->record('PROJECT_TICKET_VIEW', 'project', $id, null, ['paper' => $paper]);
        return Response::html($html);
    }
}
