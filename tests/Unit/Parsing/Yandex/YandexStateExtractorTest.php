<?php

declare(strict_types=1);

namespace Tests\Unit\Parsing\Yandex;

use App\Services\Parsing\Exceptions\LayoutChangedException;
use App\Services\Parsing\Yandex\YandexStateExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures are real responses captured from yandex.ru/maps on 2026-09-11 and
 * trimmed to five reviews per page. No test here touches the network.
 */
final class YandexStateExtractorTest extends TestCase
{
    private YandexStateExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new YandexStateExtractor();
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../../../Fixtures/Yandex/'.$name);
    }

    /** Replaces the state JSON of a real page, keeping the rest of the HTML. */
    private function withState(string $fixture, callable $mutate): string
    {
        $html = $this->fixture($fixture);
        preg_match('~<script type="application/json" class="state-view">(.*?)</script>~s', $html, $m);
        $state = json_decode(str_replace('<', '<', $m[1]), true, 512, JSON_THROW_ON_ERROR);

        $mutated = $mutate($state);

        $encoded = str_replace('<', '<', json_encode($mutated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return str_replace($m[0], '<script type="application/json" class="state-view">'.$encoded.'</script>', $html);
    }

    #[Test]
    public function it_reads_the_aggregates(): void
    {
        $page = $this->extractor->extract($this->fixture('org_page1.html'), 1);

        $this->assertSame('1124715036', $page->profile->externalId);
        $this->assertSame('Яндекс', $page->profile->name);
        $this->assertSame('Москва, ул. Льва Толстого, 16', $page->profile->address);
        $this->assertSame('IT-компания', $page->profile->category);
        $this->assertSame(4.9, round($page->profile->ratingValue, 1));
        $this->assertSame(21218, $page->profile->ratingsCount);
        $this->assertSame(5859, $page->profile->reviewsCount);
    }

    #[Test]
    public function it_reads_reviews_with_every_field(): void
    {
        $page = $this->extractor->extract($this->fixture('org_page1.html'), 1);

        $this->assertNotNull($page->reviews);
        $this->assertCount(5, $page->reviews->reviews);

        $first = $page->reviews->reviews[0];
        $this->assertSame('FSKmRod3e4-YRCZZ3T01kvo-akduL0Dm', $first->externalId);
        $this->assertSame('Ветрокрылова', $first->authorName);
        $this->assertSame('Знаток города 12 уровня', $first->authorLevel);
        $this->assertSame(5, $first->rating);
        $this->assertStringContainsString('Это отзыв о посещении офиса', $first->text);
        $this->assertSame('2026-07-20', $first->publishedAt->format('Y-m-d'));
        $this->assertStringContainsString('Благодарим за поддержку', (string) $first->businessCommentText);
        $this->assertSame(5, $first->photosCount);
        $this->assertSame(6, $first->likesCount);
    }

    #[Test]
    public function it_resolves_the_avatar_size_placeholder(): void
    {
        $page = $this->extractor->extract($this->fixture('org_page1.html'), 1);

        $avatar = (string) $page->reviews->reviews[0]->authorAvatarUrl;
        $this->assertStringNotContainsString('{size}', $avatar);
        $this->assertStringStartsWith('https://avatars.mds.yandex.net/', $avatar);
    }

    #[Test]
    public function it_reads_pagination_metadata(): void
    {
        $page = $this->extractor->extract($this->fixture('org_page2.html'), 2);

        $this->assertSame(2, $page->reviews->page);
        $this->assertSame(50, $page->reviews->offset);
        $this->assertSame(50, $page->reviews->limit);
        $this->assertSame(5859, $page->reviews->totalCount);
        $this->assertSame(118, $page->reviews->totalPages);
    }

    #[Test]
    public function it_returns_no_reviews_past_the_last_available_page(): void
    {
        // Yandex stops serving reviewResults after page 12; aggregates stay.
        $page = $this->extractor->extract($this->fixture('org_page_empty.html'), 13);

        $this->assertNull($page->reviews);
        $this->assertSame(5859, $page->profile->reviewsCount);
    }

    #[Test]
    public function it_fails_when_the_state_script_disappears(): void
    {
        $html = preg_replace(
            '~<script type="application/json" class="state-view">.*?</script>~s',
            '',
            $this->fixture('org_page1.html'),
        );

        $this->assertLayoutCode(LayoutChangedException::STATE_SCRIPT_MISSING, $html);
    }

    #[Test]
    public function it_fails_when_the_state_is_not_json(): void
    {
        $html = preg_replace(
            '~(<script type="application/json" class="state-view">).*?(</script>)~s',
            '$1{"config":$2',
            $this->fixture('org_page1.html'),
        );

        $this->assertLayoutCode(LayoutChangedException::STATE_JSON_INVALID, $html);
    }

    #[Test]
    public function it_fails_when_the_results_path_moves(): void
    {
        $html = $this->withState('org_page1.html', function (array $state): array {
            $state['stack'][0]['searchResults'] = $state['stack'][0]['results'];
            unset($state['stack'][0]['results']);

            return $state;
        });

        $this->assertLayoutCode(LayoutChangedException::RESULTS_PATH_MISSING, $html);
    }

    #[Test]
    public function it_fails_when_the_card_is_not_a_business(): void
    {
        $html = $this->withState('org_page1.html', function (array $state): array {
            $state['stack'][0]['results']['items'][0]['type'] = 'toponym';

            return $state;
        });

        $this->assertLayoutCode(LayoutChangedException::BUSINESS_NOT_FOUND, $html);
    }

    #[Test]
    public function it_fails_when_rating_data_disappears(): void
    {
        $html = $this->withState('org_page1.html', function (array $state): array {
            unset($state['stack'][0]['results']['items'][0]['ratingData']);

            return $state;
        });

        $this->assertLayoutCode(LayoutChangedException::RATING_DATA_MISSING, $html);
    }

    #[Test]
    public function it_fails_when_the_rating_is_out_of_range(): void
    {
        $html = $this->withState('org_page1.html', function (array $state): array {
            $state['stack'][0]['results']['items'][0]['ratingData']['ratingValue'] = 49;

            return $state;
        });

        $this->assertLayoutCode(LayoutChangedException::RATING_DATA_INVALID, $html);
    }

    #[Test]
    public function it_fails_when_the_first_page_has_no_reviews_but_the_counter_says_it_should(): void
    {
        // The dangerous case: a silent layout change would look like an
        // organisation that simply has no reviews.
        $html = $this->withState('org_page1.html', function (array $state): array {
            unset($state['stack'][0]['results']['items'][0]['reviewResults']);

            return $state;
        });

        $this->assertLayoutCode(LayoutChangedException::REVIEW_RESULTS_MISSING, $html, 1);
    }

    #[Test]
    public function it_accepts_a_card_that_genuinely_has_no_reviews(): void
    {
        $html = $this->withState('org_page1.html', function (array $state): array {
            unset($state['stack'][0]['results']['items'][0]['reviewResults']);
            $state['stack'][0]['results']['items'][0]['ratingData'] = [
                'ratingValue' => 0, 'ratingCount' => 0, 'reviewCount' => 0,
            ];

            return $state;
        });

        $page = $this->extractor->extract($html, 1);

        $this->assertNull($page->reviews);
        $this->assertSame(0, $page->profile->reviewsCount);
    }

    #[Test]
    public function it_keeps_a_review_that_has_text_but_no_stars(): void
    {
        // Seen on the live card: Yandex sends rating 0 for reviews whose author
        // wrote a comment without rating the place. Dropping those would lose
        // real reviews and silently undercount the page.
        $html = $this->withState('org_page1.html', function (array $state): array {
            $state['stack'][0]['results']['items'][0]['reviewResults']['reviews'][0]['rating'] = 0;

            return $state;
        });

        $page = $this->extractor->extract($html, 1);

        $this->assertCount(5, $page->reviews->reviews);
        $this->assertNull($page->reviews->reviews[0]->rating);
        $this->assertNotSame('', $page->reviews->reviews[0]->text);
    }

    #[Test]
    public function it_drops_a_review_whose_rating_is_out_of_range(): void
    {
        $html = $this->withState('org_page1.html', function (array $state): array {
            $state['stack'][0]['results']['items'][0]['reviewResults']['reviews'][0]['rating'] = 42;

            return $state;
        });

        $page = $this->extractor->extract($html, 1);

        $this->assertCount(4, $page->reviews->reviews);
        $this->assertNotEmpty($page->warnings);
    }

    #[Test]
    public function it_fails_when_reviews_lose_their_shape(): void
    {
        $html = $this->withState('org_page1.html', function (array $state): array {
            foreach ($state['stack'][0]['results']['items'][0]['reviewResults']['reviews'] as $i => $review) {
                unset($review['reviewId'], $review['updatedTime']);
                $state['stack'][0]['results']['items'][0]['reviewResults']['reviews'][$i] = $review;
            }

            return $state;
        });

        $this->assertLayoutCode(LayoutChangedException::REVIEW_SHAPE_MISMATCH, $html);
    }

    #[Test]
    public function it_tolerates_a_single_malformed_review(): void
    {
        $html = $this->withState('org_page1.html', function (array $state): array {
            unset($state['stack'][0]['results']['items'][0]['reviewResults']['reviews'][2]['reviewId']);

            return $state;
        });

        $page = $this->extractor->extract($html, 1);

        $this->assertCount(4, $page->reviews->reviews);
        $this->assertNotEmpty($page->warnings);
    }

    #[Test]
    public function it_fails_when_pagination_metadata_disappears(): void
    {
        $html = $this->withState('org_page1.html', function (array $state): array {
            unset($state['stack'][0]['results']['items'][0]['reviewResults']['params']);

            return $state;
        });

        $this->assertLayoutCode(LayoutChangedException::PAGINATION_MISSING, $html);
    }

    #[Test]
    public function it_warns_when_the_state_disagrees_with_the_microdata_on_the_same_page(): void
    {
        // Microdata is an independent rendering of the same numbers; if the two
        // drift apart, one of the two readings has gone stale.
        $html = $this->withState('org_page1.html', function (array $state): array {
            $state['stack'][0]['results']['items'][0]['ratingData']['reviewCount'] = 1;

            return $state;
        });

        $page = $this->extractor->extract($html, 1);

        $this->assertNotEmpty($page->warnings);
        $this->assertStringContainsString('microdata', implode(' ', $page->warnings));
    }

    #[Test]
    public function it_does_not_html_decode_the_state_payload(): void
    {
        // Yandex escapes only "<" as < inside the JSON; review texts keep
        // raw ampersands. Running html_entity_decode over the payload would
        // silently corrupt them.
        $html = $this->withState('org_page1.html', function (array $state): array {
            $state['stack'][0]['results']['items'][0]['reviewResults']['reviews'][0]['text']
                = 'Rock &amp; Roll &lt;3';

            return $state;
        });

        $page = $this->extractor->extract($html, 1);

        $this->assertSame('Rock &amp; Roll &lt;3', $page->reviews->reviews[0]->text);
    }

    private function assertLayoutCode(string $expected, string $html, int $page = 1): void
    {
        try {
            $this->extractor->extract($html, $page);
            $this->fail("Expected LayoutChangedException [{$expected}], none thrown");
        } catch (LayoutChangedException $e) {
            $this->assertSame($expected, $e->errorCode());
        }
    }
}
