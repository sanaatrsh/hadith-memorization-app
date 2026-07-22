<?php

namespace App\Services;

use App\Enums\MemorizationStatus;
use App\Models\UserHadithProgress;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Simple, predictable spaced-repetition schedule (SRS v1).
 *
 * Thresholds and intervals come from config('athar') so they can be tuned
 * without changing this workflow.
 */
class SpacedRepetitionService
{
    /**
     * @return array{status:MemorizationStatus, srs_level:int, next_review_at:?CarbonInterface, memorized:bool}
     */
    public function transition(?UserHadithProgress $progress, int $finalScore, ?CarbonInterface $attemptedAt = null): array
    {
        $attemptedAt ??= Carbon::now();

        $intervals = config('athar.srs.intervals');
        $maxLevel = max(array_keys($intervals));
        $passing = (int) config('athar.scoring.passing');
        $acceptable = (int) config('athar.scoring.acceptable_min');

        $level = $progress?->srs_level ?? 0;

        $memorized = false;

        if ($finalScore >= $passing) {
            if ($level >= $maxLevel) {
                $newLevel = $maxLevel;
                $status = MemorizationStatus::Memorized;
                $memorized = true;
            } else {
                $newLevel = $level + 1;
                $status = MemorizationStatus::Reviewing;
            }
        } elseif ($finalScore >= $acceptable) {
            $newLevel = $level;
            $status = $level >= 1 ? MemorizationStatus::Reviewing : MemorizationStatus::Memorizing;
        } else {
            // Failed: move back a level (min 0) and return to active work.
            $newLevel = max($level - 1, 0);
            $status = $newLevel >= 1 ? MemorizationStatus::Reviewing : MemorizationStatus::Memorizing;
        }

        $nextReviewAt = $memorized
            ? null
            : $attemptedAt->copy()->addDays((int) ($intervals[$newLevel] ?? end($intervals)));

        return [
            'status' => $status,
            'srs_level' => $newLevel,
            'next_review_at' => $nextReviewAt,
            'memorized' => $memorized,
        ];
    }
}
