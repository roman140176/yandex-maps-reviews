<?php

declare(strict_types=1);

namespace App\Services\Parsing\Contracts;

use App\Services\Parsing\Data\ParseResult;
use App\Services\Parsing\Data\SourceReference;
use Closure;

/**
 * A review platform the application can pull from.
 *
 * Yandex.Maps is the only implementation today; 2GIS would be the next one and
 * the rest of the application does not need to change to accept it.
 */
interface ReviewSourceParser
{
    /** Machine key of the platform, e.g. `yandex`. */
    public function source(): string;

    /**
     * Does this link belong to this platform?
     *
     * Cheap and network-free by contract: it only looks at the host, so the
     * registry can pick a parser without paying for a redirect lookup.
     */
    public function claims(string $url): bool;

    /**
     * Validate a user-supplied link and turn it into a canonical reference.
     *
     * @throws \App\Services\Parsing\Exceptions\InvalidSourceUrlException
     */
    public function parseUrl(string $url): SourceReference;

    /**
     * Fetch profile and reviews.
     *
     * @param null|Closure(int $pagesDone, int $pagesExpected, int $reviewsSoFar): void $onProgress
     *
     * @throws \App\Services\Parsing\Exceptions\ParsingException
     */
    public function fetch(SourceReference $reference, ?Closure $onProgress = null): ParseResult;
}
