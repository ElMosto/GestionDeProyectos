<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Policies\Authorizer;
use App\Policies\Capabilities;

/**
 * Dispositivos del usuario (D-08): `GET/POST /me/tokens`, `DELETE /me/tokens/{id}`.
 *
 * El token en claro se devuelve **una sola vez**, al crearlo. En base solo queda su
 * SHA-256 y la API nunca lo vuelve a exponer.
 */
final class TokenController
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly TokenService $tokens,
    ) {
    }

    /**
     * `GET /api/v1/me/tokens`
     */
    public function index(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::ME_TOKENS);
        $userId = (int) $this->authorizer->requireUser()['id'];

        $tokens = array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'created_at' => UserPresenter::iso($row['created_at'] ?? null),
            'last_used_at' => UserPresenter::iso($row['last_used_at'] ?? null),
            'expires_at' => UserPresenter::iso($row['expires_at'] ?? null),
        ], $this->tokens->listFor($userId));

        return Response::data($tokens, ['total' => count($tokens)]);
    }

    /**
     * `POST /api/v1/me/tokens` — cuerpo `{ "name": "Pixel 8" }`.
     */
    public function store(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::ME_TOKENS);
        $userId = (int) $this->authorizer->requireUser()['id'];

        $name = $request->input('name');
        $name = is_string($name) ? trim($name) : '';

        if ($name === '' || mb_strlen($name) > 80) {
            throw ApiException::validation([
                'name' => 'Ponle un nombre al dispositivo (máximo 80 caracteres).',
            ]);
        }

        $issued = $this->tokens->issue($userId, $name);

        return Response::created([
            'id' => (string) $issued['id'],
            'name' => $issued['name'],
            'token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_at' => UserPresenter::iso($issued['expires_at']),
        ]);
    }

    /**
     * `DELETE /api/v1/me/tokens/{id}` — revoca un token propio. Ajeno o inexistente: 404.
     */
    public function destroy(Request $request): Response
    {
        $this->authorizer->requireCapability(Capabilities::ME_TOKENS);
        $userId = (int) $this->authorizer->requireUser()['id'];

        $id = $request->param('id');

        if (!ctype_digit($id)) {
            throw ApiException::notFound('Token no encontrado.');
        }

        $this->tokens->revoke($userId, (int) $id);

        return Response::noContent();
    }
}
