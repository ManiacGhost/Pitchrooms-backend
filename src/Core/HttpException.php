<?php

declare(strict_types=1);

namespace PitchRooms\Core;

use RuntimeException;

/**
 * Thrown anywhere in the app; turned into the JSON error envelope by the Kernel.
 */
class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = []
    ) {
        parent::__construct($message, $status);
    }

    public static function badRequest(string $message, string $code = 'BAD_REQUEST', array $fields = []): self
    {
        return new self(400, $code, $message, $fields);
    }

    public static function validation(array $fields, string $message = 'The submitted data is invalid.'): self
    {
        return new self(400, 'VALIDATION_FAILED', $message, $fields);
    }

    public static function unauthenticated(string $message = 'Authentication required.'): self
    {
        return new self(401, 'UNAUTHENTICATED', $message);
    }

    public static function forbidden(string $message = 'You are not allowed to do that.', string $code = 'FORBIDDEN'): self
    {
        return new self(403, $code, $message);
    }

    public static function notFound(string $message = 'Not found.', string $code = 'NOT_FOUND'): self
    {
        return new self(404, $code, $message);
    }

    public static function conflict(string $message, string $code = 'CONFLICT'): self
    {
        return new self(409, $code, $message);
    }

    /** Business-rule failure: syntactically fine, not allowed right now. */
    public static function unprocessable(string $message, string $code = 'RULE_VIOLATION'): self
    {
        return new self(422, $code, $message);
    }

    public static function tooManyRequests(string $message = 'Too many requests. Slow down.'): self
    {
        return new self(429, 'RATE_LIMITED', $message);
    }

    public static function server(string $message = 'Something went wrong.', string $code = 'SERVER_ERROR'): self
    {
        return new self(500, $code, $message);
    }

    public function toResponse(): Response
    {
        return Response::error($this->errorCode, $this->getMessage(), $this->status, $this->fields);
    }
}
