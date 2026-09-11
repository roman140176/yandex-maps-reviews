<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ParseStatus;
use App\Enums\ParseTrigger;
use App\Models\Organization;
use App\Models\ParseRun;
use App\Services\Organizations\OrganizationParseService;
use App\Services\Parsing\Exceptions\ParsingException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Parses one organisation in the background.
 *
 * Even a single card is 12 sequential HTTP requests to a source that answers in
 * about a second — too slow for a web request, and that is one card. The task's
 * "network of ~50 branches" is 600 requests: it only works as queued jobs that
 * can be throttled, retried and watched.
 */
final class ParseOrganizationJob implements ShouldQueue
{
    use Queueable;

    /** Three attempts: the first retry catches blips, the third gives up. */
    public int $tries = 3;

    /** Twelve pages plus throttling; well under this, but not unbounded. */
    public int $timeout = 600;

    public function __construct(
        public readonly int $organizationId,
        public readonly ParseTrigger $trigger = ParseTrigger::Manual,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        // Two parses of the same card at once would race each other into the
        // same rows and double the requests for nothing.
        return [(new WithoutOverlapping((string) $this->organizationId))->dontRelease()];
    }

    /** @return list<int> seconds between attempts, growing to let a block expire */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(OrganizationParseService $service): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return; // deleted while queued — nothing to do
        }

        $run = ParseRun::query()->create([
            'organization_id' => $organization->id,
            'status' => ParseStatus::Queued,
            'trigger' => $this->trigger,
            'attempt' => $this->attempts(),
        ]);

        try {
            $service->execute($run);
        } catch (ParsingException $e) {
            // A changed layout or a deleted card will fail identically on every
            // retry; only transient trouble is worth another attempt.
            if (! $e->isRetryable()) {
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        // The last run already carries the precise reason; this only makes sure
        // the card never stays stuck showing "updating" after the queue gives up.
        if ($organization->isParsing()) {
            $organization->forceFill(['parse_status' => ParseStatus::Failed])->save();
        }

        ParseRun::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', [ParseStatus::Queued, ParseStatus::Running])
            ->update([
                'status' => ParseStatus::Failed,
                'error_code' => 'queue_gave_up',
                'error_message' => mb_substr((string) $exception?->getMessage(), 0, 1000),
                'finished_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        Log::error('parsing.job_failed', [
            'organization_id' => $this->organizationId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
