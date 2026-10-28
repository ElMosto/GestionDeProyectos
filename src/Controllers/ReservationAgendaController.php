<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ReservationService;
use App\Validation\ProjectInput;

/** §13: proyección aditiva; /agenda conserva el contrato de tareas de fase 2. */
final class ReservationAgendaController
{
    public function __construct(private readonly ReservationService $reservations)
    {
    }

    public function index(Request $request): Response
    {
        $result = $this->reservations->agenda(ProjectInput::query($request->query));
        return Response::data($result['data'], $result['meta']);
    }
}
