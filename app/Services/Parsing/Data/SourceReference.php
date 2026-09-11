<?php

declare(strict_types=1);

namespace App\Services\Parsing\Data;

/**
 * A validated link to an organisation card: which platform, which id, and the
 * canonical URL we will actually fetch (user-supplied links come in half a
 * dozen shapes and most of them do not serve reviews).
 */
final readonly class SourceReference
{
    public function __construct(
        public string $source,
        public string $externalId,
        public string $canonicalUrl,
        public string $originalUrl,
    ) {}
}
