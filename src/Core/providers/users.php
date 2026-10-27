<?php

declare(strict_types=1);

use App\Auth\AuditLogger;
use App\Auth\TemporaryPasswordService;
use App\Auth\TokenService;
use App\Auth\UserPresenter;
use App\Controllers\UsersController;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Policies\LastAdminGuard;
use App\Repositories\RoleRepository;
use App\Repositories\UserAdminRepository;
use App\Services\UserService;

/**
 * Servicios de administración de cuentas (fase 1).
 * `services.php` incluye todos los archivos de src/Core/providers/.
 */
return static function (Container $container, string $root): void {
    $container->singleton(
        RoleRepository::class,
        static fn (Container $c): RoleRepository => new RoleRepository($c->get(Database::class))
    );

    $container->singleton(
        UserAdminRepository::class,
        static fn (Container $c): UserAdminRepository => new UserAdminRepository($c->get(Database::class))
    );

    $container->singleton(
        UserService::class,
        static fn (Container $c): UserService => new UserService(
            $c->get(UserAdminRepository::class),
            $c->get(RoleRepository::class),
            $c->get(AuditLogger::class),
            $c->get(TokenService::class),
            $c->get(TemporaryPasswordService::class),
            $c->get(LastAdminGuard::class),
            $c->get(Database::class),
            $c->get(UserPresenter::class),
        )
    );

    $container->singleton(
        UsersController::class,
        static fn (Container $c): UsersController => new UsersController(
            $c->get(Authorizer::class),
            $c->get(UserService::class),
            $c->get(Config::class),
        )
    );
};
