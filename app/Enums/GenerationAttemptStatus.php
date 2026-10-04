<?php

namespace App\Enums;

enum GenerationAttemptStatus: string
{
    case Issued = 'issued';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
}
