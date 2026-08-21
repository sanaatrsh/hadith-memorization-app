<?php

namespace App\Actions\Memorization;

use App\Contracts\Ai\HadithEvaluator;
use App\Data\Ai\HadithEvaluationData;
use App\Enums\AttemptStatus;
use App\Enums\AttemptType;
use App\Enums\EvaluationVerdict;
use App\Enums\StackSource;
use App\Models\Hadith;
use App\Models\MemorizationAttempt;
use App\Models\User;
use App\Models\UserHadithProgress;
use App\Services\ArabicTextNormalizer;
use App\Services\HadithTextComparisonService;
use App\Services\MemorizationStackService;
use App\Services\SpacedRepetitionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Core evaluation workflow, reused by memorization, review, and voice-exam
 * attempts. The deterministic comparison is the authoritative score; Gemini
 * output is saved for diagnostics only and never overrides it.
 */
class EvaluateMemorizationAttempt
{
    public function __construct(
        private readonly ArabicTextNormalizer $normalizer,
        private readonly HadithTextComparisonService $comparison,
        private readonly HadithEvaluator $evaluator,
        private readonly SpacedRepetitionService $srs,
        private readonly MemorizationStackService $stack,
    ) {}

    public function execute(
        User $user,
        Hadith $hadith,
        string $clientAttemptUuid,
        string $recognizedText,
        AttemptType $type = AttemptType::Memorization,
    ): MemorizationAttempt {
        // Idempotency: a completed attempt for this UUID is returned as-is
        // so mobile retries never create duplicates or re-score.
        $existing = MemorizationAttempt::where('user_id', $user->id)
            ->where('client_attempt_uuid', $clientAttemptUuid)
            ->first();

        if ($existing && $existing->status === AttemptStatus::Completed) {
            return $existing;
        }

        $startedAt = microtime(true);

        $normalizedReference = $this->normalizer->normalize($hadith->text);
        $normalizedRecognized = $this->normalizer->normalize($recognizedText);

        $comparison = $this->comparison->compare($normalizedReference, $normalizedRecognized);
        $deterministicScore = (int) $comparison['score'];
        $verdict = $this->verdictFromScore($deterministicScore);

        // Ask Gemini for structured review (diagnostics only).
        $ai = $this->evaluator->evaluate([
            'hadith_id' => $hadith->id,
            'title' => $hadith->title,
            'canonical_text' => $hadith->text,
            'narrator' => $hadith->narrator?->name,
            'source' => $hadith->source,
            'transcript' => $recognizedText,
            'comparison' => $comparison,
            'session_type' => $type->value,
            'srs_level' => optional($user->hadithProgress()->where('hadith_id', $hadith->id)->first())->srs_level ?? 0,
        ]);

        // MVP: the deterministic comparison is the final score authority.
        $finalScore = $deterministicScore;

        $processingMs = (int) round((microtime(true) - $startedAt) * 1000);

        return DB::transaction(function () use (
            $user, $hadith, $clientAttemptUuid, $recognizedText, $type,
            $normalizedRecognized, $normalizedReference, $comparison,
            $deterministicScore, $finalScore, $verdict, $ai, $processingMs, $existing
        ) {
            $attempt = $existing ?? new MemorizationAttempt;

            $attempt->fill([
                'client_attempt_uuid' => $clientAttemptUuid,
                'user_id' => $user->id,
                'hadith_id' => $hadith->id,
                'type' => $type,
                'status' => AttemptStatus::Completed,
                'recognized_text' => $recognizedText,
                'normalized_recognized_text' => $normalizedRecognized,
                'normalized_reference_text' => $normalizedReference,
                'deterministic_score' => $deterministicScore,
                'ai_score' => $ai->available ? $ai->score : null,
                'final_score' => $finalScore,
                'verdict' => $verdict,
                'comparison_report' => $comparison,
                'ai_report' => $ai->toReport(),
                'gemini_model' => $ai->model,
                'gemini_interaction_id' => $ai->interactionId,
                'processing_ms' => $processingMs,
                'failure_code' => $ai->available ? null : $ai->failureCode,
                'failure_message' => $ai->available ? null : $ai->failureMessage,
            ]);
            $attempt->save();

            $this->updateProgress($user, $hadith, $finalScore);
            $this->syncStack($user, $hadith, $finalScore, $ai);

            // Structured, secret-free observability.
            Log::info('memorization.attempt.evaluated', [
                'attempt_id' => $attempt->id,
                'user_id' => $user->id,
                'hadith_id' => $hadith->id,
                'gemini_model' => $ai->model,
                'ai_available' => $ai->available,
                'failure_code' => $ai->failureCode,
                'processing_ms' => $processingMs,
            ]);

            return $attempt;
        });
    }

    /**
     * Keep the memorization stack in step with the attempt: a recitation below
     * the passing score puts the hadith back on the stack, and so does a Gemini
     * recommendation to repeat or review it. A clean pass pops it off.
     */
    private function syncStack(User $user, Hadith $hadith, int $finalScore, HadithEvaluationData $ai): void
    {
        $passing = (int) config('athar.scoring.passing');

        $aiWantsReview = $ai->available && (
            in_array($ai->recommendedAction, ['repeat_now', 'review_later'], true)
            || in_array($ai->verdict, [EvaluationVerdict::NeedsReview, EvaluationVerdict::Incorrect], true)
        );

        if ($finalScore >= $passing && ! $aiWantsReview) {
            $this->stack->resolve($user, $hadith);

            return;
        }

        $this->stack->push(
            user: $user,
            hadith: $hadith,
            source: $aiWantsReview ? StackSource::Ai : StackSource::Evaluation,
            reason: $aiWantsReview && $ai->feedbackAr !== null
                ? Str::limit($ai->feedbackAr, 500)
                : 'درجة التسميع أقل من الحد المطلوب، الحديث بحاجة إلى مراجعة.',
            triggerScore: $finalScore,
        );
    }

    private function updateProgress(User $user, Hadith $hadith, int $finalScore): UserHadithProgress
    {
        $progress = UserHadithProgress::firstOrNew([
            'user_id' => $user->id,
            'hadith_id' => $hadith->id,
        ]);

        $now = Carbon::now();
        $srs = $this->srs->transition($progress->exists ? $progress : null, $finalScore, $now);

        $progress->status = $srs['status'];
        $progress->srs_level = $srs['srs_level'];
        $progress->next_review_at = $srs['next_review_at'];
        $progress->best_score = max($progress->best_score ?? 0, $finalScore);
        $progress->attempts_count = ($progress->attempts_count ?? 0) + 1;
        $progress->last_attempt_at = $now;

        if ($srs['memorized'] && $progress->memorized_at === null) {
            $progress->memorized_at = $now;
        }

        $progress->save();

        return $progress;
    }

    private function verdictFromScore(int $score): EvaluationVerdict
    {
        $passing = (int) config('athar.scoring.passing');
        $acceptable = (int) config('athar.scoring.acceptable_min');

        return match (true) {
            $score >= $passing => EvaluationVerdict::Correct,
            $score >= $acceptable => EvaluationVerdict::Acceptable,
            $score >= 50 => EvaluationVerdict::NeedsReview,
            default => EvaluationVerdict::Incorrect,
        };
    }
}
