<?php

declare(strict_types=1);

namespace PitchRooms\Core;

use PitchRooms\Middleware\AuthMiddleware;
use PitchRooms\Middleware\CorsMiddleware;
use PitchRooms\Middleware\RoleMiddleware;
use PitchRooms\Services\SchedulerService;
use PitchRooms\Support\Logger;
use Throwable;

/**
 * Boots the app, resolves the route, runs middleware, dispatches the
 * controller, and — because cron lives in code here, not in the hosting
 * panel — kicks the scheduler after the response has been flushed.
 */
final class Kernel
{
    public function __construct(private Router $router)
    {
    }

    public static function boot(string $basePath): self
    {
        Env::load($basePath . '/.env');
        date_default_timezone_set(Env::string('APP_TIMEZONE', 'UTC'));

        if (Env::bool('APP_DEBUG')) {
            ini_set('display_errors', '0'); // still JSON — see handle()
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', '0');
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
        }

        Paths::setBase($basePath);

        $router = new Router();
        $register = require $basePath . '/routes/api.php';
        $register($router);

        return new self($router);
    }

    public function handle(Request $request): Response
    {
        $cors = new CorsMiddleware();

        try {
            if ($request->method === 'OPTIONS') {
                return $cors->preflight();
            }

            $matched = $this->router->match($request->method, $request->path);
            if ($matched === null) {
                throw HttpException::notFound(sprintf('No route for %s %s.', $request->method, $request->path));
            }

            $route = $matched['route'];
            $request->setAttribute('routeParams', $matched['params']);
            $request->setAttribute('routePattern', $route['pattern']);

            foreach ($route['middleware'] as $middleware) {
                $this->runMiddleware($middleware, $request);
            }

            $response = $this->dispatch($route['handler'], $request, $matched['params']);
        } catch (HttpException $e) {
            $response = $e->toResponse();
        } catch (Throwable $e) {
            Logger::error('Unhandled exception', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
                'path'    => $request->path,
            ]);

            $response = Env::bool('APP_DEBUG')
                ? Response::error('SERVER_ERROR', $e->getMessage(), 500, [
                    'file'  => $e->getFile() . ':' . $e->getLine(),
                    'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
                ])
                : Response::error('SERVER_ERROR', 'Something went wrong. Please try again.', 500);
        }

        return $cors->apply($request, $response);
    }

    private function runMiddleware(string $name, Request $request): void
    {
        // "role:admin,brand" style arguments
        $argument = null;
        if (str_contains($name, ':')) {
            [$name, $argument] = explode(':', $name, 2);
        }

        match ($name) {
            'auth'     => (new AuthMiddleware())->handle($request),
            'optional' => (new AuthMiddleware())->handle($request, optional: true),
            'role'     => (new RoleMiddleware())->handle($request, explode(',', (string) $argument)),
            default    => throw HttpException::server('Unknown middleware: ' . $name),
        };
    }

    private function dispatch(mixed $handler, Request $request, array $params): Response
    {
        if (is_callable($handler)) {
            $result = $handler($request, $params);
        } elseif (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $controller = new $class();
            $result = $controller->{$method}($request, $params);
        } else {
            throw HttpException::server('Invalid route handler.');
        }

        if ($result instanceof Response) {
            return $result;
        }

        return Response::json($result);
    }

    /**
     * Flush the response to the client, then run any due scheduled jobs in the
     * same process. This is what replaces hosting-panel cron.
     */
    public function terminate(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }

        if (!Env::bool('SCHEDULER_ENABLED', true) || !Env::bool('SCHEDULER_INLINE', true)) {
            return;
        }

        try {
            (new SchedulerService())->tick('inline');
        } catch (Throwable $e) {
            Logger::error('Inline scheduler tick failed', ['message' => $e->getMessage()]);
        }
    }

    public function router(): Router
    {
        return $this->router;
    }
}
