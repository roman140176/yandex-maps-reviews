<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Models\Organization;
use App\Models\ParseRun;
use App\Models\Review;
use App\Models\ReviewRevision;
use App\Services\Parsing\Data\ReviewData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes a parse result into the database without ever creating a duplicate.
 *
 * A review is identified by `(organization_id, external_id)` — the platform's
 * own id — so re-parsing the same card converges instead of piling up copies.
 * What changed since last time is decided by comparing a content hash, and when
 * something did change the old values are kept in `review_revisions`, which is
 * what makes "was → became" answerable later.
 *
 * Volume matters here: a card brings up to 600 reviews per run, so the work is
 * done in bulk — one SELECT, one chunked INSERT, and individual UPDATEs only
 * for the handful of reviews that actually moved.
 */
final class ReviewSyncService
{
    /** Fields whose change is worth recording in history. */
    private const TRACKED_FIELDS = [
        'author_name', 'rating', 'text', 'business_comment_text', 'business_comment_at', 'published_at',
    ];

    /** Fields refreshed silently: they drift on their own and mean nothing. */
    private const VOLATILE_FIELDS = ['author_avatar_url', 'author_level', 'photos_count', 'likes_count'];

    private const INSERT_CHUNK = 200;

    /** @param list<ReviewData> $reviews */
    public function sync(Organization $organization, array $reviews, ?ParseRun $run = null): SyncStats
    {
        if ($reviews === []) {
            return new SyncStats();
        }

        $now = Carbon::now();

        /** @var array<string, Review> $existing */
        $existing = Review::query()
            ->where('organization_id', $organization->id)
            ->whereIn('external_id', array_map(fn (ReviewData $r): string => $r->externalId, $reviews))
            ->get()
            ->keyBy('external_id')
            ->all();

        $toInsert = [];
        $unchangedIds = [];
        $updated = 0;

        foreach ($reviews as $review) {
            $current = $existing[$review->externalId] ?? null;

            if ($current === null) {
                $toInsert[] = $this->newRow($organization, $review, $now);

                continue;
            }

            if ($current->content_hash === $review->contentHash()) {
                $unchangedIds[] = $current->id;
                $this->refreshVolatile($current, $review);

                continue;
            }

            $this->applyChange($current, $review, $now, $run);
            $updated++;
        }

        $created = 0;

        DB::transaction(function () use ($toInsert, $unchangedIds, $now, &$created): void {
            foreach (array_chunk($toInsert, self::INSERT_CHUNK) as $chunk) {
                Review::query()->insert($chunk);
                $created += count($chunk);
            }

            // One statement for every review that did not change: the only
            // thing we owe them is proof they are still published.
            foreach (array_chunk($unchangedIds, 500) as $chunk) {
                Review::query()->whereIn('id', $chunk)->update(['last_seen_at' => $now]);
            }
        });

        return new SyncStats(created: $created, updated: $updated, unchanged: count($unchangedIds));
    }

    /** @return array<string, mixed> */
    private function newRow(Organization $organization, ReviewData $review, Carbon $now): array
    {
        return [
            'organization_id' => $organization->id,
            'external_id' => $review->externalId,
            'author_name' => $review->authorName,
            'author_avatar_url' => $review->authorAvatarUrl,
            'author_level' => $review->authorLevel,
            'rating' => $review->rating,
            'text' => $review->text,
            'business_comment_text' => $review->businessCommentText,
            'business_comment_at' => $review->businessCommentAt,
            'published_at' => $review->publishedAt,
            'content_hash' => $review->contentHash(),
            'photos_count' => $review->photosCount,
            'likes_count' => $review->likesCount,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function applyChange(Review $review, ReviewData $incoming, Carbon $now, ?ParseRun $run): void
    {
        $changes = [];

        foreach ($this->incomingValues($incoming) as $field => $value) {
            $old = $review->getAttribute($field);

            if (! $this->differs($old, $value)) {
                continue;
            }

            if (in_array($field, self::TRACKED_FIELDS, true)) {
                $changes[$field] = [
                    'old' => $this->presentable($old),
                    'new' => $this->presentable($value),
                ];
            }

            $review->setAttribute($field, $value);
        }

        $review->content_hash = $incoming->contentHash();
        $review->last_seen_at = $now;

        DB::transaction(function () use ($review, $changes, $run, $now): void {
            $review->save();

            if ($changes !== []) {
                ReviewRevision::query()->create([
                    'review_id' => $review->id,
                    'parse_run_id' => $run?->id,
                    'changes' => $changes,
                    'created_at' => $now,
                ]);
            }
        });
    }

    /** Keeps cosmetic fields current without touching the hash or history. */
    private function refreshVolatile(Review $review, ReviewData $incoming): void
    {
        $values = $this->incomingValues($incoming);
        $dirty = false;

        foreach (self::VOLATILE_FIELDS as $field) {
            if ($this->differs($review->getAttribute($field), $values[$field])) {
                $review->setAttribute($field, $values[$field]);
                $dirty = true;
            }
        }

        if ($dirty) {
            $review->saveQuietly();
        }
    }

    /** @return array<string, mixed> */
    private function incomingValues(ReviewData $review): array
    {
        return [
            'author_name' => $review->authorName,
            'author_avatar_url' => $review->authorAvatarUrl,
            'author_level' => $review->authorLevel,
            'rating' => $review->rating,
            'text' => $review->text,
            'business_comment_text' => $review->businessCommentText,
            'business_comment_at' => $review->businessCommentAt !== null
                ? Carbon::instance($review->businessCommentAt)
                : null,
            'published_at' => Carbon::instance($review->publishedAt),
            'photos_count' => $review->photosCount,
            'likes_count' => $review->likesCount,
        ];
    }

    private function differs(mixed $old, mixed $new): bool
    {
        if ($old instanceof Carbon || $new instanceof Carbon) {
            if (! ($old instanceof Carbon && $new instanceof Carbon)) {
                return true; // a date appeared or disappeared
            }

            // Yandex sends milliseconds (2026-09-09T09:34:39.244Z) while the
            // column stores whole seconds. Comparing finer than the storage
            // does would report every review as edited on every parse.
            return $old->getTimestamp() !== $new->getTimestamp();
        }

        return $old !== $new;
    }

    private function presentable(mixed $value): mixed
    {
        return $value instanceof Carbon ? $value->toIso8601String() : $value;
    }
}
