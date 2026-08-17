<?php

declare(strict_types=1);

namespace App\Enums;

enum SyncStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Partial = 'partial';
}
