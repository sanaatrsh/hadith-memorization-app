<?php

namespace App\Enums;

/**
 * Who pushed a hadith onto the memorization stack.
 */
enum StackSource: string
{
    /** The user asked for this hadith to be reviewed again. */
    case User = 'user';

    /** The deterministic comparison scored the recitation below passing. */
    case Evaluation = 'evaluation';

    /** Gemini recommended repeating or reviewing the hadith. */
    case Ai = 'ai';

    /**
     * Stack ordering weight — the user's own requests stay on top.
     */
    public function priority(): int
    {
        return match ($this) {
            self::User => 0,
            self::Ai => 1,
            self::Evaluation => 2,
        };
    }
}
