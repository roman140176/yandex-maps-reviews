<?php

declare(strict_types=1);

namespace App\Services\Parsing\Exceptions;

use Throwable;

/** Network failure, timeout or 5xx from the source. */
final class SourceUnavailableException extends ParsingException
{
    public const CONNECTION_FAILED = 'source_connection_failed';
    public const SERVER_ERROR = 'source_server_error';
    public const EMPTY_RESPONSE = 'source_empty_response';

    /** @param array<string, mixed> $context */
    public static function make(string $code, string $message, array $context = [], ?Throwable $previous = null): self
    {
        return new self($code, $message, $context, $previous);
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
