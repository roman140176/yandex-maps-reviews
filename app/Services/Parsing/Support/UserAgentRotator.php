<?php

declare(strict_types=1);

namespace App\Services\Parsing\Support;

/**
 * Hands out a different browser signature per request.
 *
 * A fleet of organisations parsed from one IP with one identical User-Agent is
 * the easiest possible bot signature. Rotation alone does not defeat anything
 * serious, but it removes the cheapest tell.
 */
final class UserAgentRotator
{
    /** @var list<string> */
    private array $agents;

    private int $cursor = 0;

    /** @param list<string> $agents */
    public function __construct(array $agents = [])
    {
        $this->agents = $agents !== [] ? array_values($agents) : self::defaults();
        $this->cursor = random_int(0, count($this->agents) - 1);
    }

    public function next(): string
    {
        $agent = $this->agents[$this->cursor % count($this->agents)];
        $this->cursor++;

        return $agent;
    }

    /** @return list<string> */
    public static function defaults(): array
    {
        return [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
        ];
    }
}
