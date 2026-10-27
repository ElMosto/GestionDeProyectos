<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<array{method:string, pattern:string, regex:string, params:list<string>, handler:callable|array}> */
    private array $routes = [];
    /** @var list<callable> */
    private array $middleware = [];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param callable|array{class-string, string} $handler
     */
    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /**
     * @param callable|array{class-string, string} $handler
     */
    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /**
     * @param callable|array{class-string, string} $handler
     */
    public function patch(string $pattern, callable|array $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    /**
     * @param callable|array{class-string, string} $handler
     */
    public function put(string $pattern, callable|array $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    /**
     * @param callable|array{class-string, string} $handler
     */
    public function delete(string $pattern, callable|array $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    /**
     * @param callable|array{class-string, string} $handler
     */
    public function add(string $method, string $pattern, callable|array $handler): void
    {
        $params = [];

        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '([0-9]+)';
            },
            $pattern
        );

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
        ];
    }

    /**
     * @param callable $middleware fn(Request, callable): Response
     */
    public function middleware(callable $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function handle(Request $request): Response
    {
        $core = function (Request $request): Response {
            return $this->dispatch($request);
        };

        $pipeline = array_reduce(
            array_reverse($this->middleware),
            static fn (callable $next, callable $mw): callable => static fn (Request $r): Response => $mw($r, $next),
            $core
        );

        return $pipeline($request);
    }

    private function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }

            $pathMatched = true;

            if ($route['method'] !== $request->method) {
                continue;
            }

            $params = [];
            foreach ($route['params'] as $index => $name) {
                $params[$name] = $matches[$index + 1] ?? null;
            }

            $handler = $route['handler'];

            if (is_array($handler) && is_string($handler[0] ?? null)) {
                $controller = $this->container->get($handler[0]);
                $handler = [$controller, $handler[1]];
            }

            return $handler($request->withRouteParams($params));
        }

        if ($pathMatched) {
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Método no permitido para esta ruta.');
        }

        throw ApiException::notFound('Ruta no encontrada.');
    }
}
