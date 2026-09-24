<?php

declare(strict_types=1);

namespace PitchRooms\Core;

/**
 * Every response uses the envelope the frontend already tolerates:
 *   { ok: true,  data: ..., meta: ... }
 *   { ok: false, error: { code, message, fields } }
 */
final class Response
{
    private array $headers = [];

    public function __construct(
        public readonly int $status = 200,
        public readonly mixed $payload = null
    ) {
    }

    public static function json(mixed $data = null, int $status = 200, array $meta = []): self
    {
        $body = ['ok' => true, 'data' => $data];
        if ($meta !== []) {
            $body['meta'] = $meta;
        }
        return new self($status, $body);
    }

    public static function created(mixed $data = null): self
    {
        return self::json($data, 201);
    }

    public static function noContent(): self
    {
        return new self(204, null);
    }

    public static function paginated(array $items, int $total, int $page, int $perPage, array $extra = []): self
    {
        return self::json($items, 200, array_merge([
            'page'       => $page,
            'perPage'    => $perPage,
            'total'      => $total,
            'totalPages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ], $extra));
    }

    public static function error(string $code, string $message, int $status = 400, array $fields = []): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }
        return new self($status, ['ok' => false, 'error' => $error]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        if ($this->status !== 204 && $this->payload !== null) {
            echo json_encode(
                $this->payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
        }
    }
}
