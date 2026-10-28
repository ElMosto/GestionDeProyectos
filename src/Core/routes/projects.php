<?php

declare(strict_types=1);

use App\Controllers\ProjectChargesController;
use App\Controllers\ProjectsController;
use App\Controllers\StagesController;
use App\Controllers\TasksController;
use App\Core\Container;
use App\Core\Router;

return static function (Router $router, Container $container): void {
    $router->get('/api/v1/projects', [ProjectsController::class, 'index']);
    $router->post('/api/v1/projects', [ProjectsController::class, 'store']);
    $router->get('/api/v1/projects/{id}', [ProjectsController::class, 'show']);
    $router->patch('/api/v1/projects/{id}', [ProjectsController::class, 'update']);
    $router->post('/api/v1/projects/{id}/transitions', [ProjectsController::class, 'transition']);
    $router->get('/api/v1/projects/{id}/members', [ProjectsController::class, 'members']);
    $router->put('/api/v1/projects/{id}/members', [ProjectsController::class, 'replaceMembers']);
    $router->get('/api/v1/projects/{id}/payments', [ProjectsController::class, 'payments']);
    $router->get('/api/v1/projects/{id}/stages', [StagesController::class, 'index']);
    $router->post('/api/v1/projects/{id}/stages', [StagesController::class, 'store']);
    $router->patch('/api/v1/stages/{id}', [StagesController::class, 'update']);
    $router->post('/api/v1/projects/{id}/stages/reorder', [StagesController::class, 'reorder']);
    $router->get('/api/v1/stages/{id}/tasks', [TasksController::class, 'stageIndex']);
    $router->post('/api/v1/stages/{id}/tasks', [TasksController::class, 'store']);
    $router->get('/api/v1/tasks', [TasksController::class, 'index']);
    $router->get('/api/v1/tasks/{id}', [TasksController::class, 'show']);
    $router->patch('/api/v1/tasks/{id}', [TasksController::class, 'update']);
    $router->post('/api/v1/tasks/{id}/transitions', [TasksController::class, 'transition']);
    $router->post('/api/v1/standalone-tasks', [TasksController::class, 'standalone']);
    $router->get('/api/v1/tasks/{id}/comments', [TasksController::class, 'comments']);
    $router->post('/api/v1/tasks/{id}/comments', [TasksController::class, 'comment']);
    $router->get('/api/v1/agenda', [TasksController::class, 'agenda']);
    $router->get('/api/v1/projects/{id}/charges', [ProjectChargesController::class, 'index']);
    $router->post('/api/v1/projects/{id}/charges', [ProjectChargesController::class, 'store']);
    $router->patch('/api/v1/project-charges/{id}', [ProjectChargesController::class, 'update']);
};
