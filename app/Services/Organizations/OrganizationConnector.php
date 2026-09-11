<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Enums\ParseStatus;
use App\Enums\ParseTrigger;
use App\Jobs\ParseOrganizationJob;
use App\Models\Organization;
use App\Models\User;
use App\Services\Parsing\ParserRegistry;

/**
 * Connects a pasted link to a user's account.
 *
 * Validation happens here, not in the controller: deciding whether a URL is a
 * usable organisation card is the parser's knowledge, and re-submitting a card
 * that is already connected has to update it rather than create a twin.
 */
final class OrganizationConnector
{
    public function __construct(private readonly ParserRegistry $registry) {}

    /** @throws \App\Services\Parsing\Exceptions\ParsingException */
    public function connect(User $user, string $url): Organization
    {
        $parser = $this->registry->resolve($url);
        $reference = $parser->parseUrl($url);

        $organization = Organization::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'source' => $reference->source,
                'external_id' => $reference->externalId,
            ],
            [
                'url' => $reference->canonicalUrl,
                'parse_status' => ParseStatus::Queued,
            ],
        );

        $trigger = $organization->wasRecentlyCreated ? ParseTrigger::Initial : ParseTrigger::Manual;

        ParseOrganizationJob::dispatch($organization->id, $trigger);

        return $organization;
    }

    /** Queues a refresh, unless one is already in flight. */
    public function refresh(Organization $organization, ParseTrigger $trigger = ParseTrigger::Manual): bool
    {
        if ($organization->isParsing()) {
            return false;
        }

        $organization->forceFill(['parse_status' => ParseStatus::Queued])->save();

        ParseOrganizationJob::dispatch($organization->id, $trigger);

        return true;
    }
}
