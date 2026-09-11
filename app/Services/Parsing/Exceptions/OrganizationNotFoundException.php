<?php

declare(strict_types=1);

namespace App\Services\Parsing\Exceptions;

/** The card does not exist any more (404 or an empty result set for the id). */
final class OrganizationNotFoundException extends ParsingException
{
    public const NOT_FOUND = 'organization_not_found';

    /** @param array<string, mixed> $context */
    public static function make(string $message, array $context = []): self
    {
        return new self(self::NOT_FOUND, $message, $context);
    }

    public function isRetryable(): bool
    {
        return false;
    }
}
