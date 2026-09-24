<?php

declare(strict_types=1);

namespace PitchRooms\Middleware;

use PitchRooms\Core\Env;
use PitchRooms\Core\Request;
use PitchRooms\Core\Response;

/**
 * The frontend and the API are deployed to different hosts, so every browser
 * call is cross-origin. Allowed origins come from FRONTEND_DNS (+ extras),
 * which keeps environment moves to a single env change.
 */
final class CorsMiddleware
{
    public function apply(Request $request, Response $response): Response
    {
        $origin = $this->resolveOrigin($request->header('origin'));
        if ($origin === null) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Vary', 'Origin');
    }

    public function preflight(): Response
    {
        $origin = $this->resolveOrigin($_SERVER['HTTP_ORIGIN'] ?? null) ?? Env::frontendDns();

        return (new Response(204, null))
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Requested-With, X-Scheduler-Key, X-Client-Version')
            ->withHeader('Access-Control-Max-Age', '86400')
            ->withHeader('Vary', 'Origin');
    }

    private function resolveOrigin(?string $origin): ?string
    {
        if ($origin === null || $origin === '') {
            return null;
        }

        $origin = rtrim($origin, '/');
        $allowed = array_map(
            static fn (string $value): string => rtrim(trim($value), '/'),
            array_merge([Env::frontendDns()], Env::list('CORS_EXTRA_ORIGINS'))
        );

        if (in_array('*', $allowed, true)) {
            return $origin;
        }

        foreach ($allowed as $candidate) {
            if ($candidate !== '' && strcasecmp($candidate, $origin) === 0) {
                return $candidate;
            }
        }

        // Any localhost port is fine while developing; never in production.
        if (Env::string('APP_ENV', 'local') === 'local' && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $origin)) {
            return $origin;
        }

        return null;
    }
}
