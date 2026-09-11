<?php

declare(strict_types=1);

namespace Tests\Feature\Parsing;

use App\Enums\ParseStatus;
use App\Enums\ParseTrigger;
use App\Jobs\ParseOrganizationJob;
use App\Models\Organization;
use App\Models\ParseRun;
use App\Models\Review;
use App\Models\ReviewRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end through the job: fixtures in, database rows out. Nothing here
 * touches the network — Yandex is replaced by the captured responses.
 */
final class ParseOrganizationJobTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    /** What the fake source serves for page 1; swapped between runs. */
    private string $firstPage = '';

    /** What it serves for every later page; the captured "out of pages" body. */
    private string $laterPages = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'external_id' => '1124715036',
            'url' => 'https://yandex.ru/maps/org/1124715036/reviews/',
            'parse_status' => null,
            'name' => null,
            'rating_value' => null,
        ]);
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/Yandex/{$name}"));
    }

    /**
     * Installs one fake that reads the current page bodies on every call.
     *
     * Calling Http::fake() a second time merges rather than replaces, and the
     * earliest matching stub wins — so a test that changes what the source
     * serves has to swap the body, not re-register the fake.
     */
    private function fakeSource(?string $firstPage = null, ?string $laterPages = null): void
    {
        $this->firstPage = $firstPage ?? $this->pageWithCount(5);
        $this->laterPages = $laterPages ?? $this->fixture('org_page_empty.html');

        Http::fake(fn ($request) => Http::response(
            str_contains($request->url(), 'page=') ? $this->laterPages : $this->firstPage,
        ));
    }

    /** Changes what the source serves without re-registering the fake. */
    private function sourceNowServes(string $firstPage, ?string $laterPages = null): void
    {
        $this->firstPage = $firstPage;

        if ($laterPages !== null) {
            $this->laterPages = $laterPages;
        }
    }

    /** Rewrites the card's review counter so pagination expects a single page. */
    private function pageWithCount(int $reviewCount): string
    {
        $html = $this->fixture('org_page1.html');

        preg_match('~<script type="application/json" class="state-view">(.*?)</script>~s', $html, $m);
        $state = json_decode(str_replace('<', '<', $m[1]), true);

        $item = &$state['stack'][0]['results']['items'][0];
        $item['ratingData']['reviewCount'] = $reviewCount;
        $item['reviewResults']['params']['count'] = $reviewCount;
        $item['reviewResults']['params']['totalPages'] = (int) ceil($reviewCount / 50);

        $encoded = str_replace('<', '<', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $html = preg_replace('~(itemProp="reviewCount" content=")\d+(")~', '${1}'.$reviewCount.'$2', $html);

        return str_replace($m[0], '<script type="application/json" class="state-view">'.$encoded.'</script>', $html);
    }

    private function runJob(): ParseRun
    {
        (new ParseOrganizationJob($this->organization->id, ParseTrigger::Initial))
            ->handle(app(\App\Services\Organizations\OrganizationParseService::class));

        return ParseRun::query()->latest('id')->firstOrFail();
    }

    #[Test]
    public function it_fills_the_card_and_stores_its_reviews(): void
    {
        $this->fakeSource();

        $run = $this->runJob();
        $this->organization->refresh();

        $this->assertSame(ParseStatus::Success, $run->status);
        $this->assertSame('Яндекс', $this->organization->name);
        $this->assertSame('Москва, ул. Льва Толстого, 16', $this->organization->address);
        $this->assertSame(4.9, $this->organization->rating_value);
        $this->assertSame(21218, $this->organization->ratings_count);
        $this->assertSame(5, $this->organization->reviews_count);
        $this->assertSame(5, $this->organization->reviews_stored);
        $this->assertSame(ParseStatus::Success, $this->organization->parse_status);
        $this->assertNotNull($this->organization->last_parsed_at);
        $this->assertDatabaseCount('reviews', 5);
    }

    #[Test]
    public function it_records_progress_and_a_snapshot(): void
    {
        $this->fakeSource();

        $run = $this->runJob();

        $this->assertSame(1, $run->pages_done);
        $this->assertSame(1, $run->pages_expected);
        $this->assertSame(5, $run->reviews_seen);
        $this->assertSame(5, $run->reviews_created);
        $this->assertNotNull($run->duration_ms);

        $this->assertDatabaseHas('organization_snapshots', [
            'organization_id' => $this->organization->id,
            'parse_run_id' => $run->id,
            'ratings_count' => 21218,
            'reviews_stored' => 5,
        ]);
    }

    #[Test]
    public function a_second_run_changes_nothing_when_the_source_has_not_changed(): void
    {
        $this->fakeSource();

        $this->runJob();
        $second = $this->runJob();

        $this->assertDatabaseCount('reviews', 5);
        $this->assertSame(0, $second->reviews_created);
        $this->assertSame(0, $second->reviews_updated);
        $this->assertDatabaseCount('review_revisions', 0);
    }

    #[Test]
    public function an_edited_review_shows_up_as_history_on_the_next_run(): void
    {
        $this->fakeSource();
        $this->runJob();

        // The source now serves a different text for the same review id.
        $edited = str_replace(
            'Это отзыв о посещении офиса',
            'Отредактированный отзыв о посещении офиса',
            $this->pageWithCount(5),
        );

        $this->sourceNowServes($edited);

        $run = $this->runJob();

        $this->assertDatabaseCount('reviews', 5);
        $this->assertSame(1, $run->reviews_updated);

        $revision = ReviewRevision::query()->firstOrFail();
        $this->assertArrayHasKey('text', $revision->changes);
        $this->assertStringContainsString('Отредактированный', (string) $revision->changes['text']['new']);
        $this->assertSame($run->id, $revision->parse_run_id);
    }

    #[Test]
    public function a_captcha_marks_the_run_blocked_and_keeps_the_previous_data(): void
    {
        $this->fakeSource();
        $this->runJob();

        $this->sourceNowServes($this->fixture('captcha.html'), $this->fixture('captcha.html'));

        try {
            $this->runJob();
        } catch (\App\Services\Parsing\Exceptions\SourceBlockedException) {
            // Rethrown on purpose so the queue retries with a longer backoff.
        }

        $run = ParseRun::query()->latest('id')->firstOrFail();
        $this->organization->refresh();

        $this->assertSame(ParseStatus::Blocked, $run->status);
        $this->assertSame('source_captcha', $run->error_code);
        $this->assertSame(ParseStatus::Blocked, $this->organization->parse_status);

        // Being blocked must not wipe what was collected before.
        $this->assertDatabaseCount('reviews', 5);
        $this->assertSame(4.9, $this->organization->rating_value);
    }

    #[Test]
    public function a_changed_layout_fails_the_run_with_a_precise_code(): void
    {
        $this->fakeSource('<html><body>Совсем другая страница</body></html>');

        try {
            $this->runJob();
        } catch (\App\Services\Parsing\Exceptions\LayoutChangedException) {
            // Recorded first, then rethrown for the queue to fail the job.
        }

        $run = ParseRun::query()->latest('id')->firstOrFail();

        $this->assertSame(ParseStatus::Failed, $run->status);
        $this->assertSame('state_script_missing', $run->error_code);
        $this->assertNotNull($run->error_message);
        $this->assertDatabaseCount('reviews', 0);
    }

    #[Test]
    public function a_card_the_source_no_longer_has_is_reported_as_missing(): void
    {
        Http::fake(fn () => Http::response('Not found', 404));

        try {
            $this->runJob();
        } catch (\App\Services\Parsing\Exceptions\OrganizationNotFoundException) {
        }

        $this->assertSame('organization_not_found', ParseRun::query()->latest('id')->firstOrFail()->error_code);
    }

    #[Test]
    public function a_truncated_card_is_marked_partial_with_an_explanation(): void
    {
        // 5859 reviews but only one page served: the source's own ceiling.
        $this->fakeSource($this->fixture('org_page1.html'));

        $run = $this->runJob();

        $this->assertSame(ParseStatus::Partial, $run->status);
        $this->assertNotEmpty($run->warnings);
        $this->assertStringContainsString('5859', implode(' ', $run->warnings));
        $this->assertDatabaseCount('reviews', 5);
    }

    #[Test]
    public function reviews_survive_disappearing_from_the_source(): void
    {
        // Yandex only shows the last ~600; anything older drops off its pages.
        // Our copy must keep it rather than delete what the source forgot.
        $this->fakeSource();
        $this->runJob();

        $keptId = Review::query()->firstOrFail()->id;

        $shorter = $this->pageWithCount(5);
        preg_match('~<script type="application/json" class="state-view">(.*?)</script>~s', $shorter, $m);
        $state = json_decode(str_replace('<', '<', $m[1]), true);
        $state['stack'][0]['results']['items'][0]['reviewResults']['reviews'] = array_slice(
            $state['stack'][0]['results']['items'][0]['reviewResults']['reviews'],
            1,
        );
        $shorter = str_replace($m[0], '<script type="application/json" class="state-view">'.
            str_replace('<', '<', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).
            '</script>', $shorter);

        $this->sourceNowServes($shorter);

        $this->runJob();

        $this->assertDatabaseCount('reviews', 5);
        $this->assertDatabaseHas('reviews', ['id' => $keptId]);
    }
}
