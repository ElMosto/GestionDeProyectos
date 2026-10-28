<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\PaymentService;
use App\Services\ProjectAudit;
use App\Validation\PaymentInput;
use App\Validation\ProjectInput;

final class PaymentsController
{
    public function __construct(private readonly PaymentService $payments, private readonly ProjectAudit $audit)
    {
    }

    public function saleHistory(Request $request): Response
    {
        $result = $this->payments->history('sale', ProjectInput::id($request->param('id')), ProjectInput::query($request->query));
        return Response::data($result['data'], $result['meta']);
    }

    public function salePayment(Request $request): Response
    {
        return $this->register($request, 'sale');
    }

    public function projectPayment(Request $request): Response
    {
        return $this->register($request, 'project');
    }

    public function markPaid(Request $request): Response
    {
        $this->audit->bind($request);
        return Response::data($this->payments->registerPayment('sale', ProjectInput::id($request->param('id')),
            PaymentInput::fields($request, 'mark-paid'), $request->header('idempotency-key'), true));
    }

    public function voidPayment(Request $request): Response
    {
        $this->audit->bind($request);
        return Response::data($this->payments->voidPayment(ProjectInput::id($request->param('id')),
            PaymentInput::fields($request, 'void'), $request->header('idempotency-key')));
    }

    public function refund(Request $request): Response
    {
        $this->audit->bind($request);
        return Response::created($this->payments->refundPayment(ProjectInput::id($request->param('id')),
            PaymentInput::fields($request, 'refund'), $request->header('idempotency-key')));
    }

    public function refundHistory(Request $request): Response
    {
        $result = $this->payments->refundHistory(ProjectInput::id($request->param('id')), ProjectInput::query($request->query));
        return Response::data($result['data'], $result['meta']);
    }

    private function register(Request $request, string $kind): Response
    {
        $this->audit->bind($request);
        return Response::created($this->payments->registerPayment($kind, ProjectInput::id($request->param('id')),
            PaymentInput::fields($request, 'payment'), $request->header('idempotency-key')));
    }
}
