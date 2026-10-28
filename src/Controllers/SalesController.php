<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ProjectAudit;
use App\Services\SaleService;
use App\Support\Version;
use App\Validation\ProjectInput;
use App\Validation\SaleInput;

final class SalesController
{
    public function __construct(private readonly SaleService $sales, private readonly ProjectAudit $audit)
    {
    }

    public function index(Request $request): Response
    {
        $page = $this->sales->list(SaleInput::query($request->query));
        return Response::data($page['data'], $page['meta']);
    }

    public function show(Request $request): Response
    {
        return Response::data($this->sales->show(ProjectInput::id($request->param('id'))));
    }

    public function store(Request $request): Response
    {
        $this->audit->bind($request);
        return Response::created($this->sales->create(SaleInput::fields($request->body), $request->header('idempotency-key')));
    }

    public function update(Request $request): Response
    {
        $this->audit->bind($request);
        return Response::data($this->sales->update(ProjectInput::id($request->param('id')),
            SaleInput::fields($request->body, true), Version::fromRequest($request), $request->header('idempotency-key')));
    }

    public function issue(Request $request): Response
    {
        $this->audit->bind($request);
        ProjectInput::fields($request->body, [], [], true);
        return Response::data($this->sales->issueSale(ProjectInput::id($request->param('id')),
            Version::fromRequest($request), $request->header('idempotency-key')));
    }

    public function cancel(Request $request): Response
    {
        $this->audit->bind($request);
        $fields = ProjectInput::fields($request->body, ['reason' => 'text255'], ['reason'], true);
        return Response::data($this->sales->cancelSale(ProjectInput::id($request->param('id')), $fields['reason'],
            Version::fromRequest($request), $request->header('idempotency-key')));
    }
}
