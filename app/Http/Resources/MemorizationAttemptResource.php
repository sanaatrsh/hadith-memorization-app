<?php

namespace App\Http\Resources;

use App\Enums\EvaluationVerdict;
use App\Models\MemorizationAttempt;
use App\Models\UserHadithProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MemorizationAttempt */
class MemorizationAttemptResource extends JsonResource
{
    private const GENERIC_FEEDBACK_AR = 'تم تقييم التسميع بناءً على المقارنة النصية. حاول تحسين الكلمات الناقصة.';

    public function toArray(Request $request): array
    {
        $comparison = $this->comparison_report ?? [];
        $ai = $this->ai_report; // null when Gemini was unavailable
        $aiAvailable = is_array($ai);

        $progress = UserHadithProgress::where('user_id', $this->user_id)
            ->where('hadith_id', $this->hadith_id)
            ->first();

        return [
            'attempt_id' => $this->id,
            'hadith_id' => $this->hadith_id,
            'score' => $this->final_score,
            'verdict' => $this->verdict?->value,
            'is_correct' => $this->verdict === EvaluationVerdict::Correct,
            'missing_words' => $aiAvailable ? ($ai['missing_words'] ?? []) : ($comparison['missing_words'] ?? []),
            'extra_words' => $aiAvailable ? ($ai['extra_words'] ?? []) : ($comparison['extra_words'] ?? []),
            'incorrect_segments' => $aiAvailable
                ? ($ai['incorrect_segments'] ?? [])
                : ($comparison['substitutions'] ?? []),
            'feedback' => $aiAvailable ? ($ai['feedback_ar'] ?? self::GENERIC_FEEDBACK_AR) : self::GENERIC_FEEDBACK_AR,
            'ai_feedback_available' => $aiAvailable,
            'progress_status' => $progress?->status?->value,
            'next_review_at' => $progress?->next_review_at,
            'comparison' => $comparison,
        ];
    }
}
