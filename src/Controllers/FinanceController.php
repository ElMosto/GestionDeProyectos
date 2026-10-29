<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\FinanceService;
use App\Validation\FinanceInput;

final class FinanceController
{
    public function __construct(private readonly FinanceService $service)
    {
    }

    public function summary(Request $request): Response
    {
        return $this->report('summary', $request);
    }

    public function cashflow(Request $request): Response
    {
        return $this->report('cashflow', $request);
    }

    public function receivables(Request $request): Response
    {
        return $this->report('receivables', $request);
    }

    public function transactions(Request $request): Response
    {
        return $this->report('transactions', $request);
    }

    public function documents(Request $request): Response
    {
        return $this->report('documents', $request);
    }

    private function report(string $kind, Request $request): Response
    {
        $result = $this->service->report($kind, FinanceInput::query($request->query));
        return Response::data($result['data'], $result['meta']);
    }
}
