<?php

declare(strict_types=1);

namespace App\Services\Parsing\Yandex;

use App\Services\Parsing\Data\SourceReference;
use App\Services\Parsing\Exceptions\InvalidSourceUrlException;

/**
 * Turns whatever the user pasted into a canonical reviews URL.
 *
 * People copy links from the address bar, from the "share" button, from search
 * results and from the mobile app, so the same card arrives in half a dozen
 * shapes. Only the numeric organisation id is stable across all of them, and
 * only the `/reviews/` tab serves the full first page of reviews — the main tab
 * returns three of them as a preview. Hence: extract the id, rebuild the URL.
 *
 * Pure: no network. Short links need an HTTP redirect to resolve and are
 * reported with a dedicated code for the caller to handle.
 */
final class YandexUrlParser
{
    public const SOURCE = 'yandex';

    /** Domains that serve Yandex.Maps organisation cards. */
    private const ALLOWED_HOSTS = [
        'yandex.ru', 'yandex.com', 'yandex.by', 'yandex.kz', 'yandex.uz',
        'yandex.com.tr', 'yandex.com.ge', 'yandex.az', 'yandex.eu', 'ya.ru',
    ];

    private const ID_PATTERNS = [
        // /maps/org/<seoname>/<id>/ and /maps/org/<id>/, with an optional
        // /maps/<region-id>/<region-name>/ prefix and any trailing tab.
        '~/maps/(?:\d+/[^/]+/)?org/(?:[^/]+/)?(\d{4,})(?:/|$)~',
        // /profile/<id> and /maps/profile/<id>
        '~/(?:maps/)?profile/(\d{4,})(?:/|$)~',
        // ?poi[uri]=ymapsbm1://org?oid=<id> — decoded before matching
        '~ymapsbm1://org\?oid=(\d{4,})~',
        // plain ?oid=<id>
        '~[?&]oid=(\d{4,})~',
    ];

    public function parse(string $url): SourceReference
    {
        $original = trim($url);

        if ($original === '') {
            throw InvalidSourceUrlException::make('url_empty', 'Ссылка не указана.');
        }

        $normalised = $this->withScheme($original);
        $parts = parse_url($normalised);

        if ($parts === false || ! isset($parts['host'])) {
            throw InvalidSourceUrlException::make(
                'url_malformed',
                'Это не похоже на ссылку. Скопируйте адрес карточки из адресной строки Яндекс.Карт.',
                ['url' => $original],
            );
        }

        $host = $this->normaliseHost($parts['host']);

        if (preg_match('~^[a-z0-9-]+(\\.[a-z0-9-]+)+$~', $host) !== 1) {
            throw InvalidSourceUrlException::make(
                'url_malformed',
                'Это не похоже на ссылку. Скопируйте адрес карточки из адресной строки Яндекс.Карт.',
                ['url' => $original],
            );
        }

        if (! in_array($host, self::ALLOWED_HOSTS, true)) {
            throw InvalidSourceUrlException::make(
                'url_foreign_host',
                'Ссылка не с Яндекс.Карт. Ожидается адрес вида https://yandex.ru/maps/org/…',
                ['host' => $host],
            );
        }

        if ($this->isShortLink($normalised)) {
            throw InvalidSourceUrlException::make(
                'url_short_link',
                'Это короткая ссылка — её нужно предварительно развернуть.',
                ['url' => $original],
            );
        }

        $externalId = $this->extractId($normalised);

        if ($externalId === null) {
            throw InvalidSourceUrlException::make(
                'url_not_an_organisation',
                'В ссылке нет идентификатора организации. Откройте карточку компании и скопируйте адрес целиком.',
                ['url' => $original],
            );
        }

        return new SourceReference(
            source: self::SOURCE,
            externalId: $externalId,
            canonicalUrl: $this->canonicalUrl($externalId),
            originalUrl: $original,
        );
    }

    /** Host check only — no id extraction, no network. */
    public function claims(string $url): bool
    {
        $parts = parse_url($this->withScheme(trim($url)));

        if ($parts === false || ! isset($parts['host'])) {
            return false;
        }

        return in_array($this->normaliseHost($parts['host']), self::ALLOWED_HOSTS, true);
    }

    /** Short links (`/maps/-/CDbmZ8x`) only resolve through an HTTP redirect. */
    public function isShortLink(string $url): bool
    {
        return (bool) preg_match('~/maps/-/[A-Za-z0-9_-]+~', $this->withScheme(trim($url)));
    }

    /** Page 1 is the bare reviews URL; Yandex paginates with `?page=N`. */
    public function pageUrl(SourceReference $reference, int $page): string
    {
        return $page <= 1
            ? $reference->canonicalUrl
            : $reference->canonicalUrl.'?page='.$page;
    }

    private function canonicalUrl(string $externalId): string
    {
        return "https://yandex.ru/maps/org/{$externalId}/reviews/";
    }

    private function extractId(string $url): ?string
    {
        $candidates = [$url, urldecode($url)];

        foreach (self::ID_PATTERNS as $pattern) {
            foreach ($candidates as $candidate) {
                if (preg_match($pattern, $candidate, $m) === 1) {
                    return $m[1];
                }
            }
        }

        return null;
    }

    private function withScheme(string $url): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $url) === 1) {
            return $url;
        }

        // A bare `host/path` is fine to assume https; anything with a scheme we
        // do not speak (javascript:, data:) must not be silently upgraded.
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) === 1) {
            return $url;
        }

        return 'https://'.ltrim($url, '/');
    }

    private function normaliseHost(string $host): string
    {
        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
