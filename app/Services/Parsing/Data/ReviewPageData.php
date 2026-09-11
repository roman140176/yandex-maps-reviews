<?php

declare(strict_types=1);

namespace App\Services\Parsing\Data;

/** One page of reviews plus the source's own pagination metadata. */
final readonly class ReviewPageData
{
    /** @param list<ReviewData> $reviews */
    public function __construct(
        public array $reviews,
        public int $page,
        public int $offset,
        public int $limit,
        public int $totalCount,
        public int $totalPages,
    ) {}

    public function isEmpty(): bool
    {
        return $this->reviews === [];
    }
}
