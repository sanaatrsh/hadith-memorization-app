<?php

namespace App\Enums;

enum AttemptType: string
{
    case Memorization = 'memorization';
    case Review = 'review';
    case ExamVoice = 'exam_voice';
}
