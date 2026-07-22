<?php

namespace App\Enums;

enum EvaluationVerdict: string
{
    case Correct = 'correct';
    case Acceptable = 'acceptable';
    case NeedsReview = 'needs_review';
    case Incorrect = 'incorrect';
}
