<?php

declare(strict_types=1);

namespace App\Services\Parsing\Data;

/** Everything a single parse run produced. */
final readonly class ParseResult
{
    /**
     * @param list<ReviewData> $reviews
     * @param list<string>     $warnings non-fatal deviations worth recording
     */
    public function __construct(
        public BusinessProfileData $profile,
        public array $reviews,
        public int $pagesFetched,
        public int $pagesExpected,
        public array $warnings = [],
    ) {}
}
