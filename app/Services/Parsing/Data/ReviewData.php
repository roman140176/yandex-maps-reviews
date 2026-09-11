<?php

declare(strict_types=1);

namespace App\Services\Parsing\Data;

use DateTimeImmutable;

/** A single review as it came from the source, already normalised. */
final readonly class ReviewData
{
    public function __construct(
        public string $externalId,
        public string $authorName,
        public ?string $authorAvatarUrl,
        public ?string $authorLevel,
        /** Null when the author wrote text but left no stars — Yandex allows that. */
        public ?int $rating,
        public string $text,
        public DateTimeImmutable $publishedAt,
        public ?string $businessCommentText = null,
        public ?DateTimeImmutable $businessCommentAt = null,
        public int $photosCount = 0,
        public int $likesCount = 0,
    ) {}

    /**
     * Fingerprint of everything that can legitimately change on an existing
     * review. Comparing hashes is how a re-parse tells "unchanged" from
     * "edited" without diffing every column.
     */
    public function contentHash(): string
    {
        return hash('sha256', implode("\x1f", [
            $this->authorName,
            (string) ($this->rating ?? ''),
            $this->text,
            $this->publishedAt->format(DATE_ATOM),
            $this->businessCommentText ?? '',
            $this->businessCommentAt?->format(DATE_ATOM) ?? '',
        ]));
    }
}
