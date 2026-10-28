<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\MeetingService;
use App\Services\ProjectAudit;
use App\Support\Version;
use App\Validation\MeetingInput;
use App\Validation\ProjectInput;

final class MeetingsController
{
    public function __construct(private readonly MeetingService $meetings, private readonly ProjectAudit $audit)
    {
    }

    public function index(Request $request): Response
    {
        $page = $this->meetings->list(MeetingInput::query($request->query));
        return Response::data($page['data'], $page['meta']);
    }

    public function agenda(Request $request): Response
    {
        $page = $this->meetings->agenda(MeetingInput::query($request->query, true));
        return Response::data($page['data'], $page['meta']);
    }

    public function show(Request $request): Response
    {
        return Response::data($this->meetings->show(ProjectInput::id($request->param('id'))));
    }

    public function store(Request $request): Response
    {
        $this->audit->bind($request);
        return Response::created($this->meetings->create(MeetingInput::fields($request->body, 'create'),
            $request->header('idempotency-key')));
    }

    public function update(Request $request): Response
    {
        $this->audit->bind($request);
        return Response::data($this->meetings->update(ProjectInput::id($request->param('id')),
            Version::fromRequest($request), MeetingInput::fields($request->body, 'update'),
            $request->header('idempotency-key')));
    }

    public function cancel(Request $request): Response
    {
        $this->audit->bind($request);
        $input = MeetingInput::fields($request->body, 'cancel');
        return Response::data($this->meetings->cancel(ProjectInput::id($request->param('id')),
            Version::fromRequest($request), $input['reason'], $request->header('idempotency-key')));
    }

    public function respond(Request $request): Response
    {
        $this->audit->bind($request);
        return Response::data($this->meetings->respond(ProjectInput::id($request->param('id')),
            Version::fromRequest($request), MeetingInput::fields($request->body, 'response'),
            $request->header('idempotency-key')));
    }
}
