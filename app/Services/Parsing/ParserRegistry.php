<?php

declare(strict_types=1);

namespace App\Services\Parsing;

use App\Services\Parsing\Contracts\ReviewSourceParser;
use App\Services\Parsing\Exceptions\InvalidSourceUrlException;
use InvalidArgumentException;

/**
 * Keeps the application from hard-coding "Yandex" everywhere.
 *
 * Adding 2GIS means registering one more parser here; controllers, jobs and
 * models already speak in terms of `source`.
 */
final class ParserRegistry
{
    /** @var array<string, ReviewSourceParser> */
    private array $parsers = [];

    /** @param iterable<ReviewSourceParser> $parsers */
    public function __construct(iterable $parsers = [])
    {
        foreach ($parsers as $parser) {
            $this->register($parser);
        }
    }

    public function register(ReviewSourceParser $parser): void
    {
        $this->parsers[$parser->source()] = $parser;
    }

    public function for(string $source): ReviewSourceParser
    {
        return $this->parsers[$source]
            ?? throw new InvalidArgumentException("Нет парсера для источника [{$source}].");
    }

    /**
     * Finds the platform a link belongs to.
     *
     * Matching is by host alone, which costs nothing: whether the link is a
     * usable card is the parser's job to answer afterwards.
     *
     * @throws InvalidSourceUrlException when no registered platform claims it
     */
    public function resolve(string $url): ReviewSourceParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->claims($url)) {
                return $parser;
            }
        }

        throw InvalidSourceUrlException::make(
            'url_unsupported_source',
            'Ссылка не относится к поддерживаемым площадкам. Сейчас поддерживаются Яндекс.Карты.',
            ['url' => $url, 'supported' => $this->sources()],
        );
    }

    /** @return list<string> */
    public function sources(): array
    {
        return array_keys($this->parsers);
    }
}
