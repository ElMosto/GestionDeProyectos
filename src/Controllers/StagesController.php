<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ProjectAudit;
use App\Services\StageService;
use App\Support\Version;
use App\Validation\ProjectInput;

final class StagesController
{
    public function __construct(private readonly StageService $stages, private readonly ProjectAudit $audit)
    {
    }

    public function index(Request $request): Response
    {
        $result = $this->stages->list(ProjectInput::id($request->param('id')), ProjectInput::query($request->query));
        return Response::data($result['data'], $result['meta']);
    }

    public function store(Request $request): Response
    {
        $this->audit->bind($request);
        $schema = ProjectInput::STAGE;
        unset($schema['status']);
        $fields = ProjectInput::fields($request->body, $schema, ['name']);
        return Response::created($this->stages->create(ProjectInput::id($request->param('id')), $fields));
    }

    public function update(Request $request): Response
    {
        $this->audit->bind($request);
        $fields = ProjectInput::fields($request->body, ProjectInput::STAGE, [], true);
        return Response::data($this->stages->update(ProjectInput::id($request->param('id')), $fields, Version::fromRequest($request)));
    }

    public function reorder(Request $request): Response
    {
        $this->audit->bind($request);
        $input = ProjectInput::fields($request->body, ['stage_ids' => 'ids'], ['stage_ids'], true);
        $result = $this->stages->reorder(ProjectInput::id($request->param('id')), $input['stage_ids'], Version::fromRequest($request));
        return Response::data($result['data'], $result['meta']);
    }
}
