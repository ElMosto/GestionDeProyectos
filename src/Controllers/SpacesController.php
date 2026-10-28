<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ProjectAudit;
use App\Services\SpaceService;
use App\Support\Version;
use App\Validation\ProjectInput;
use App\Validation\ReservationInput;
use App\Validation\SpaceInput;

final class SpacesController
{
    public function __construct(private readonly SpaceService $spaces, private readonly ProjectAudit $audit)
    {
    }

    public function index(Request $request): Response
    {
        $query = ProjectInput::query(array_intersect_key($request->query, array_flip(['limit', 'offset', 'q'])));
        if (isset($request->query['active']) && $request->query['active'] !== '') {
            if (!in_array($request->query['active'], ['0', '1'], true)) {
                throw ApiException::validation(['active' => 'Usa 0 o 1.']);
            }
            $query['active'] = $request->query['active'] === '1';
        }
        $page = $this->spaces->list($query);
        return Response::data($page['data'], $page['meta']);
    }

    public function show(Request $request): Response
    {
        return Response::data($this->spaces->show(ProjectInput::id($request->param('id'))));
    }

    public function store(Request $request): Response
    {
        $this->audit->bind($request);
        return Response::created($this->spaces->create(SpaceInput::fields($request->body)));
    }

    public function update(Request $request): Response
    {
        $this->audit->bind($request);
        return Response::data($this->spaces->update(ProjectInput::id($request->param('id')), SpaceInput::fields($request->body, true),
            Version::fromRequest($request)));
    }

    public function hours(Request $request): Response
    {
        $this->audit->bind($request);
        return Response::data($this->spaces->replaceHours(ProjectInput::id($request->param('id')), SpaceInput::hours($request->body),
            Version::fromRequest($request)));
    }

    public function availability(Request $request): Response
    {
        return Response::data($this->spaces->availability(ProjectInput::id($request->param('id')),
            ReservationInput::instant($request->query['from'] ?? null, 'from'),
            ReservationInput::instant($request->query['to'] ?? null, 'to')));
    }
}
