<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuditLogsService;
use App\Validation\FinanceInput;

final class AuditLogsController
{
    public function __construct(private readonly AuditLogsService $service)
    {
    }

    public function index(Request $request): Response
    {
        $result = $this->service->listing(FinanceInput::query($request->query));
        return Response::data($result['data'], $result['meta']);
    }
}
