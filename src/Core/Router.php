<?php

declare(strict_types=1);

namespace PitchRooms\Core;

/**
 * Pattern router. Routes are declared as:
 *   $router->get('/events/{id}', [EventController::class, 'show'], ['auth']);
 *
 * Middleware names are resolved by the Kernel.
 */
final class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, params:array, handler:mixed, middleware:array}> */
    private array $routes = [];

    private array $groupMiddleware = [];
    private string $groupPrefix = '';

    public function get(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /** PUT and PATCH behave identically across this API. */
    public function write(string $path, mixed $handler, array $middleware = []): void
    {
        $this->put($path, $handler, $middleware);
        $this->patch($path, $handler, $middleware);
    }

    public function group(array $middleware, callable $callback, string $prefix = ''): void
    {
        $previousMiddleware = $this->groupMiddleware;
        $previousPrefix = $this->groupPrefix;

        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);
        $this->groupPrefix .= $prefix;

        $callback($this);

        $this->groupMiddleware = $previousMiddleware;
        $this->groupPrefix = $previousPrefix;
    }

    private function add(string $method, string $path, mixed $handler, array $middleware): void
    {
        $pattern = $this->groupPrefix . $path;
        $pattern = '/' . trim($pattern, '/');

        $params = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $matches) use (&$params): string {
                $params[] = $matches[1];
                return '([^/]+)';
            },
            $pattern
        );

        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $pattern,
            'regex'      => '#^' . $regex . '$#',
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
        ];
    }

    /**
     * @return array{route:array, params:array}|null  null = no match
     */
    public function match(string $method, string $path): ?array
    {
        $path = '/' . trim($path, '/');
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }

            array_shift($matches);
            $params = [];
            foreach ($route['params'] as $index => $name) {
                $params[$name] = urldecode($matches[$index] ?? '');
            }

            return ['route' => $route, 'params' => $params];
        }

        if ($pathMatched) {
            throw new HttpException(405, 'METHOD_NOT_ALLOWED', sprintf('%s is not allowed on %s.', $method, $path));
        }

        return null;
    }

    public function routes(): array
    {
        return $this->routes;
    }
}
