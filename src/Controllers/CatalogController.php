<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\CatalogService;
use App\Services\ProjectAudit;
use App\Support\Version;
use App\Validation\CatalogInput;
use App\Validation\ProjectInput;

final class CatalogController
{
    public function __construct(private readonly CatalogService $catalog, private readonly ProjectAudit $audit)
    {
    }

    private function kind(Request $request): string
    {
        return str_starts_with($request->path, '/api/v1/products') ? 'products' : 'extras';
    }

    public function index(Request $request): Response
    {
        $page = $this->catalog->list($this->kind($request), CatalogInput::query($request->query));
        return Response::data($page['data'], $page['meta']);
    }

    public function show(Request $request): Response
    {
        return Response::data($this->catalog->show($this->kind($request), ProjectInput::id($request->param('id'))));
    }

    public function store(Request $request): Response
    {
        $this->audit->bind($request);
        $kind = $this->kind($request);
        return Response::created($this->catalog->create($kind, CatalogInput::fields($request->body, $kind)));
    }

    public function update(Request $request): Response
    {
        $this->audit->bind($request);
        $kind = $this->kind($request);
        return Response::data($this->catalog->update($kind, ProjectInput::id($request->param('id')),
            CatalogInput::fields($request->body, $kind, true), Version::fromRequest($request)));
    }

    public function replaceExtras(Request $request): Response
    {
        $this->audit->bind($request);
        $input = ProjectInput::fields($request->body, ['extra_ids' => 'ids'], ['extra_ids'], true);
        return Response::data($this->catalog->replaceExtras(ProjectInput::id($request->param('id')),
            $input['extra_ids'], Version::fromRequest($request)));
    }
}
