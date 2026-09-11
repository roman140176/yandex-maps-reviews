<?php

declare(strict_types=1);

namespace App\Services\Parsing\Exceptions;

/**
 * The page loaded fine but no longer looks the way the parser expects.
 *
 * This is the exception that keeps the parser honest: every extraction step
 * asserts its anchor and throws this instead of quietly returning null, so a
 * silent change on Yandex's side surfaces as a failed run with a precise code
 * rather than as an organisation that mysteriously has zero reviews.
 */
final class LayoutChangedException extends ParsingException
{
    public const STATE_SCRIPT_MISSING = 'state_script_missing';
    public const STATE_JSON_INVALID = 'state_json_invalid';
    public const RESULTS_PATH_MISSING = 'results_path_missing';
    public const BUSINESS_NOT_FOUND = 'business_not_found';
    public const RATING_DATA_MISSING = 'rating_data_missing';
    public const RATING_DATA_INVALID = 'rating_data_invalid';
    public const REVIEW_RESULTS_MISSING = 'review_results_missing';
    public const REVIEW_SHAPE_MISMATCH = 'review_shape_mismatch';
    public const PAGINATION_MISSING = 'pagination_missing';

    /** @param array<string, mixed> $context */
    public static function make(string $code, string $message, array $context = []): self
    {
        return new self($code, $message, $context);
    }

    public function isRetryable(): bool
    {
        return false;
    }
}
