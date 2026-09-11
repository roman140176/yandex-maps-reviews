<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ParseTrigger;
use App\Jobs\ParseOrganizationJob;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Queues re-parsing of connected cards.
 *
 * This is the entry point for the "network of ~50 branches" case: it does not
 * parse anything itself, it only puts jobs on the queue, so the work is spread
 * across workers and paced by the parser's own throttling instead of arriving
 * as one spike.
 */
final class ParseOrganizationsCommand extends Command
{
    protected $signature = 'organizations:parse
        {organization?* : Ids to parse; omit with --all for every card}
        {--all : Queue every connected card}
        {--stale= : Only cards not parsed within this many hours}
        {--trigger=manual : initial|manual|scheduled}';

    protected $description = 'Поставить в очередь обновление карточек организаций';

    public function handle(): int
    {
        $trigger = ParseTrigger::tryFrom((string) $this->option('trigger'));

        if ($trigger === null) {
            $this->error('Неизвестный триггер. Допустимы: initial, manual, scheduled.');

            return self::INVALID;
        }

        $ids = $this->argument('organization');

        if ($ids === [] && ! $this->option('all')) {
            $this->error('Укажите идентификаторы карточек или флаг --all.');

            return self::INVALID;
        }

        $query = Organization::query()
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->when(
                $this->option('stale') !== null,
                fn ($q) => $q->where(fn ($inner) => $inner
                    ->whereNull('last_parsed_at')
                    ->orWhere('last_parsed_at', '<', Carbon::now()->subHours((int) $this->option('stale')))),
            );

        $queued = 0;

        $query->select('id', 'name', 'parse_status')->chunkById(100, function ($organizations) use ($trigger, &$queued): void {
            foreach ($organizations as $organization) {
                if ($organization->isParsing()) {
                    $this->line("  · пропуск #{$organization->id} ({$organization->name}) — уже обновляется");

                    continue;
                }

                ParseOrganizationJob::dispatch($organization->id, $trigger);
                $queued++;
            }
        });

        $this->info("Поставлено в очередь карточек: {$queued}.");

        return self::SUCCESS;
    }
}
