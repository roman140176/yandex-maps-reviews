<?php

declare(strict_types=1);

namespace App\Services\Parsing\Data;

/**
 * What one fetched HTML page yielded: the card's aggregates (present on every
 * page) and its slice of reviews (absent once the source runs out of pages).
 */
final readonly class ExtractedPage
{
    /** @param list<string> $warnings */
    public function __construct(
        public BusinessProfileData $profile,
        public ?ReviewPageData $reviews,
        public array $warnings = [],
    ) {}
}
