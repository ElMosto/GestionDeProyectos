<?php

declare(strict_types=1);

use App\Auth\AuditLogger;
use App\Controllers\ClientsController;
use App\Core\Container;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Repositories\ClientRepository;
use App\Services\ClientService;

/**
 * Servicios de clientes (fase 1).
 * `services.php` incluye todos los archivos de src/Core/providers/.
 */
return static function (Container $container, string $root): void {
    $container->singleton(
        ClientRepository::class,
        static fn (Container $c): ClientRepository => new ClientRepository($c->get(Database::class))
    );

    $container->singleton(
        ClientService::class,
        static fn (Container $c): ClientService => new ClientService(
            $c->get(ClientRepository::class),
            $c->get(AuditLogger::class),
            $c->get(Database::class),
            $c->get(Authorizer::class),
        )
    );

    $container->singleton(
        ClientsController::class,
        static fn (Container $c): ClientsController => new ClientsController(
            $c->get(Authorizer::class),
            $c->get(ClientService::class),
        )
    );
};
