<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Core\Container;
use App\Core\Router;

/**
 * Rutas de infraestructura + las que registre cada módulo en src/Core/routes/.
 * Cada módulo añade su propio archivo para no editar este.
 */
return static function (Router $router, Container $container): void {
    $router->get('/', [HealthController::class, 'root']);
    $router->get('/api/v1/health', [HealthController::class, 'health']);

    foreach (glob(__DIR__ . '/routes/*.php') ?: [] as $file) {
        /** @var callable(Router, Container):void $register */
        $register = require $file;
        $register($router, $container);
    }
};
