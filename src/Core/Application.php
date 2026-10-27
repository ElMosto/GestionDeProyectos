<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Arranque HTTP: construye el contenedor, resuelve la petición y normaliza la salida.
 * La construcción va dentro del try para que un fallo de arranque también devuelva
 * JSON 500 en lugar de una traza HTML con código 200.
 */
final class Application
{
    private ?Container $container = null;
    private ?Router $router = null;

    public function __construct(
        private readonly string $root,
        private readonly array $configValues,
        private readonly string $requestId,
    ) {
    }

    public function run(): void
    {
        try {
            $this->container = $this->buildContainer();
            $this->router = $this->buildRouter();

            $request = Request::fromGlobals($this->requestId);
            $this->router->handle($request)->send();
        } catch (ApiException $e) {
            $this->respondWithError($e->status, $e->errorCode, $e->getMessage(), $e->fields, $e->headers);
        } catch (Throwable $e) {
            $this->log($e);
            $this->respondWithError(500, 'INTERNAL_ERROR', 'Ocurrió un error inesperado.');
        }
    }

    public function container(): Container
    {
        return $this->container ??= $this->buildContainer();
    }

    private function buildContainer(): Container
    {
        $container = new Container();
        $config = new Config($this->configValues);

        $container->singleton(Config::class, static fn (): Config => $config);
        $container->singleton(Database::class, static fn (Container $c): Database => new Database($c->get(Config::class)));

        if (is_file($this->root . '/src/Core/services.php')) {
            /** @var callable(Container, string):void $register */
            $register = require $this->root . '/src/Core/services.php';
            $register($container, $this->root);
        }

        return $container;
    }

    private function buildRouter(): Router
    {
        $container = $this->container();
        $router = new Router($container);

        if (is_file($this->root . '/src/Core/middleware.php')) {
            /** @var callable(Router, Container):void $register */
            $register = require $this->root . '/src/Core/middleware.php';
            $register($router, $container);
        }

        /** @var callable(Router, Container):void $register */
        $register = require $this->root . '/src/Core/routes.php';
        $register($router, $container);

        return $router;
    }

    /**
     * @param array<string, mixed>  $fields
     * @param array<string, string> $headers
     */
    private function respondWithError(
        int $status,
        string $code,
        string $message,
        array $fields = [],
        array $headers = [],
    ): void {
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        Response::error($status, $code, $message, $fields, $this->requestId)->send();
    }

    private function log(Throwable $e): void
    {
        error_log(sprintf(
            '[%s] %s: %s en %s:%d',
            $this->requestId,
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
}
