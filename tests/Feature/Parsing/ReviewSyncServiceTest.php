<?php

declare(strict_types=1);

namespace Tests\Feature\Parsing;

use App\Models\Organization;
use App\Models\ParseRun;
use App\Models\Review;
use App\Models\ReviewRevision;
use App\Services\Parsing\Data\ReviewData;
use App\Services\Organizations\ReviewSyncService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReviewSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReviewSyncService $service;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ReviewSyncService::class);
        $this->organization = Organization::factory()->create();
    }

    private function review(string $id, array $overrides = []): ReviewData
    {
        return new ReviewData(
            externalId: $id,
            authorName: $overrides['authorName'] ?? 'Иван',
            authorAvatarUrl: $overrides['authorAvatarUrl'] ?? null,
            authorLevel: $overrides['authorLevel'] ?? null,
            rating: array_key_exists('rating', $overrides) ? $overrides['rating'] : 5,
            text: $overrides['text'] ?? 'Отличное место',
            publishedAt: new DateTimeImmutable($overrides['publishedAt'] ?? '2026-01-15T10:00:00Z'),
            businessCommentText: $overrides['businessCommentText'] ?? null,
            businessCommentAt: isset($overrides['businessCommentAt'])
                ? new DateTimeImmutable($overrides['businessCommentAt'])
                : null,
            photosCount: $overrides['photosCount'] ?? 0,
            likesCount: $overrides['likesCount'] ?? 0,
        );
    }

    #[Test]
    public function it_stores_reviews_on_the_first_run(): void
    {
        $stats = $this->service->sync($this->organization, [
            $this->review('a'), $this->review('b'),
        ]);

        $this->assertSame(2, $stats->created);
        $this->assertSame(0, $stats->updated);
        $this->assertDatabaseCount('reviews', 2);
    }

    #[Test]
    public function re_parsing_the_same_reviews_creates_no_duplicates(): void
    {
        $batch = [$this->review('a'), $this->review('b'), $this->review('c')];

        $this->service->sync($this->organization, $batch);
        $stats = $this->service->sync($this->organization, $batch);

        $this->assertDatabaseCount('reviews', 3);
        $this->assertSame(0, $stats->created);
        $this->assertSame(0, $stats->updated);
        $this->assertSame(3, $stats->unchanged);
    }

    #[Test]
    public function an_unchanged_review_only_gets_its_last_seen_timestamp_refreshed(): void
    {
        $this->service->sync($this->organization, [$this->review('a')]);

        $before = Review::firstOrFail();
        $this->travel(2)->hours();

        $this->service->sync($this->organization, [$this->review('a')]);

        $after = Review::firstOrFail();
        $this->assertTrue($after->last_seen_at->gt($before->last_seen_at));
        $this->assertEquals($before->first_seen_at, $after->first_seen_at);
        $this->assertSame($before->content_hash, $after->content_hash);
    }

    #[Test]
    public function an_edited_review_is_updated_and_its_change_recorded(): void
    {
        $run = ParseRun::factory()->for($this->organization)->create();

        $this->service->sync($this->organization, [$this->review('a', ['text' => 'Было нормально', 'rating' => 4])]);
        $stats = $this->service->sync(
            $this->organization,
            [$this->review('a', ['text' => 'Стало плохо', 'rating' => 2])],
            $run,
        );

        $this->assertSame(1, $stats->updated);
        $this->assertDatabaseCount('reviews', 1);

        $review = Review::firstOrFail();
        $this->assertSame('Стало плохо', $review->text);
        $this->assertSame(2, $review->rating);

        $revision = ReviewRevision::firstOrFail();
        $this->assertSame($review->id, $revision->review_id);
        $this->assertSame($run->id, $revision->parse_run_id);
        $this->assertSame('Было нормально', $revision->changes['text']['old']);
        $this->assertSame('Стало плохо', $revision->changes['text']['new']);
        $this->assertSame(4, $revision->changes['rating']['old']);
        $this->assertSame(2, $revision->changes['rating']['new']);
    }

    #[Test]
    public function it_records_a_reply_appearing_under_an_existing_review(): void
    {
        $this->service->sync($this->organization, [$this->review('a')]);
        $this->service->sync($this->organization, [
            $this->review('a', [
                'businessCommentText' => 'Спасибо за отзыв!',
                'businessCommentAt' => '2026-02-01T09:00:00Z',
            ]),
        ]);

        $revision = ReviewRevision::firstOrFail();
        $this->assertNull($revision->changes['business_comment_text']['old']);
        $this->assertSame('Спасибо за отзыв!', $revision->changes['business_comment_text']['new']);
        $this->assertSame('Спасибо за отзыв!', Review::firstOrFail()->business_comment_text);
    }

    #[Test]
    public function sub_second_precision_in_dates_is_not_mistaken_for_an_edit(): void
    {
        // The source sends milliseconds, the column keeps whole seconds. Left
        // unhandled, this marked every review as edited on every single parse.
        $this->service->sync($this->organization, [
            $this->review('a', ['publishedAt' => '2026-09-09T09:34:39.244Z']),
        ]);

        $stats = $this->service->sync($this->organization, [
            $this->review('a', ['publishedAt' => '2026-09-09T09:34:39.871Z']),
        ]);

        $this->assertSame(1, $stats->unchanged);
        $this->assertDatabaseCount('review_revisions', 0);
    }

    #[Test]
    public function a_genuinely_different_date_is_still_recorded(): void
    {
        $this->service->sync($this->organization, [
            $this->review('a', ['publishedAt' => '2026-09-09T09:34:39Z']),
        ]);

        $this->service->sync($this->organization, [
            $this->review('a', ['publishedAt' => '2026-09-10T09:34:39Z']),
        ]);

        $this->assertArrayHasKey('published_at', ReviewRevision::firstOrFail()->changes);
    }

    #[Test]
    public function it_does_not_record_a_revision_for_noise_fields(): void
    {
        // Likes drift constantly; treating that as an edit would bury the real
        // changes under thousands of meaningless revisions.
        $this->service->sync($this->organization, [$this->review('a', ['likesCount' => 1])]);
        $stats = $this->service->sync($this->organization, [$this->review('a', ['likesCount' => 99])]);

        $this->assertSame(0, $stats->updated);
        $this->assertDatabaseCount('review_revisions', 0);
        $this->assertSame(99, Review::firstOrFail()->likes_count);
    }

    #[Test]
    public function it_stores_a_review_that_has_no_rating(): void
    {
        $this->service->sync($this->organization, [$this->review('a', ['rating' => null])]);

        $this->assertNull(Review::firstOrFail()->rating);
    }

    #[Test]
    public function a_rating_appearing_on_a_previously_unrated_review_is_recorded(): void
    {
        $this->service->sync($this->organization, [$this->review('a', ['rating' => null])]);
        $this->service->sync($this->organization, [$this->review('a', ['rating' => 3])]);

        $revision = ReviewRevision::firstOrFail();
        $this->assertNull($revision->changes['rating']['old']);
        $this->assertSame(3, $revision->changes['rating']['new']);
    }

    #[Test]
    public function reviews_of_different_organisations_do_not_collide(): void
    {
        $other = Organization::factory()->create();

        $this->service->sync($this->organization, [$this->review('same-id')]);
        $this->service->sync($other, [$this->review('same-id')]);

        $this->assertDatabaseCount('reviews', 2);
    }

    #[Test]
    public function it_handles_a_realistic_batch_in_one_pass(): void
    {
        $batch = [];

        for ($i = 0; $i < 600; $i++) {
            $batch[] = $this->review("review-{$i}", ['text' => "Отзыв номер {$i}"]);
        }

        $stats = $this->service->sync($this->organization, $batch);

        $this->assertSame(600, $stats->created);
        $this->assertDatabaseCount('reviews', 600);

        // Second pass over the same 600: nothing new, nothing changed.
        $stats = $this->service->sync($this->organization, $batch);
        $this->assertSame(600, $stats->unchanged);
        $this->assertDatabaseCount('reviews', 600);
    }
}
