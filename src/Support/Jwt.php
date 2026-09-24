<?php

declare(strict_types=1);

namespace PitchRooms\Support;

use PitchRooms\Core\Env;

/**
 * HS256 / RS256 JWT. HS256 signs our own access tokens; RS256 is used for
 * Jitsi JaaS tokens, which must be signed with the tenant's private key.
 */
final class Jwt
{
    public static function encode(array $claims, ?string $secret = null, string $algorithm = 'HS256', array $extraHeaders = []): string
    {
        $header = array_merge(['typ' => 'JWT', 'alg' => $algorithm], $extraHeaders);
        $segments = [
            self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES)),
        ];

        $signingInput = implode('.', $segments);
        $signature = self::sign($signingInput, $secret ?? self::secret(), $algorithm);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /** @return array|null decoded claims, or null when invalid/expired */
    public static function decode(string $token, ?string $secret = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header64, $payload64, $signature64] = $parts;
        $header = json_decode(self::base64UrlDecode($header64) ?: '', true);
        $claims = json_decode(self::base64UrlDecode($payload64) ?: '', true);

        if (!is_array($header) || !is_array($claims)) {
            return null;
        }
        if (($header['alg'] ?? '') !== 'HS256') {
            return null; // we only verify our own HS256 tokens here
        }

        $expected = self::sign($header64 . '.' . $payload64, $secret ?? self::secret(), 'HS256');
        if (!hash_equals($expected, self::base64UrlDecode($signature64) ?: '')) {
            return null;
        }

        $now = time();
        if (isset($claims['exp']) && $now >= (int) $claims['exp']) {
            return null;
        }
        if (isset($claims['nbf']) && $now < (int) $claims['nbf']) {
            return null;
        }

        return $claims;
    }

    private static function sign(string $input, string $key, string $algorithm): string
    {
        if ($algorithm === 'HS256') {
            return hash_hmac('sha256', $input, $key, true);
        }

        if ($algorithm === 'RS256') {
            $privateKey = openssl_pkey_get_private($key);
            if ($privateKey === false) {
                throw new \RuntimeException('Invalid RS256 private key.');
            }
            $signature = '';
            openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256);
            return $signature;
        }

        throw new \RuntimeException('Unsupported JWT algorithm: ' . $algorithm);
    }

    private static function secret(): string
    {
        $secret = Env::string('JWT_SECRET', '');
        if (strlen($secret) < 16) {
            throw new \RuntimeException('JWT_SECRET is missing or too short. Run: php bin/keygen.php');
        }
        return $secret;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4));
    }
}
