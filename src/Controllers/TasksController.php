<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ProjectAudit;
use App\Services\TaskService;
use App\Support\Version;
use App\Validation\ProjectInput;

final class TasksController
{
    public function __construct(private readonly TaskService $tasks, private readonly ProjectAudit $audit)
    {
    }

    public function index(Request $request): Response
    {
        $result = $this->tasks->list(ProjectInput::query($request->query));
        return Response::data($result['data'], $result['meta']);
    }

    public function stageIndex(Request $request): Response
    {
        $result = $this->tasks->stageList(ProjectInput::id($request->param('id')), ProjectInput::query($request->query));
        return Response::data($result['data'], $result['meta']);
    }

    public function show(Request $request): Response
    {
        return Response::data($this->tasks->show(ProjectInput::id($request->param('id'))));
    }

    public function store(Request $request): Response
    {
        return $this->create($request, ProjectInput::id($request->param('id')));
    }

    public function standalone(Request $request): Response
    {
        return $this->create($request, null);
    }

    private function create(Request $request, ?int $stageId): Response
    {
        $this->audit->bind($request);
        $schema = ProjectInput::TASK;
        unset($schema['progress'], $schema['actual_start'], $schema['actual_end']);
        $fields = ProjectInput::fields($request->body, $schema, ['title', 'assignee_id']);
        return Response::created($this->tasks->create($stageId, $fields));
    }

    public function update(Request $request): Response
    {
        $this->audit->bind($request);
        $fields = ProjectInput::fields($request->body, ProjectInput::TASK, [], true);
        return Response::data($this->tasks->update(ProjectInput::id($request->param('id')), $fields, Version::fromRequest($request)));
    }

    public function transition(Request $request): Response
    {
        $this->audit->bind($request);
        $input = ProjectInput::fields($request->body, ['status' => 'PENDIENTE|EN_CURSO|BLOQUEADA|COMPLETADA|CANCELADA',
            'reason' => '?text2000'], ['status'], true);
        return Response::data($this->tasks->transition(ProjectInput::id($request->param('id')), $input, Version::fromRequest($request)));
    }

    public function comments(Request $request): Response
    {
        $result = $this->tasks->comments(ProjectInput::id($request->param('id')), ProjectInput::query($request->query));
        return Response::data($result['data'], $result['meta']);
    }

    public function comment(Request $request): Response
    {
        $this->audit->bind($request);
        $input = ProjectInput::fields($request->body, ['body' => 'text10000'], ['body']);
        return Response::created($this->tasks->comment(ProjectInput::id($request->param('id')), $input['body']));
    }

    public function agenda(Request $request): Response
    {
        $result = $this->tasks->agenda(ProjectInput::query($request->query));
        return Response::data($result['data'], $result['meta']);
    }
}
