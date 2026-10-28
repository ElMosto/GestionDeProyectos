<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ProjectAudit;
use App\Services\ProjectService;
use App\Support\Version;
use App\Validation\ProjectInput;

final class ProjectsController
{
    public function __construct(private readonly ProjectService $projects, private readonly ProjectAudit $audit)
    {
    }

    public function index(Request $request): Response
    {
        $result = $this->projects->list(ProjectInput::query($request->query));
        return Response::data($result['data'], $result['meta']);
    }

    public function show(Request $request): Response
    {
        return Response::data($this->projects->show(ProjectInput::id($request->param('id'))));
    }

    public function store(Request $request): Response
    {
        $this->audit->bind($request);
        $fields = ProjectInput::fields($request->body, ProjectInput::PROJECT, ['name', 'description', 'type']);
        return Response::created($this->projects->create($fields, $request->header('idempotency-key')));
    }

    public function update(Request $request): Response
    {
        $this->audit->bind($request);
        $fields = ProjectInput::fields($request->body, ProjectInput::PROJECT, [], true);
        return Response::data($this->projects->update(ProjectInput::id($request->param('id')), $fields,
            Version::fromRequest($request), $request->header('idempotency-key')));
    }

    public function transition(Request $request): Response
    {
        $this->audit->bind($request);
        $input = ProjectInput::fields($request->body, ['status' => 'BORRADOR|PLANIFICADO|EN_CURSO|PAUSADO|FINALIZADO|CANCELADO',
            'reason' => '?text2000', 'exception' => 'bool'], ['status'], true);
        return Response::data($this->projects->transition(ProjectInput::id($request->param('id')), $input, Version::fromRequest($request)));
    }

    public function members(Request $request): Response
    {
        $result = $this->projects->members(ProjectInput::id($request->param('id')), ProjectInput::query($request->query));
        return Response::data($result['data'], $result['meta']);
    }

    public function replaceMembers(Request $request): Response
    {
        $this->audit->bind($request);
        $input = ProjectInput::fields($request->body, ['user_ids' => 'ids'], ['user_ids'], true);
        $result = $this->projects->replaceMembers(ProjectInput::id($request->param('id')), $input['user_ids'], Version::fromRequest($request));
        return Response::data($result['data'], $result['meta']);
    }

    public function payments(Request $request): Response
    {
        $result = $this->projects->payments(ProjectInput::id($request->param('id')), ProjectInput::query($request->query));
        return Response::data($result['data'], $result['meta']);
    }
}
