<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Core\Config;
use App\Core\Container;

/**
 * Servicios de infraestructura + los que registre cada módulo en src/Core/providers/.
 * Cada módulo añade su propio archivo para no editar este.
 */
return static function (Container $container, string $root): void {
    $container->singleton(HealthController::class, static fn (Container $c): HealthController => new HealthController(
        $c->get(Config::class)
    ));

    foreach (glob(__DIR__ . '/providers/*.php') ?: [] as $file) {
        /** @var callable(Container, string):void $register */
        $register = require $file;
        $register($container, $root);
    }
};
