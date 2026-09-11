<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Enums\ParseStatus;
use App\Models\Organization;
use App\Models\OrganizationSnapshot;
use App\Models\ParseRun;
use App\Services\Parsing\Data\BusinessProfileData;
use App\Services\Parsing\Data\SourceReference;
use App\Services\Parsing\Exceptions\ParsingException;
use App\Services\Parsing\Exceptions\SourceBlockedException;
use App\Services\Parsing\ParserRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one parse of one organisation and records everything about it.
 *
 * The run row is the single place that answers "what is happening / what went
 * wrong": it carries live progress while the job works, and afterwards the
 * outcome, the aggregates as of that moment, and any warnings. Nothing about a
 * failure is swallowed — if the run cannot finish, it says why, in a code the
 * UI can render and a developer can grep for.
 */
final class OrganizationParseService
{
    public function __construct(
        private readonly ParserRegistry $registry,
        private readonly ReviewSyncService $reviews,
    ) {}

    /**
     * @throws ParsingException rethrown after being recorded, so the queue can
     *                          decide whether a retry makes sense
     */
    public function execute(ParseRun $run): void
    {
        $organization = $run->organization;
        $startedAt = microtime(true);

        $this->markRunning($run, $organization);

        try {
            $parser = $this->registry->for($organization->source);

            $result = $parser->fetch(
                new SourceReference(
                    source: $organization->source,
                    externalId: $organization->external_id,
                    canonicalUrl: $organization->url,
                    originalUrl: $organization->url,
                ),
                function (int $done, int $expected, int $reviews) use ($run): void {
                    // Written straight to the database rather than accumulated:
                    // the point is for another process (the browser) to watch.
                    $run->forceFill([
                        'pages_done' => $done,
                        'pages_expected' => $expected,
                        'reviews_seen' => $reviews,
                    ])->save();
                },
            );

            $stats = $this->reviews->sync($organization, $result->reviews, $run);

            $this->applyProfile($organization, $result->profile);

            $run->forceFill([
                'status' => $result->warnings === [] ? ParseStatus::Success : ParseStatus::Partial,
                'pages_done' => $result->pagesFetched,
                'pages_expected' => $result->pagesExpected,
                'reviews_seen' => count($result->reviews),
                'reviews_created' => $stats->created,
                'reviews_updated' => $stats->updated,
                'rating_value' => $result->profile->ratingValue,
                'ratings_count' => $result->profile->ratingsCount,
                'reviews_count' => $result->profile->reviewsCount,
                'warnings' => $result->warnings ?: null,
                'error_code' => null,
                'error_message' => null,
                'finished_at' => Carbon::now(),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ])->save();

            $this->snapshot($organization, $run);

            $organization->forceFill([
                'parse_status' => $run->status,
                'last_parsed_at' => Carbon::now(),
            ])->save();

            Log::info('parsing.run_finished', [
                'organization_id' => $organization->id,
                'status' => $run->status->value,
                'reviews' => count($result->reviews),
                'created' => $stats->created,
                'updated' => $stats->updated,
                'warnings' => $result->warnings,
            ]);
        } catch (ParsingException $e) {
            $this->recordFailure($run, $organization, $e->errorCode(), $e->getMessage(), $startedAt, $e instanceof SourceBlockedException);

            Log::error('parsing.run_failed', [
                'organization_id' => $organization->id,
                'error_code' => $e->errorCode(),
                'context' => $e->context(),
            ]);

            throw $e;
        } catch (Throwable $e) {
            $this->recordFailure($run, $organization, 'unexpected_error', $e->getMessage(), $startedAt, false);

            Log::error('parsing.run_crashed', [
                'organization_id' => $organization->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function markRunning(ParseRun $run, Organization $organization): void
    {
        $run->forceFill([
            'status' => ParseStatus::Running,
            'started_at' => Carbon::now(),
        ])->save();

        $organization->forceFill(['parse_status' => ParseStatus::Running])->save();
    }

    private function applyProfile(Organization $organization, BusinessProfileData $profile): void
    {
        $organization->forceFill([
            'name' => $profile->name,
            'address' => $profile->address,
            'category' => $profile->category,
            'rating_value' => $profile->ratingValue,
            'ratings_count' => $profile->ratingsCount,
            'reviews_count' => $profile->reviewsCount,
            // What we hold, which can legitimately exceed what the source is
            // willing to show today: reviews collected earlier are kept.
            'reviews_stored' => $organization->reviews()->count(),
        ])->save();
    }

    private function snapshot(Organization $organization, ParseRun $run): void
    {
        OrganizationSnapshot::query()->create([
            'organization_id' => $organization->id,
            'parse_run_id' => $run->id,
            'rating_value' => $organization->rating_value,
            'ratings_count' => $organization->ratings_count,
            'reviews_count' => $organization->reviews_count,
            'reviews_stored' => $organization->reviews_stored,
            'captured_at' => Carbon::now(),
        ]);
    }

    private function recordFailure(
        ParseRun $run,
        Organization $organization,
        string $code,
        string $message,
        float $startedAt,
        bool $blocked,
    ): void {
        $status = $blocked ? ParseStatus::Blocked : ParseStatus::Failed;

        $run->forceFill([
            'status' => $status,
            'error_code' => $code,
            'error_message' => mb_substr($message, 0, 1000),
            'finished_at' => Carbon::now(),
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ])->save();

        $organization->forceFill(['parse_status' => $status])->save();
    }
}
