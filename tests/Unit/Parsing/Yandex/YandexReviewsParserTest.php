<?php

declare(strict_types=1);

namespace Tests\Unit\Parsing\Yandex;

use App\Services\Parsing\Exceptions\LayoutChangedException;
use App\Services\Parsing\Exceptions\SourceBlockedException;
use App\Services\Parsing\Support\ProxyPool;
use App\Services\Parsing\Support\RequestThrottle;
use App\Services\Parsing\Support\UserAgentRotator;
use App\Services\Parsing\Yandex\YandexHttpClient;
use App\Services\Parsing\Yandex\YandexReviewsParser;
use App\Services\Parsing\Yandex\YandexStateExtractor;
use App\Services\Parsing\Yandex\YandexUrlParser;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class YandexReviewsParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../../../Fixtures/Yandex/'.$name);
    }

    /**
     * Rewrites the fixture so each requested page carries reviews with ids of
     * its own — the real source never repeats a review across pages.
     */
    private function pageWithDistinctReviews(int $page, int $reviewCount = 5859): string
    {
        $html = $this->fixture('org_page1.html');

        preg_match('~<script type="application/json" class="state-view">(.*?)</script>~s', $html, $m);
        $state = json_decode(str_replace('<', '<', $m[1]), true);

        $results = &$state['stack'][0]['results']['items'][0];
        $results['ratingData']['reviewCount'] = $reviewCount;
        $results['reviewResults']['params'] = [
            'offset' => ($page - 1) * 50,
            'limit' => 50,
            'count' => $reviewCount,
            'page' => $page,
            'totalPages' => (int) ceil($reviewCount / 50),
        ];

        foreach ($results['reviewResults']['reviews'] as $i => $review) {
            $results['reviewResults']['reviews'][$i]['reviewId'] = "p{$page}-r{$i}";
        }

        $encoded = str_replace('<', '<', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Keep the microdata in step so the cross-check stays quiet.
        $html = preg_replace('~(itemProp="reviewCount" content=")\d+(")~', '${1}'.$reviewCount.'$2', $html);

        return str_replace(
            $m[0],
            '<script type="application/json" class="state-view">'.$encoded.'</script>',
            $html,
        );
    }

    /** @param array<string, mixed> $responses url pattern => response */
    private function parser(array $responses, int $maxPages = 12): YandexReviewsParser
    {
        $http = new HttpFactory();
        $http->fake($responses);

        return new YandexReviewsParser(
            new YandexUrlParser(),
            new YandexHttpClient(
                $http,
                new UserAgentRotator(),
                new RequestThrottle(0, 0, sleeper: fn () => null),
                new ProxyPool(),
            ),
            new YandexStateExtractor(),
            maxPages: $maxPages,
        );
    }

    #[Test]
    public function it_walks_every_page_until_the_source_runs_out(): void
    {
        $parser = $this->parser([
            // 3 pages' worth of reviews, then the source stops serving them.
            // Http::fake matches in order, so exact URLs come before wildcards.
            'yandex.ru/maps/org/1124715036/reviews/' => $this->pageWithDistinctReviews(1, 150),
            'yandex.ru/maps/org/1124715036/reviews/?page=2' => $this->pageWithDistinctReviews(2, 150),
            'yandex.ru/maps/org/1124715036/reviews/?page=3' => $this->pageWithDistinctReviews(3, 150),
            'yandex.ru/maps/org/1124715036/reviews/*' => $this->fixture('org_page_empty.html'),
        ]);

        $result = $parser->fetch($parser->parseUrl('https://yandex.ru/maps/org/1124715036/'));

        $this->assertCount(15, $result->reviews);
        $this->assertSame(3, $result->pagesFetched);
        $this->assertSame('Яндекс', $result->profile->name);
        $this->assertSame(150, $result->profile->reviewsCount);
    }

    #[Test]
    public function it_stops_at_the_page_limit_the_source_imposes(): void
    {
        // Yandex serves at most 12 pages (~600 reviews) however large the card.
        $pages = [];

        for ($page = 2; $page <= 20; $page++) {
            $pages["yandex.ru/maps/org/1124715036/reviews/?page={$page}"] = $this->pageWithDistinctReviews($page);
        }

        $pages = ['yandex.ru/maps/org/1124715036/reviews/' => $this->pageWithDistinctReviews(1)] + $pages;

        $parser = $this->parser($pages, maxPages: 12);
        $result = $parser->fetch($parser->parseUrl('https://yandex.ru/maps/org/1124715036/'));

        $this->assertSame(12, $result->pagesFetched);
        $this->assertCount(60, $result->reviews);
        $this->assertNotEmpty($result->warnings, 'A card truncated by the source should say so');
    }

    #[Test]
    public function it_never_returns_the_same_review_twice(): void
    {
        // Same page content served for every request: a naive walker would
        // happily collect the first page over and over.
        $parser = $this->parser([
            'yandex.ru/maps/org/1124715036/reviews*' => $this->pageWithDistinctReviews(1, 150),
        ]);

        $result = $parser->fetch($parser->parseUrl('https://yandex.ru/maps/org/1124715036/'));

        $ids = array_map(fn ($review) => $review->externalId, $result->reviews);
        $this->assertSame($ids, array_unique($ids));
        $this->assertCount(5, $result->reviews);
    }

    #[Test]
    public function it_does_not_request_more_pages_than_the_review_count_needs(): void
    {
        $parser = $this->parser([
            'yandex.ru/maps/org/1124715036/reviews/' => $this->pageWithDistinctReviews(1, 30),
            'yandex.ru/maps/org/1124715036/reviews*' => $this->fixture('org_page_empty.html'),
        ]);

        $result = $parser->fetch($parser->parseUrl('https://yandex.ru/maps/org/1124715036/'));

        $this->assertSame(1, $result->pagesFetched);
        $this->assertSame(1, $result->pagesExpected);
    }

    #[Test]
    public function it_reports_progress_page_by_page(): void
    {
        $parser = $this->parser([
            'yandex.ru/maps/org/1124715036/reviews/' => $this->pageWithDistinctReviews(1, 100),
            'yandex.ru/maps/org/1124715036/reviews/?page=2' => $this->pageWithDistinctReviews(2, 100),
        ]);

        $progress = [];
        $parser->fetch(
            $parser->parseUrl('https://yandex.ru/maps/org/1124715036/'),
            function (int $done, int $expected, int $reviews) use (&$progress): void {
                $progress[] = [$done, $expected, $reviews];
            },
        );

        $this->assertSame([[1, 2, 5], [2, 2, 10]], $progress);
    }

    #[Test]
    public function it_surfaces_a_captcha_instead_of_storing_its_contents(): void
    {
        $parser = $this->parser([
            'yandex.ru/maps/org/1124715036/reviews*' => $this->fixture('captcha.html'),
        ]);

        $this->expectException(SourceBlockedException::class);

        $parser->fetch($parser->parseUrl('https://yandex.ru/maps/org/1124715036/'));
    }

    #[Test]
    public function it_fails_loudly_when_the_first_page_is_unreadable(): void
    {
        $parser = $this->parser([
            'yandex.ru/maps/org/1124715036/reviews*' => '<html><body>totally different page</body></html>',
        ]);

        $this->expectException(LayoutChangedException::class);

        $parser->fetch($parser->parseUrl('https://yandex.ru/maps/org/1124715036/'));
    }

    #[Test]
    public function it_keeps_what_it_collected_when_a_later_page_breaks(): void
    {
        // Losing page 7 of 12 should not throw away pages 1-6: partial data with
        // a warning beats no data at all.
        $parser = $this->parser([
            'yandex.ru/maps/org/1124715036/reviews/' => $this->pageWithDistinctReviews(1, 600),
            'yandex.ru/maps/org/1124715036/reviews/?page=2' => $this->pageWithDistinctReviews(2, 600),
            'yandex.ru/maps/org/1124715036/reviews/?page=3' => '<html><body>broken</body></html>',
        ]);

        $result = $parser->fetch($parser->parseUrl('https://yandex.ru/maps/org/1124715036/'));

        $this->assertCount(10, $result->reviews);
        $this->assertSame(2, $result->pagesFetched);
        $this->assertNotEmpty($result->warnings);
    }

    #[Test]
    public function it_resolves_short_links_before_parsing_them(): void
    {
        $http = new HttpFactory();
        $http->fake([
            'yandex.ru/maps/-/CDbmZ8x' => $http::response('', 301, [
                'Location' => 'https://yandex.ru/maps/org/yandeks/1124715036/',
            ]),
        ]);

        $parser = new YandexReviewsParser(
            new YandexUrlParser(),
            new YandexHttpClient($http, new UserAgentRotator(), new RequestThrottle(0, 0, sleeper: fn () => null), new ProxyPool()),
            new YandexStateExtractor(),
        );

        $reference = $parser->parseUrl('https://yandex.ru/maps/-/CDbmZ8x');

        $this->assertSame('1124715036', $reference->externalId);
        $this->assertSame('https://yandex.ru/maps/-/CDbmZ8x', $reference->originalUrl);
    }
}
