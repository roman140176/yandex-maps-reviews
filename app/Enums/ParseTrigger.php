<?php

declare(strict_types=1);

namespace App\Enums;

enum ParseTrigger: string
{
    /** Right after the organisation was connected. */
    case Initial = 'initial';
    /** The user pressed "refresh". */
    case Manual = 'manual';
    /** Scheduled re-parse. */
    case Scheduled = 'scheduled';
}
