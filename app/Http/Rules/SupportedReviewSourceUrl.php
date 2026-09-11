<?php

declare(strict_types=1);

namespace App\Http\Rules;

use App\Services\Parsing\Exceptions\InvalidSourceUrlException;
use App\Services\Parsing\ParserRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the pasted link by asking the parsers themselves.
 *
 * Keeping the rule thin and delegating matters: "is this a usable card link"
 * is the parser's knowledge, and duplicating a regex here would mean two places
 * to update when a platform changes its URL shapes.
 *
 * For a short link this does one redirect lookup, so the user learns right away
 * that the link leads nowhere useful instead of after a queued job fails. The
 * result is memoised for the request, so the following parse does not repeat it.
 */
final class SupportedReviewSourceUrl implements ValidationRule
{
    public function __construct(private readonly ParserRegistry $registry) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Ссылка должна быть строкой.');

            return;
        }

        try {
            $parser = $this->registry->resolve($value);
            $parser->parseUrl($value);
        } catch (InvalidSourceUrlException $e) {
            $fail($e->getMessage());
        }
    }
}
