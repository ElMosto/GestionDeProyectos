<?php

declare(strict_types=1);

use App\Auth\AuditLogger;
use App\Auth\Repositories\AuditLogRepository;
use App\Controllers\ProjectChargesController;
use App\Controllers\ProjectsController;
use App\Controllers\StagesController;
use App\Controllers\TasksController;
use App\Core\Container;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Repositories\ProjectChargeRepository;
use App\Repositories\ProjectIdempotencyRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\SequenceRepository;
use App\Repositories\StageRepository;
use App\Repositories\TaskRepository;
use App\Services\ProjectAudit;
use App\Services\NotificationService;
use App\Services\ProjectChargeService;
use App\Services\ProjectIdempotency;
use App\Services\ProjectService;
use App\Services\StageService;
use App\Services\TaskService;

return static function (Container $container, string $root): void {
    foreach ([ProjectRepository::class, SequenceRepository::class, StageRepository::class, TaskRepository::class,
        ProjectChargeRepository::class, ProjectIdempotencyRepository::class] as $class) {
        $container->singleton($class, static fn (Container $c): object => new $class($c->get(Database::class)));
    }
    $container->singleton(ProjectAudit::class, static fn (Container $c): ProjectAudit => new ProjectAudit(
        $c->get(AuditLogRepository::class), $c->get(AuditLogger::class), $c->get(Authorizer::class),
    ));
    $container->singleton(ProjectIdempotency::class, static fn (Container $c): ProjectIdempotency => new ProjectIdempotency(
        $c->get(Database::class), $c->get(Authorizer::class), $c->get(ProjectIdempotencyRepository::class),
    ));
    $container->singleton(ProjectService::class, static fn (Container $c): ProjectService => new ProjectService(
        $c->get(ProjectRepository::class), $c->get(SequenceRepository::class), $c->get(Database::class),
        $c->get(Authorizer::class), $c->get(ProjectAudit::class), $c->get(ProjectIdempotency::class),
    ));
    $container->singleton(StageService::class, static fn (Container $c): StageService => new StageService(
        $c->get(StageRepository::class), $c->get(ProjectService::class), $c->get(Database::class),
        $c->get(Authorizer::class), $c->get(ProjectAudit::class),
    ));
    $container->singleton(TaskService::class, static fn (Container $c): TaskService => new TaskService(
        $c->get(TaskRepository::class), $c->get(ProjectRepository::class), $c->get(StageRepository::class),
        $c->get(ProjectService::class), $c->get(Database::class), $c->get(Authorizer::class), $c->get(ProjectAudit::class), $c->get(NotificationService::class),
    ));
    $container->singleton(ProjectChargeService::class, static fn (Container $c): ProjectChargeService => new ProjectChargeService(
        $c->get(ProjectChargeRepository::class), $c->get(ProjectRepository::class), $c->get(ProjectService::class),
        $c->get(Authorizer::class), $c->get(ProjectAudit::class), $c->get(ProjectIdempotency::class),
    ));
    foreach ([ProjectsController::class => ProjectService::class, StagesController::class => StageService::class,
        TasksController::class => TaskService::class, ProjectChargesController::class => ProjectChargeService::class] as $controller => $service) {
        $container->singleton($controller, static fn (Container $c): object => new $controller($c->get($service), $c->get(ProjectAudit::class)));
    }
};
