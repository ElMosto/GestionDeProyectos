<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Policies\Authorizer;
use App\Policies\Capabilities;
use App\Services\ClientService;
use App\Validation\ClientValidation;

/**
 * Clientes (§7, §15).
 *
 * Autoriza la capacidad global antes de llamar al servicio; el permiso por
 * objeto (creador vs. administrador) y el recorte de campos sensibles viven en
 * `ClientService`.
 */
final class ClientsController
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly ClientService $clients,
    ) {
    }

    /**
     * `GET /api/v1/clients` — `clients.view`.
     */
    public function index(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::CLIENTS_VIEW);
        $actor = $this->authorizer->requireUser();

        $query = ClientValidation::listQuery($request->query);
        $result = $this->clients->list($query, $actor);

        return Response::data($result['data'], $result['meta']);
    }

    /**
     * `GET /api/v1/clients/{id}` — `clients.view`; invisible → 404.
     */
    public function show(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::CLIENTS_VIEW);
        $actor = $this->authorizer->requireUser();

        return Response::data($this->clients->show(ClientValidation::id($request->param('id')), $actor));
    }

    /**
     * `POST /api/v1/clients` — `clients.create`; cualquier usuario activo.
     */
    public function store(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::CLIENTS_CREATE);
        $actor = $this->authorizer->requireUser();

        $input = ClientValidation::create($request->body);
        $result = $this->clients->create($input, $actor);

        $meta = $result['warnings'] === [] ? [] : ['warnings' => $result['warnings']];

        return Response::created($result['client'], $meta);
    }

    /**
     * `PATCH /api/v1/clients/{id}` — `clients.editAny` o `clients.editOwn`.
     */
    public function update(Request $request): Response
    {
        $this->authorizer->requireUser();

        if (
            !$this->authorizer->can(Capabilities::CLIENTS_EDIT_ANY)
            && !$this->authorizer->can(Capabilities::CLIENTS_EDIT_OWN)
        ) {
            throw ApiException::forbidden();
        }

        $actor = $this->authorizer->requireUser();
        $input = ClientValidation::update($request->body);
        $updated = $this->clients->update(ClientValidation::id($request->param('id')), $input, $actor);

        return Response::data($updated);
    }

    /**
     * `POST /api/v1/clients/{id}/deactivate` — solo `clients.deactivate` (ADMIN).
     */
    public function deactivate(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::CLIENTS_DEACTIVATE);
        $actor = $this->authorizer->requireUser();

        return Response::data($this->clients->deactivate(
            ClientValidation::id($request->param('id')),
            $actor,
        ));
    }
}
