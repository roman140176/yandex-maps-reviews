<?php

declare(strict_types=1);

namespace App\Enums;

enum ParseStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    /** Everything the source offers was collected. */
    case Success = 'success';
    /** Data was collected, but less than expected — see the run's warnings. */
    case Partial = 'partial';
    /** Anti-bot protection engaged; retrying later is meaningful. */
    case Blocked = 'blocked';
    /** Broke for a reason that will not fix itself, e.g. the layout changed. */
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return match ($this) {
            self::Queued, self::Running => false,
            default => true,
        };
    }

    public function isUsable(): bool
    {
        return $this === self::Success || $this === self::Partial;
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'В очереди',
            self::Running => 'Обновляется',
            self::Success => 'Готово',
            self::Partial => 'Готово частично',
            self::Blocked => 'Источник заблокировал запросы',
            self::Failed => 'Ошибка',
        };
    }
}
