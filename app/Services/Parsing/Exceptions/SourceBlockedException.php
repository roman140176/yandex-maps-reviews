<?php

declare(strict_types=1);

namespace App\Services\Parsing\Exceptions;

/** Anti-bot protection kicked in: captcha page, 403, or a rate-limit response. */
final class SourceBlockedException extends ParsingException
{
    public const CAPTCHA = 'source_captcha';
    public const FORBIDDEN = 'source_forbidden';
    public const RATE_LIMITED = 'source_rate_limited';

    /** @param array<string, mixed> $context */
    public static function make(string $code, string $message, array $context = []): self
    {
        return new self($code, $message, $context);
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
