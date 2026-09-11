<?php

declare(strict_types=1);

namespace App\Services\Parsing\Exceptions;

/** The submitted link is not a recognisable organisation card. */
final class InvalidSourceUrlException extends ParsingException
{
    /** @param array<string, mixed> $context */
    public static function make(string $code, string $message, array $context = []): self
    {
        return new self($code, $message, $context);
    }

    public function isRetryable(): bool
    {
        return false;
    }
}
