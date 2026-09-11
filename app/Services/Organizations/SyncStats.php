<?php

declare(strict_types=1);

namespace App\Services\Organizations;

final readonly class SyncStats
{
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $unchanged = 0,
    ) {}

    public function seen(): int
    {
        return $this->created + $this->updated + $this->unchanged;
    }
}
