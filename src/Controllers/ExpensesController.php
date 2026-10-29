<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ExpenseService;
use App\Services\ProjectAudit;
use App\Validation\ExpenseInput;
use App\Validation\FinanceInput;
use App\Validation\ProjectInput;

final class ExpensesController
{
    public function __construct(private readonly ExpenseService $service, private readonly ProjectAudit $audit)
    {
    }

    public function index(Request $request): Response
    {
        $result = $this->service->listing(FinanceInput::query($request->query, 'expenses'));
        return Response::data($result['data'], $result['meta']);
    }

    public function show(Request $request): Response
    {
        return Response::data($this->service->show(ProjectInput::id($request->param('id'))));
    }

    public function create(Request $request): Response
    {
        $this->audit->bind($request);
        return Response::created($this->service->createExpense(ExpenseInput::fields($request, true), $request->header('idempotency-key')));
    }

    public function update(Request $request): Response
    {
        $this->audit->bind($request);
        return Response::data($this->service->updateExpense(ProjectInput::id($request->param('id')), ExpenseInput::fields($request, false), $request->header('idempotency-key')));
    }
}
