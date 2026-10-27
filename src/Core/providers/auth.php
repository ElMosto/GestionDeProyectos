<?php

declare(strict_types=1);

use App\Auth\AuditLogger;
use App\Auth\AuthContext;
use App\Auth\AuthController;
use App\Auth\Authenticator;
use App\Auth\AvatarStorage;
use App\Auth\Csrf;
use App\Auth\LoginThrottle;
use App\Auth\MeController;
use App\Auth\Repositories\AuditLogRepository;
use App\Auth\Repositories\LoginAttemptRepository;
use App\Auth\Repositories\PasswordResetRepository;
use App\Auth\Repositories\TokenRepository;
use App\Auth\Repositories\UserRepository;
use App\Auth\SessionManager;
use App\Auth\TemporaryPasswordService;
use App\Auth\TokenAuthenticator;
use App\Auth\TokenController;
use App\Auth\TokenService;
use App\Auth\UserPresenter;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Policies\LastAdminGuard;

/**
 * Servicios de autenticación, sesión y permisos (fase 1).
 * `services.php` incluye todos los archivos de src/Core/providers/.
 */
return static function (Container $container, string $root): void {
    // --- Identidad de la petición -------------------------------------------
    $container->singleton(AuthContext::class, static fn (): AuthContext => new AuthContext());

    // --- Repositorios (única capa con SQL) ----------------------------------
    $container->singleton(
        UserRepository::class,
        static fn (Container $c): UserRepository => new UserRepository($c->get(Database::class))
    );
    $container->singleton(
        TokenRepository::class,
        static fn (Container $c): TokenRepository => new TokenRepository($c->get(Database::class))
    );
    $container->singleton(
        LoginAttemptRepository::class,
        static fn (Container $c): LoginAttemptRepository => new LoginAttemptRepository($c->get(Database::class))
    );
    $container->singleton(
        PasswordResetRepository::class,
        static fn (Container $c): PasswordResetRepository => new PasswordResetRepository($c->get(Database::class))
    );
    $container->singleton(
        AuditLogRepository::class,
        static fn (Container $c): AuditLogRepository => new AuditLogRepository($c->get(Database::class))
    );

    // --- Servicios de seguridad ---------------------------------------------
    $container->singleton(
        AuditLogger::class,
        static fn (Container $c): AuditLogger => new AuditLogger(
            $c->get(AuditLogRepository::class),
            $c->get(AuthContext::class),
        )
    );

    $container->singleton(
        SessionManager::class,
        static fn (Container $c): SessionManager => new SessionManager($c->get(Config::class))
    );

    $container->singleton(
        Csrf::class,
        static fn (Container $c): Csrf => new Csrf($c->get(SessionManager::class))
    );

    $container->singleton(
        LoginThrottle::class,
        static fn (Container $c): LoginThrottle => new LoginThrottle(
            $c->get(LoginAttemptRepository::class),
            $c->get(Config::class),
        )
    );

    $container->singleton(
        TokenAuthenticator::class,
        static fn (Container $c): TokenAuthenticator => new TokenAuthenticator(
            $c->get(TokenRepository::class),
            $c->get(UserRepository::class),
        )
    );

    $container->singleton(
        TokenService::class,
        static fn (Container $c): TokenService => new TokenService(
            $c->get(TokenRepository::class),
            $c->get(Config::class),
            $c->get(AuditLogger::class),
        )
    );

    $container->singleton(
        Authenticator::class,
        static fn (Container $c): Authenticator => new Authenticator(
            $c->get(UserRepository::class),
            $c->get(AuthContext::class),
            $c->get(SessionManager::class),
            $c->get(TokenAuthenticator::class),
            $c->get(AuditLogger::class),
        )
    );

    $container->singleton(
        UserPresenter::class,
        static fn (): UserPresenter => new UserPresenter()
    );

    $container->singleton(
        AvatarStorage::class,
        static fn (Container $c): AvatarStorage => new AvatarStorage($c->get(Config::class), $root)
    );

    $container->singleton(
        TemporaryPasswordService::class,
        static fn (Container $c): TemporaryPasswordService => new TemporaryPasswordService(
            $c->get(UserRepository::class),
            $c->get(PasswordResetRepository::class),
            $c->get(TokenService::class),
            $c->get(AuditLogger::class),
            $c->get(Database::class),
        )
    );

    // --- Autorización -------------------------------------------------------
    $container->singleton(
        Authorizer::class,
        static fn (Container $c): Authorizer => new Authorizer($c->get(AuthContext::class))
    );

    $container->singleton(
        LastAdminGuard::class,
        static fn (Container $c): LastAdminGuard => new LastAdminGuard($c->get(UserRepository::class))
    );

    // Alias cortos para que cualquier controlador de otro agente los resuelva igual.
    $container->set('auth', static fn (Container $c): object => $c->get(Authenticator::class));
    $container->set('authorizer', static fn (Container $c): object => $c->get(Authorizer::class));

    // --- Controladores ------------------------------------------------------
    $container->singleton(
        AuthController::class,
        static fn (Container $c): AuthController => new AuthController(
            $c->get(UserRepository::class),
            $c->get(SessionManager::class),
            $c->get(Csrf::class),
            $c->get(LoginThrottle::class),
            $c->get(AuditLogger::class),
            $c->get(AuthContext::class),
            $c->get(UserPresenter::class),
            $c->get(TokenService::class),
        )
    );

    $container->singleton(
        MeController::class,
        static fn (Container $c): MeController => new MeController(
            $c->get(Authorizer::class),
            $c->get(AuthContext::class),
            $c->get(UserRepository::class),
            $c->get(AuditLogger::class),
            $c->get(UserPresenter::class),
            $c->get(SessionManager::class),
            $c->get(TokenService::class),
            $c->get(PasswordResetRepository::class),
            $c->get(AvatarStorage::class),
            $c->get(Database::class),
            $c->get(Config::class),
        )
    );

    $container->singleton(
        TokenController::class,
        static fn (Container $c): TokenController => new TokenController(
            $c->get(Authorizer::class),
            $c->get(TokenService::class),
        )
    );
};
