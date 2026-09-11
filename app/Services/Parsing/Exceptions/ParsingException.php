<?php

declare(strict_types=1);

namespace App\Services\Parsing\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for every failure that happens while talking to an external
 * review source. Each subclass carries a machine-readable code so that the
 * failure can be stored in `parse_runs.error_code`, matched in tests and
 * rendered in the UI without parsing English prose.
 */
abstract class ParsingException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * Whether retrying the same request later has a realistic chance to work.
     * Drives queue retries: a blocked source is worth retrying, a changed
     * layout is not — it needs a developer.
     */
    abstract public function isRetryable(): bool;
}
