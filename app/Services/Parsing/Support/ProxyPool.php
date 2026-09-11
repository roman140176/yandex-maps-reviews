<?php

declare(strict_types=1);

namespace App\Services\Parsing\Support;

/**
 * Round-robin over a configured proxy list, with failing entries parked.
 *
 * Empty by default: the application works fine from a single IP at this volume.
 * The pool exists so that scaling to many cards a day is a config change
 * (`YANDEX_PROXIES=...`) rather than a rewrite.
 */
final class ProxyPool
{
    /** @var list<string> */
    private array $proxies;

    /** @var array<string, int> proxy => unix timestamp until which it is parked */
    private array $parked = [];

    private int $cursor = 0;

    /** @param list<string> $proxies */
    public function __construct(array $proxies = [], private readonly int $parkSeconds = 600)
    {
        $this->proxies = array_values(array_filter($proxies));
    }

    public function isEmpty(): bool
    {
        return $this->available() === [];
    }

    /** Null means "go direct" — either no pool configured or all entries parked. */
    public function next(): ?string
    {
        $available = $this->available();

        if ($available === []) {
            return null;
        }

        $proxy = $available[$this->cursor % count($available)];
        $this->cursor++;

        return $proxy;
    }

    /** Takes a proxy out of rotation after it failed or got blocked. */
    public function park(string $proxy): void
    {
        $this->parked[$proxy] = time() + $this->parkSeconds;
    }

    /** @return list<string> */
    private function available(): array
    {
        $now = time();

        return array_values(array_filter(
            $this->proxies,
            fn (string $proxy): bool => ($this->parked[$proxy] ?? 0) <= $now,
        ));
    }
}
