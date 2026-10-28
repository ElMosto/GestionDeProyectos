<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ProjectAudit;
use App\Services\ProjectChargeService;
use App\Support\Version;
use App\Validation\ProjectInput;

final class ProjectChargesController
{
    public function __construct(private readonly ProjectChargeService $charges, private readonly ProjectAudit $audit)
    {
    }

    public function index(Request $request): Response
    {
        $result = $this->charges->list(ProjectInput::id($request->param('id')), ProjectInput::query($request->query));
        return Response::data($result['data'], $result['meta']);
    }

    public function store(Request $request): Response
    {
        $this->audit->bind($request);
        $schema = ProjectInput::CHARGE;
        unset($schema['status']);
        $fields = ProjectInput::fields($request->body, $schema, ['concept', 'quantity', 'unit_price', 'charge_date']);
        return Response::created($this->charges->create(ProjectInput::id($request->param('id')), $fields, $request->header('idempotency-key')));
    }

    public function update(Request $request): Response
    {
        $this->audit->bind($request);
        $fields = ProjectInput::fields($request->body, ProjectInput::CHARGE, [], true);
        return Response::data($this->charges->update(ProjectInput::id($request->param('id')), $fields,
            Version::fromRequest($request), $request->header('idempotency-key')));
    }
}
