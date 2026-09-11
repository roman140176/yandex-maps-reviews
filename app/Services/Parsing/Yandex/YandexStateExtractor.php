<?php

declare(strict_types=1);

namespace App\Services\Parsing\Yandex;

use App\Services\Parsing\Data\BusinessProfileData;
use App\Services\Parsing\Data\ExtractedPage;
use App\Services\Parsing\Data\ReviewData;
use App\Services\Parsing\Data\ReviewPageData;
use App\Services\Parsing\Exceptions\LayoutChangedException;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

/**
 * Turns one organisation page into typed data.
 *
 * Yandex.Maps is server-rendered: the same JSON its React app hydrates from is
 * embedded in the HTML as `<script type="application/json" class="state-view">`.
 * Reading that is far more stable than scraping class names, which change with
 * every deploy, and it needs no browser at all.
 *
 * The whole class is written around one rule: **never return quiet emptiness.**
 * Every step asserts the anchor it depends on and throws a
 * {@see LayoutChangedException} with a precise code when the anchor is gone.
 * A silent change on Yandex's side must look like a failed run, not like an
 * organisation that suddenly has no reviews.
 */
final class YandexStateExtractor
{
    /** Reviews are dropped rather than trusted when they lack these. */
    private const REQUIRED_REVIEW_FIELDS = ['reviewId', 'updatedTime'];

    /** Above this share of unusable reviews the page is considered broken. */
    private const MAX_BROKEN_REVIEWS_SHARE = 0.2;

    private const AVATAR_SIZE = 'islands-retina-50';

    public function extract(string $html, int $requestedPage): ExtractedPage
    {
        $state = $this->decodeState($html);
        $item = $this->businessItem($state);
        $warnings = [];

        $profile = $this->profile($item);
        $warnings = array_merge($warnings, $this->crossCheckWithMicrodata($html, $profile));

        $reviewResults = $item['reviewResults'] ?? null;

        if (! is_array($reviewResults)) {
            // Past the last available page Yandex keeps rendering the card but
            // drops reviewResults entirely — that is a normal end of pagination.
            // On the very first page it means something broke, unless the card
            // genuinely has no reviews.
            if ($requestedPage <= 1 && $profile->reviewsCount > 0) {
                throw LayoutChangedException::make(
                    LayoutChangedException::REVIEW_RESULTS_MISSING,
                    'Карточка сообщает о наличии отзывов, но блок отзывов на странице отсутствует.',
                    ['reviews_count' => $profile->reviewsCount, 'page' => $requestedPage],
                );
            }

            return new ExtractedPage($profile, null, $warnings);
        }

        [$reviews, $reviewWarnings] = $this->reviews($reviewResults, $requestedPage);
        $warnings = array_merge($warnings, $reviewWarnings);

        return new ExtractedPage(
            profile: $profile,
            reviews: $this->reviewPage($reviewResults, $reviews, $requestedPage, $profile),
            warnings: $warnings,
        );
    }

