<?php

declare(strict_types=1);

namespace App\Services\Parsing\Yandex;

use App\Services\Parsing\Contracts\ReviewSourceParser;
use App\Services\Parsing\Data\ParseResult;
use App\Services\Parsing\Data\ReviewData;
use App\Services\Parsing\Data\SourceReference;
use App\Services\Parsing\Exceptions\InvalidSourceUrlException;
use App\Services\Parsing\Exceptions\ParsingException;
use App\Services\Parsing\Exceptions\SourceBlockedException;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Walks an organisation's review pages and returns everything it could collect.
 *
 * Yandex paginates reviews with a plain `?page=N` on the card's `/reviews/` URL
 * and serves 50 per page, but stops after twelve of them — roughly the last 600
 * reviews — no matter how many the card actually has. That ceiling is the
 * source's, not ours, so hitting it is recorded as a warning rather than
 * treated as success or failure.
 */
final class YandexReviewsParser implements ReviewSourceParser
{
    /**
     * @param int $maxPages hard stop; Yandex itself stops serving reviews after
     *                      12 pages, and without a cap a changed response shape
     *                      could turn into an unbounded request loop.
     */
    public function __construct(
        private readonly YandexUrlParser $urls,
        private readonly YandexHttpClient $client,
        private readonly YandexStateExtractor $extractor,
        private readonly int $maxPages = 12,
    ) {}

    /**
     * Short links resolved during this request, so validating a link and then
     * acting on it does not pay for the same redirect twice.
     *
     * @var array<string, SourceReference>
     */
    private array $resolvedShortLinks = [];

    public function source(): string
    {
        return YandexUrlParser::SOURCE;
    }

    public function claims(string $url): bool
    {
        return $this->urls->claims($url);
    }

    public function parseUrl(string $url): SourceReference
    {
        $trimmed = trim($url);

        if (! $this->urls->isShortLink($trimmed)) {
            return $this->urls->parse($trimmed);
        }

        if (isset($this->resolvedShortLinks[$trimmed])) {
            return $this->resolvedShortLinks[$trimmed];
        }

        // Short links carry no organisation id — only Yandex knows where they
        // point, so one redirect has to be followed before validation.
        $resolved = $this->client->resolveRedirect($trimmed);
        $reference = $this->urls->parse($resolved);

        return $this->resolvedShortLinks[$trimmed] = new SourceReference(
            source: $reference->source,
            externalId: $reference->externalId,
            canonicalUrl: $reference->canonicalUrl,
            originalUrl: $trimmed,
        );
    }

    public function fetch(SourceReference $reference, ?Closure $onProgress = null): ParseResult
    {
        $first = $this->extractor->extract(
            $this->client->get($this->urls->pageUrl($reference, 1)),
            1,
        );

        $profile = $first->profile;
        $warnings = $first->warnings;

        /** @var array<string, ReviewData> $collected keyed by external id */
        $collected = [];
        $this->collect($collected, $first->reviews?->reviews ?? []);

        $perPage = $first->reviews?->limit ?? 50;
        $pagesExpected = $this->pagesExpected($profile->reviewsCount, $perPage, $first->reviews?->totalPages);
        $pagesFetched = 1;

        $this->report($onProgress, $pagesFetched, $pagesExpected, count($collected));

        for ($page = 2; $page <= $pagesExpected; $page++) {
            try {
                $extracted = $this->extractor->extract(
                    $this->client->get($this->urls->pageUrl($reference, $page)),
                    $page,
                );
            } catch (SourceBlockedException $e) {
                // Being blocked mid-walk is not a partial success: the run has
                // to fail so the queue backs off instead of hammering on.
                throw $e;
            } catch (ParsingException $e) {
                // Anything else — a broken page, a transient outage — costs us
                // one page, not the whole run.
                $warnings[] = "Страница {$page} не разобрана ({$e->errorCode()}), собраны только предыдущие.";
                Log::warning('yandex.page_failed', [
                    'organisation' => $reference->externalId,
                    'page' => $page,
                    'error_code' => $e->errorCode(),
                    'context' => $e->context(),
                ]);

                break;
            }

            if ($extracted->reviews === null || $extracted->reviews->isEmpty()) {
                break; // the source ran out of pages
            }

            $before = count($collected);
            $this->collect($collected, $extracted->reviews->reviews);
            $warnings = array_merge($warnings, $extracted->reviews === null ? [] : $extracted->warnings);
            $pagesFetched++;

            $this->report($onProgress, $pagesFetched, $pagesExpected, count($collected));

            if (count($collected) === $before) {
                // The page parsed fine but added nothing new — the source is
                // repeating itself, and walking further would only loop.
                $warnings[] = "Страница {$page} не принесла новых отзывов, обход остановлен.";
                break;
            }
        }

        $reviews = array_values($collected);

        return new ParseResult(
            profile: $profile,
            reviews: $reviews,
            pagesFetched: $pagesFetched,
            pagesExpected: $pagesExpected,
            warnings: array_values(array_unique(array_merge(
                $warnings,
                $this->completenessWarnings($profile->reviewsCount, count($reviews)),
            ))),
        );
    }

    /**
     * @param array<string, ReviewData> $collected
     * @param list<ReviewData>          $reviews
     */
    private function collect(array &$collected, array $reviews): void
    {
        foreach ($reviews as $review) {
            $collected[$review->externalId] ??= $review;
        }
    }

    private function pagesExpected(int $reviewsCount, int $perPage, ?int $sourceTotalPages): int
    {
        $needed = (int) ceil($reviewsCount / max(1, $perPage));

        if ($sourceTotalPages !== null && $sourceTotalPages > 0) {
            $needed = min($needed, $sourceTotalPages);
        }

        return max(1, min($needed, $this->maxPages));
    }

    /** @return list<string> */
    private function completenessWarnings(int $reviewsCount, int $collected): array
    {
        if ($reviewsCount <= $collected) {
            return [];
        }

        return [sprintf(
            'Собрано %d отзывов из %d: Яндекс отдаёт не более %d страниц (%d отзывов) на карточку.',
            $collected,
            $reviewsCount,
            $this->maxPages,
            $this->maxPages * 50,
        )];
    }

    private function report(?Closure $onProgress, int $done, int $expected, int $reviews): void
    {
        if ($onProgress !== null) {
            $onProgress($done, $expected, $reviews);
        }
    }
}
