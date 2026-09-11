<?php

declare(strict_types=1);

namespace App\Services\Parsing\Yandex;

use App\Services\Parsing\Exceptions\OrganizationNotFoundException;
use App\Services\Parsing\Exceptions\SourceBlockedException;
use App\Services\Parsing\Exceptions\SourceUnavailableException;
use App\Services\Parsing\Support\ProxyPool;
use App\Services\Parsing\Support\RequestThrottle;
use App\Services\Parsing\Support\UserAgentRotator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * The only place that talks to Yandex over the wire.
 *
 * Everything that makes repeated scraping survivable lives here: throttling
 * between requests, exponential backoff with jitter on transient failures,
 * User-Agent rotation, an optional proxy pool, and — most importantly —
 * telling "we got blocked" apart from "the server hiccuped", because the two
 * need opposite responses.
 */
final class YandexHttpClient
{
    /** Markers of Yandex's anti-bot interstitial. */
    private const CAPTCHA_MARKERS = ['SmartCaptcha', 'captcha-container', 'showcaptcha', 'CheckboxCaptcha'];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly UserAgentRotator $userAgents,
        private readonly RequestThrottle $throttle,
        private readonly ProxyPool $proxies,
        private readonly int $timeout = 30,
        private readonly int $maxAttempts = 3,
    ) {}

    /**
     * Fetches a page, retrying transient failures.
     *
     * @throws SourceBlockedException      anti-bot protection engaged
     * @throws SourceUnavailableException  network trouble that outlived the retries
     * @throws OrganizationNotFoundException the card is gone
     */
    public function get(string $url): string
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $this->throttle->wait();

            $proxy = $this->proxies->next();

            try {
                $response = $this->send($url, $proxy);
            } catch (ConnectionException $e) {
                $lastException = SourceUnavailableException::make(
                    SourceUnavailableException::CONNECTION_FAILED,
                    'Не удалось соединиться с Яндекс.Картами.',
                    ['url' => $url, 'attempt' => $attempt],
                    $e,
                );

                $this->onFailedAttempt($proxy, $attempt, $url, 'connection');

                continue;
            }

            $this->assertNotBlocked($response, $url, $proxy);

            if ($response->status() === 404) {
                throw OrganizationNotFoundException::make(
                    'Карточка организации не найдена — возможно, она удалена.',
                    ['url' => $url],
                );
            }

            if ($response->serverError()) {
                $lastException = SourceUnavailableException::make(
                    SourceUnavailableException::SERVER_ERROR,
                    "Яндекс ответил ошибкой {$response->status()}.",
                    ['url' => $url, 'status' => $response->status(), 'attempt' => $attempt],
                );

                $this->onFailedAttempt($proxy, $attempt, $url, "http_{$response->status()}");

                continue;
            }

            $body = $response->body();

            if (trim($body) === '') {
                $lastException = SourceUnavailableException::make(
                    SourceUnavailableException::EMPTY_RESPONSE,
                    'Яндекс вернул пустой ответ.',
                    ['url' => $url, 'attempt' => $attempt],
                );

                $this->onFailedAttempt($proxy, $attempt, $url, 'empty_body');

                continue;
            }

            return $body;
        }

        throw $lastException ?? SourceUnavailableException::make(
            SourceUnavailableException::CONNECTION_FAILED,
            'Не удалось получить страницу.',
            ['url' => $url],
        );
    }

    /** Resolves a short `/maps/-/XXX` link to the URL it points at. */
    public function resolveRedirect(string $url): string
    {
        $this->throttle->wait();

        try {
            $response = $this->http
                ->withHeaders($this->headers())
                ->withOptions($this->options($this->proxies->next(), followRedirects: false))
                ->timeout($this->timeout)
                ->get($url);
        } catch (ConnectionException $e) {
            throw SourceUnavailableException::make(
                SourceUnavailableException::CONNECTION_FAILED,
                'Не удалось развернуть короткую ссылку.',
                ['url' => $url],
                $e,
            );
        }

        $location = $response->header('Location');

        if ($location === '') {
            throw SourceUnavailableException::make(
                SourceUnavailableException::EMPTY_RESPONSE,
                'Короткая ссылка не ведёт на карточку организации.',
                ['url' => $url, 'status' => $response->status()],
            );
        }

        return $location;
    }

    private function send(string $url, ?string $proxy): Response
    {
        return $this->http
            ->withHeaders($this->headers())
            ->withOptions($this->options($proxy))
            ->timeout($this->timeout)
            ->get($url);
    }

    /**
     * Blocking looks different depending on how Yandex decides to refuse: a 403,
     * a 429, or — most often — a 200 carrying the captcha page instead of the
     * card. All three must be recognised, or the parser would happily store an
     * "organisation" scraped from a captcha screen.
     */
    private function assertNotBlocked(Response $response, string $url, ?string $proxy): void
    {
        $status = $response->status();

        if ($status === 429) {
            $this->parkProxy($proxy);

            throw SourceBlockedException::make(
                SourceBlockedException::RATE_LIMITED,
                'Яндекс ограничил частоту запросов (429). Нужно снизить темп.',
                ['url' => $url, 'retry_after' => $response->header('Retry-After')],
            );
        }

        if ($status === 403) {
            $this->parkProxy($proxy);

            throw SourceBlockedException::make(
                SourceBlockedException::FORBIDDEN,
                'Яндекс отклонил запрос (403) — вероятно, сработала защита от ботов.',
                ['url' => $url],
            );
        }

        if ($this->looksLikeCaptcha($response)) {
            $this->parkProxy($proxy);

            throw SourceBlockedException::make(
                SourceBlockedException::CAPTCHA,
                'Вместо карточки отдана страница с капчей.',
                ['url' => $url, 'status' => $status],
            );
        }
    }

    private function looksLikeCaptcha(Response $response): bool
    {
        $effectiveUrl = (string) $response->effectiveUri();

        if (str_contains($effectiveUrl, '/showcaptcha')) {
            return true;
        }

        // Only the head of the document is inspected: the captcha interstitial
        // is tiny, while a real card is ~1 MB and mentions "captcha" deep inside
        // unrelated analytics config.
        $head = substr($response->body(), 0, 4096);

        foreach (self::CAPTCHA_MARKERS as $marker) {
            if (str_contains($head, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function onFailedAttempt(?string $proxy, int $attempt, string $url, string $reason): void
    {
        $this->parkProxy($proxy);

        Log::warning('yandex.request_failed', [
            'url' => $url,
            'attempt' => $attempt,
            'reason' => $reason,
        ]);

        if ($attempt < $this->maxAttempts) {
            $this->throttle->backoff($attempt);
        }
    }

    private function parkProxy(?string $proxy): void
    {
        if ($proxy !== null) {
            $this->proxies->park($proxy);
        }
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'User-Agent' => $this->userAgents->next(),
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'ru,en;q=0.9',
            'Cache-Control' => 'no-cache',
            'Upgrade-Insecure-Requests' => '1',
        ];
    }

    /** @return array<string, mixed> */
    private function options(?string $proxy, bool $followRedirects = true): array
    {
        $options = [
            'allow_redirects' => $followRedirects
                ? ['max' => 5, 'track_redirects' => true]
                : false,
            'decode_content' => true,
            'http_errors' => false,
        ];

        if ($proxy !== null) {
            $options['proxy'] = $proxy;
        }

        return $options;
    }
}
