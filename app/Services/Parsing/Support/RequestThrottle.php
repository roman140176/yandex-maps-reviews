<?php

declare(strict_types=1);

namespace App\Services\Parsing\Support;

/**
 * Keeps a polite, slightly irregular distance between consecutive requests.
 *
 * Fixed intervals are themselves a signature, hence the jitter. The sleeper is
 * injectable so tests do not actually wait.
 */
final class RequestThrottle
{
    /** @var null|callable(int): void */
    private $sleeper;

    private ?float $lastRequestAt = null;

    /** @param null|callable(int): void $sleeper microseconds */
    public function __construct(
        private readonly int $minDelayMs = 400,
        private readonly int $maxDelayMs = 900,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper;
    }

    /** Blocks until enough time has passed since the previous request. */
    public function wait(): void
    {
        $delayMs = $this->minDelayMs >= $this->maxDelayMs
            ? $this->minDelayMs
            : random_int($this->minDelayMs, $this->maxDelayMs);

        if ($this->lastRequestAt !== null) {
            $elapsedMs = (microtime(true) - $this->lastRequestAt) * 1000;
            $delayMs = (int) max(0, $delayMs - $elapsedMs);
        } else {
            $delayMs = 0; // nothing to space out from yet
        }

        if ($delayMs > 0) {
            $this->sleep($delayMs * 1000);
        }

        $this->lastRequestAt = microtime(true);
    }

    /** Exponential backoff with jitter, used after a failed attempt. */
    public function backoff(int $attempt): void
    {
        $baseMs = min(30_000, (int) (1000 * 2 ** max(0, $attempt - 1)));
        $this->sleep(random_int((int) ($baseMs * 0.5), $baseMs) * 1000);
    }

    private function sleep(int $microseconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($microseconds);

            return;
        }

        usleep($microseconds);
    }
}
