<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Policies\Authorizer;
use App\Policies\Capabilities;
use App\Services\UserService;
use App\Validation\UserValidation;

/**
 * Administración de cuentas (§5, §15).
 *
 * El controlador solo valida forma, autoriza la capacidad global y elige el
 * código HTTP. Las reglas (rol, último administrador, tokens, auditoría) viven
 * en `UserService`.
 */
final class UsersController
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly UserService $users,
        private readonly Config $config,
    ) {
    }

    /**
     * `GET /api/v1/users` — solo `users.manage`.
     */
    public function index(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::USERS_MANAGE);

        $query = UserValidation::listQuery($request->query);
        $result = $this->users->list($query);

        return Response::data($result['data'], $result['meta']);
    }

    /**
     * `GET /api/v1/users/directory` — cualquier usuario activo.
     */
    public function directory(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::USERS_DIRECTORY);

        return Response::data($this->users->directory());
    }

    /**
     * `POST /api/v1/users` — solo `users.manage`.
     */
    public function store(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::USERS_MANAGE);
        $actor = $this->authorizer->requireUser();

        $minLength = max(8, (int) $this->config->get('security.password_min_length', 12));
        $input = UserValidation::create($request->body, $minLength);

        return Response::created($this->users->create($input, (int) $actor['id']));
    }

    /**
     * `GET /api/v1/users/{id}` — solo `users.manage`.
     */
    public function show(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::USERS_MANAGE);

        return Response::data($this->users->show(UserValidation::id($request->param('id'))));
    }

    /**
     * `PATCH /api/v1/users/{id}` — solo `users.manage`.
     */
    public function update(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::USERS_MANAGE);
        $actor = $this->authorizer->requireUser();

        $input = UserValidation::update($request->body);
        $updated = $this->users->update(UserValidation::id($request->param('id')), $input, $actor);

        return Response::data($updated);
    }

    /**
     * `POST /api/v1/users/{id}/deactivate` — solo `users.manage`.
     */
    public function deactivate(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::USERS_MANAGE);
        $actor = $this->authorizer->requireUser();

        return Response::data($this->users->deactivate(
            UserValidation::id($request->param('id')),
            (int) $actor['id'],
        ));
    }

    /**
     * `POST /api/v1/users/{id}/reactivate` — solo `users.manage`.
     */
    public function reactivate(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::USERS_MANAGE);
        $actor = $this->authorizer->requireUser();

        return Response::data($this->users->reactivate(
            UserValidation::id($request->param('id')),
            (int) $actor['id'],
        ));
    }

    /**
     * `POST /api/v1/users/{id}/reset-password` — solo `users.manage`.
     */
    public function resetPassword(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::USERS_MANAGE);
        $actor = $this->authorizer->requireUser();

        return Response::data($this->users->resetPassword(
            UserValidation::id($request->param('id')),
            (int) $actor['id'],
        ));
    }
}
