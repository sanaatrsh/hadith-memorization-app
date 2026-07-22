<?php

namespace App\Enums;

enum MemorizationStatus: string
{
    case NotStarted = 'not_started';
    case Memorizing = 'memorizing';
    case Reviewing = 'reviewing';
    case Memorized = 'memorized';
}
