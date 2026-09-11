<?php

declare(strict_types=1);

namespace App\Services\Parsing\Data;

/**
 * Aggregates of an organisation card.
 *
 * `ratingsCount` and `reviewsCount` are deliberately separate: on Yandex most
 * people leave a star rating without any text, so the two numbers differ by an
 * order of magnitude and conflating them would be wrong.
 */
final readonly class BusinessProfileData
{
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $address,
        public ?string $category,
        public float $ratingValue,
        public int $ratingsCount,
        public int $reviewsCount,
    ) {}
}
