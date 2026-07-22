<?php

namespace App\Enums;

enum AttemptStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
