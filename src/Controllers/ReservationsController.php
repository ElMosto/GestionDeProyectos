<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ProjectAudit;
use App\Services\ReservationService;
use App\Support\Version;
use App\Validation\ProjectInput;
use App\Validation\ReservationInput;

final class ReservationsController
{
    public function __construct(private readonly ReservationService $reservations, private readonly ProjectAudit $audit)
    {
    }

    public function index(Request $request): Response
    {
        $page = $this->reservations->list(ReservationInput::query($request->query));
        return Response::data($page['data'], $page['meta']);
    }

    public function show(Request $request): Response
    {
        return Response::data($this->reservations->show(ProjectInput::id($request->param('id'))));
    }

    public function store(Request $request): Response
    {
        $this->audit->bind($request);
        return Response::created($this->reservations->create(ReservationInput::fields($request->body, 'create'),
            $request->header('idempotency-key')));
    }

    public function update(Request $request): Response
    {
        $this->audit->bind($request);
        $input = ReservationInput::fields($request->body, 'notes');
        return Response::data($this->reservations->updateNotes(ProjectInput::id($request->param('id')), $input['notes'],
            Version::fromRequest($request), $request->header('idempotency-key')));
    }

    public function reschedule(Request $request): Response
    {
        $this->audit->bind($request);
        return Response::data($this->reservations->reschedule(ProjectInput::id($request->param('id')),
            ReservationInput::fields($request->body, 'reschedule'), Version::fromRequest($request), $request->header('idempotency-key')));
    }

    public function transition(Request $request): Response
    {
        $this->audit->bind($request);
        $input = ReservationInput::fields($request->body, 'transition');
        return Response::data($this->reservations->transition(ProjectInput::id($request->param('id')), $input['status'],
            Version::fromRequest($request), $request->header('idempotency-key'), $input['reason'] ?? null));
    }

    public function cancel(Request $request): Response
    {
        $this->audit->bind($request);
        $input = ReservationInput::fields($request->body, 'cancel');
        return Response::data($this->reservations->cancel(ProjectInput::id($request->param('id')), $input['reason'],
            Version::fromRequest($request), $request->header('idempotency-key')));
    }
}
