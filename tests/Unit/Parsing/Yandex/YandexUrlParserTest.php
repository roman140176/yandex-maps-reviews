<?php

declare(strict_types=1);

namespace Tests\Unit\Parsing\Yandex;

use App\Services\Parsing\Exceptions\InvalidSourceUrlException;
use App\Services\Parsing\Yandex\YandexUrlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class YandexUrlParserTest extends TestCase
{
    private YandexUrlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new YandexUrlParser();
    }

    /** @return array<string, array{string, string}> */
    public static function acceptedUrls(): array
    {
        return [
            'org with seoname and reviews tab' => [
                'https://yandex.ru/maps/org/yandeks/1124715036/reviews/', '1124715036',
            ],
            'org with seoname' => [
                'https://yandex.ru/maps/org/yandeks/1124715036/', '1124715036',
            ],
            'org without seoname' => [
                'https://yandex.ru/maps/org/1124715036/', '1124715036',
            ],
            'org without trailing slash' => [
                'https://yandex.ru/maps/org/yandeks/1124715036', '1124715036',
            ],
            'com domain' => [
                'https://yandex.com/maps/org/yandeks/1124715036/reviews/', '1124715036',
            ],
            'regional domain' => [
                'https://yandex.kz/maps/org/yandeks/1124715036/', '1124715036',
            ],
            'ya.ru short domain' => [
                'https://ya.ru/maps/org/1124715036/', '1124715036',
            ],
            'www prefix' => [
                'https://www.yandex.ru/maps/org/1124715036/', '1124715036',
            ],
            'no scheme' => [
                'yandex.ru/maps/org/yandeks/1124715036/', '1124715036',
            ],
            'http scheme' => [
                'http://yandex.ru/maps/org/1124715036/', '1124715036',
            ],
            'with query and hash' => [
                'https://yandex.ru/maps/org/yandeks/1124715036/reviews/?ll=37.5%2C55.7&z=17#reviews', '1124715036',
            ],
            'region prefixed path' => [
                'https://yandex.ru/maps/213/moscow/org/yandeks/1124715036/', '1124715036',
            ],
            'profile page' => [
                'https://yandex.ru/profile/1124715036', '1124715036',
            ],
            'maps profile page' => [
                'https://yandex.ru/maps/profile/1124715036/', '1124715036',
            ],
            'poi uri parameter' => [
                'https://yandex.ru/maps/213/moscow/?mode=poi&poi%5Buri%5D=ymapsbm1%3A%2F%2Forg%3Foid%3D1124715036', '1124715036',
            ],
            'oid query parameter' => [
                'https://yandex.ru/maps/?oid=1124715036', '1124715036',
            ],
            'surrounding whitespace' => [
                "  https://yandex.ru/maps/org/1124715036/\n", '1124715036',
            ],
        ];
    }

    #[Test]
    #[DataProvider('acceptedUrls')]
    public function it_extracts_the_organisation_id(string $url, string $expectedId): void
    {
        $reference = $this->parser->parse($url);

        $this->assertSame('yandex', $reference->source);
        $this->assertSame($expectedId, $reference->externalId);
        $this->assertSame(trim($url), $reference->originalUrl);
    }

    #[Test]
    public function it_always_builds_the_reviews_url_because_other_tabs_only_serve_a_preview(): void
    {
        $reference = $this->parser->parse('https://yandex.ru/maps/org/yandeks/1124715036/');

        $this->assertSame(
            'https://yandex.ru/maps/org/1124715036/reviews/',
            $reference->canonicalUrl,
        );
    }

    /** @return array<string, array{string, string}> */
    public static function rejectedUrls(): array
    {
        return [
            'empty string' => ['', 'url_empty'],
            'whitespace only' => ['   ', 'url_empty'],
            'not a url' => ['just some text', 'url_malformed'],
            'foreign host' => ['https://2gis.ru/moscow/firm/70000001006556069', 'url_foreign_host'],
            'google maps' => ['https://maps.google.com/?cid=123', 'url_foreign_host'],
            'yandex but not a card' => ['https://yandex.ru/maps/213/moscow/', 'url_not_an_organisation'],
            'yandex but not maps' => ['https://yandex.ru/search/?text=test', 'url_not_an_organisation'],
            'org id is not numeric' => ['https://yandex.ru/maps/org/yandeks/abc/', 'url_not_an_organisation'],
            'javascript scheme' => ['javascript:alert(1)', 'url_malformed'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedUrls')]
    public function it_rejects_links_that_are_not_organisation_cards(string $url, string $expectedCode): void
    {
        try {
            $this->parser->parse($url);
            $this->fail("Expected {$expectedCode} for [{$url}]");
        } catch (InvalidSourceUrlException $e) {
            $this->assertSame($expectedCode, $e->errorCode());
        }
    }

    #[Test]
    public function it_reports_short_links_separately_so_the_caller_can_resolve_them(): void
    {
        try {
            $this->parser->parse('https://yandex.ru/maps/-/CDbmZ8x');
            $this->fail('Expected a short link to be reported');
        } catch (InvalidSourceUrlException $e) {
            $this->assertSame('url_short_link', $e->errorCode());
        }
    }

    #[Test]
    public function it_detects_short_links_without_throwing(): void
    {
        $this->assertTrue($this->parser->isShortLink('https://yandex.ru/maps/-/CDbmZ8x'));
        $this->assertFalse($this->parser->isShortLink('https://yandex.ru/maps/org/1124715036/'));
    }

    #[Test]
    public function it_builds_page_urls_for_pagination(): void
    {
        $reference = $this->parser->parse('https://yandex.ru/maps/org/1124715036/');

        $this->assertSame(
            'https://yandex.ru/maps/org/1124715036/reviews/',
            $this->parser->pageUrl($reference, 1),
        );
        $this->assertSame(
            'https://yandex.ru/maps/org/1124715036/reviews/?page=7',
            $this->parser->pageUrl($reference, 7),
        );
    }
}
