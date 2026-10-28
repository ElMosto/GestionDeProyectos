<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ProjectAudit;
use App\Services\SpaceBlockService;
use App\Support\Version;
use App\Validation\ProjectInput;
use App\Validation\ReservationInput;

final class SpaceBlocksController
{
    public function __construct(private readonly SpaceBlockService $blocks, private readonly ProjectAudit $audit)
    {
    }

    public function index(Request $request): Response
    {
        $page = $this->blocks->list(ReservationInput::query($request->query));
        return Response::data($page['data'], $page['meta']);
    }

    public function store(Request $request): Response
    {
        $this->audit->bind($request);
        return Response::created($this->blocks->create(ReservationInput::fields($request->body, 'block'),
            $request->header('idempotency-key')));
    }

    public function cancel(Request $request): Response
    {
        $this->audit->bind($request);
        $input = ReservationInput::fields($request->body, 'unblock');
        return Response::data($this->blocks->cancel(ProjectInput::id($request->param('id')),
            Version::fromRequest($request), $request->header('idempotency-key'), $input['reason'] ?? null));
    }
}