    /** @return array<string, mixed> */
    private function decodeState(string $html): array
    {
        $matched = preg_match(
            '~<script[^>]*\bclass="state-view"[^>]*>(.*?)</script>~s',
            $html,
            $m,
        );

        if ($matched !== 1) {
            throw LayoutChangedException::make(
                LayoutChangedException::STATE_SCRIPT_MISSING,
                'На странице нет встроенного состояния (script.state-view) — разметка Яндекса изменилась.',
                ['html_length' => strlen($html)],
            );
        }

        // The payload is raw JSON: Yandex escapes only "<" (as <) to keep
        // the script tag from closing early. It is NOT HTML-encoded, so running
        // html_entity_decode over it would corrupt ampersands in review texts.
        try {
            $state = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw LayoutChangedException::make(
                LayoutChangedException::STATE_JSON_INVALID,
                'Встроенное состояние страницы не разбирается как JSON.',
                ['json_error' => $e->getMessage()],
                );
        }

        if (! is_array($state)) {
            throw LayoutChangedException::make(
                LayoutChangedException::STATE_JSON_INVALID,
                'Встроенное состояние страницы не является объектом.',
            );
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function businessItem(array $state): array
    {
        $item = data_get($state, 'stack.0.results.items.0');

        if (! is_array($item)) {
            throw LayoutChangedException::make(
                LayoutChangedException::RESULTS_PATH_MISSING,
                'В состоянии страницы нет карточки по пути stack[0].results.items[0].',
                ['top_level_keys' => array_slice(array_keys($state), 0, 15)],
            );
        }

        if (($item['type'] ?? null) !== 'business') {
            throw LayoutChangedException::make(
                LayoutChangedException::BUSINESS_NOT_FOUND,
                'По ссылке найдена не карточка организации.',
                ['type' => $item['type'] ?? null],
            );
        }

        return $item;
    }

    /** @param array<string, mixed> $item */
    private function profile(array $item): BusinessProfileData
    {
        $rating = $item['ratingData'] ?? null;

        if (! is_array($rating)) {
            throw LayoutChangedException::make(
                LayoutChangedException::RATING_DATA_MISSING,
                'В карточке нет блока с рейтингом (ratingData).',
                ['item_keys' => array_slice(array_keys($item), 0, 20)],
            );
        }

        $value = (float) ($rating['ratingValue'] ?? 0);
        $ratingsCount = (int) ($rating['ratingCount'] ?? 0);
        $reviewsCount = (int) ($rating['reviewCount'] ?? 0);

        if ($value < 0 || $value > 5 || $ratingsCount < 0 || $reviewsCount < 0) {
            throw LayoutChangedException::make(
                LayoutChangedException::RATING_DATA_INVALID,
                'Значения рейтинга вне допустимого диапазона — формат данных изменился.',
                ['rating' => $rating],
            );
        }

        return new BusinessProfileData(
            externalId: (string) ($item['id'] ?? ''),
            name: (string) ($item['title'] ?? $item['shortTitle'] ?? 'Без названия'),
            address: isset($item['address']) ? (string) $item['address'] : null,
            category: is_array($item['categories'][0] ?? null)
                ? (string) $item['categories'][0]['name']
                : null,
            // Yandex sends a float32 artefact (4.900000095367432); one decimal
            // is the precision the source actually displays.
            ratingValue: round($value, 1),
            ratingsCount: $ratingsCount,
            reviewsCount: $reviewsCount,
        );
    }

    /**
     * @param  array<string, mixed>  $reviewResults
     * @return array{list<ReviewData>, list<string>}
     */
    private function reviews(array $reviewResults, int $requestedPage): array
    {
        $raw = $reviewResults['reviews'] ?? null;

        if (! is_array($raw)) {
            throw LayoutChangedException::make(
                LayoutChangedException::REVIEW_SHAPE_MISMATCH,
                'Блок отзывов есть, но список отзывов в нём отсутствует.',
                ['page' => $requestedPage, 'keys' => array_keys($reviewResults)],
            );
        }

        $reviews = [];
        $broken = 0;

        foreach ($raw as $entry) {
            $review = is_array($entry) ? $this->review($entry) : null;

            if ($review === null) {
                $broken++;

                continue;
            }

            $reviews[] = $review;
        }

        $total = count($raw);
        $warnings = [];

        // One malformed entry is noise; a page that is mostly malformed means
        // the review format changed under us and the data cannot be trusted.
        if ($total > 0 && $broken / $total > self::MAX_BROKEN_REVIEWS_SHARE) {
            throw LayoutChangedException::make(
                LayoutChangedException::REVIEW_SHAPE_MISMATCH,
                "На странице {$requestedPage} не удалось разобрать {$broken} из {$total} отзывов — формат отзыва изменился.",
                ['page' => $requestedPage, 'broken' => $broken, 'total' => $total],
            );
        }

        if ($broken > 0) {
            $warnings[] = "Страница {$requestedPage}: пропущено отзывов с неполными данными — {$broken} из {$total}.";
        }

        return [$reviews, $warnings];
    }

    /** @param array<string, mixed> $entry */
    private function review(array $entry): ?ReviewData
    {
        foreach (self::REQUIRED_REVIEW_FIELDS as $field) {
            if (! isset($entry[$field]) || $entry[$field] === '') {
                return null;
            }
        }

        $publishedAt = $this->parseDate($entry['updatedTime']);

        if ($publishedAt === null) {
            return null;
        }

        $rating = (int) $entry['rating'];

        if ($rating < 0 || $rating > 5) {
            return null;
        }

        return new ReviewData(
            externalId: (string) $entry['reviewId'],
            authorName: trim((string) data_get($entry, 'author.name', '')) ?: 'Аноним',
            authorAvatarUrl: $this->avatarUrl(data_get($entry, 'author.avatarUrl')),
            authorLevel: data_get($entry, 'author.professionLevel') !== null
                ? (string) data_get($entry, 'author.professionLevel')
                : null,
            // Yandex sends 0 for a review whose author wrote text but left no
            // stars. Those are real reviews and must not be dropped — they just
            // have no rating of their own.
            rating: $rating > 0 ? $rating : null,
            // The mirror case: a rating with no comment arrives with empty text
            // and is still part of the card's own review list.
            text: (string) ($entry['text'] ?? ''),
            publishedAt: $publishedAt,
            businessCommentText: data_get($entry, 'businessComment.text') !== null
                ? (string) data_get($entry, 'businessComment.text')
                : null,
            businessCommentAt: $this->parseDate(data_get($entry, 'businessComment.updatedTime')),
            photosCount: is_array($entry['photos'] ?? null) ? count($entry['photos']) : 0,
            likesCount: (int) data_get($entry, 'reactions.likes', 0),
        );
    }

    /**
     * @param  array<string, mixed>  $reviewResults
     * @param  list<ReviewData>      $reviews
     */
    private function reviewPage(
        array $reviewResults,
        array $reviews,
        int $requestedPage,
        BusinessProfileData $profile,
    ): ReviewPageData {
        $params = $reviewResults['params'] ?? null;

        if (! is_array($params) || ! isset($params['limit'])) {
            throw LayoutChangedException::make(
                LayoutChangedException::PAGINATION_MISSING,
                'В блоке отзывов нет параметров постраничной навигации.',
                ['page' => $requestedPage, 'keys' => array_keys($reviewResults)],
            );
        }

        $limit = max(1, (int) $params['limit']);
        $totalCount = (int) ($params['count'] ?? $profile->reviewsCount);

        return new ReviewPageData(
            reviews: $reviews,
            page: (int) ($params['page'] ?? $requestedPage),
            offset: (int) ($params['offset'] ?? ($requestedPage - 1) * $limit),
            limit: $limit,
            totalCount: $totalCount,
            totalPages: (int) ($params['totalPages'] ?? (int) ceil($totalCount / $limit)),
        );
    }

    /**
     * Compares the state JSON against the schema.org microdata rendered into
     * the same page. The two are produced independently, so agreement is real
     * evidence the reading is correct and disagreement is an early warning that
     * one of them moved.
     *
     * @return list<string>
     */
    private function crossCheckWithMicrodata(string $html, BusinessProfileData $profile): array
    {
        $microdata = $this->microdataAggregates($html);

        if ($microdata === []) {
            return ['На странице нет контрольной микроразметки aggregateRating — сверить агрегаты не с чем.'];
        }

        $warnings = [];
        $expected = [
            'reviewCount' => $profile->reviewsCount,
            'ratingCount' => $profile->ratingsCount,
        ];

        foreach ($expected as $key => $value) {
            if (isset($microdata[$key]) && (int) $microdata[$key] !== $value) {
                $warnings[] = sprintf(
                    'Расхождение с microdata: %s в состоянии — %d, в разметке — %d.',
                    $key,
                    $value,
                    (int) $microdata[$key],
                );
            }
        }

        if (isset($microdata['ratingValue'])
            && abs((float) $microdata['ratingValue'] - $profile->ratingValue) > 0.05) {
            $warnings[] = sprintf(
                'Расхождение с microdata: ratingValue в состоянии — %.1f, в разметке — %.1f.',
                $profile->ratingValue,
                (float) $microdata['ratingValue'],
            );
        }

        return $warnings;
    }

    /** @return array<string, string> */
    private function microdataAggregates(string $html): array
    {
        if (preg_match('~itemProp="aggregateRating".*?</span>~is', $html, $block) !== 1) {
            return [];
        }

        preg_match_all(
            '~<meta\s+itemProp="(reviewCount|ratingCount|ratingValue)"\s+content="([^"]*)"~i',
            $block[0],
            $matches,
            PREG_SET_ORDER,
        );

        $result = [];

        foreach ($matches as $match) {
            $result[$match[1]] = $match[2];
        }

        return $result;
    }

    private function avatarUrl(mixed $template): ?string
    {
        if (! is_string($template) || $template === '') {
            return null;
        }

        return str_replace('{size}', self::AVATAR_SIZE, $template);
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
