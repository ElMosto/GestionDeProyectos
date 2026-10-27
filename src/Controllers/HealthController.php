<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

final class HealthController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function root(Request $request): Response
    {
        $accept = (string) $request->header('accept');

        if (str_contains($accept, 'text/html')) {
            return Response::redirect('/app.html');
        }

        return Response::data([
            'app' => (string) $this->config->get('app.name', 'Gestión'),
            'version' => (string) $this->config->get('app.version', '0.0.0'),
            'status' => 'ok',
        ]);
    }

    public function health(Request $request): Response
    {
        if ($request->method !== 'GET') {
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Método no permitido.');
        }

        return Response::data(
            ['status' => 'ok', 'time' => gmdate('c')],
            ['request_id' => $request->requestId]
        );
    }
}
