<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Parsing\ParserRegistry;
use App\Services\Parsing\Support\ProxyPool;
use App\Services\Parsing\Support\RequestThrottle;
use App\Services\Parsing\Support\UserAgentRotator;
use App\Services\Parsing\Yandex\YandexHttpClient;
use App\Services\Parsing\Yandex\YandexReviewsParser;
use App\Services\Parsing\Yandex\YandexStateExtractor;
use App\Services\Parsing\Yandex\YandexUrlParser;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

final class ParsingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(YandexUrlParser::class);
        $this->app->singleton(YandexStateExtractor::class);

        // Scoped, not singleton: throttle and proxy pool carry per-run state
        // (when the last request went out, which proxies are parked), which
        // should not leak between queue jobs in a long-lived worker.
        $this->app->scoped(YandexHttpClient::class, function ($app): YandexHttpClient {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return new YandexHttpClient(
                $app->make(HttpFactory::class),
                new UserAgentRotator(),
                new RequestThrottle(
                    (int) $config->get('parsing.yandex.throttle.min_ms'),
                    (int) $config->get('parsing.yandex.throttle.max_ms'),
                ),
                new ProxyPool(
                    $config->get('parsing.yandex.proxies', []),
                    (int) $config->get('parsing.yandex.proxy_park_seconds'),
                ),
                timeout: (int) $config->get('parsing.yandex.timeout'),
                maxAttempts: (int) $config->get('parsing.yandex.max_attempts'),
            );
        });

        $this->app->scoped(YandexReviewsParser::class, function ($app): YandexReviewsParser {
            return new YandexReviewsParser(
                $app->make(YandexUrlParser::class),
                $app->make(YandexHttpClient::class),
                $app->make(YandexStateExtractor::class),
                maxPages: (int) $app->make(Config::class)->get('parsing.yandex.max_pages'),
            );
        });

        $this->app->scoped(ParserRegistry::class, fn ($app): ParserRegistry => new ParserRegistry([
            $app->make(YandexReviewsParser::class),
        ]));
    }
}
