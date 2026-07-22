<?php

namespace App\Data\Ai;

use App\Enums\EvaluationVerdict;

/**
 * Typed, validated result of an AI hadith evaluation.
 *
 * When `available` is false the AI output must be ignored and the caller
 * should fall back to the deterministic comparison report.
 */
class HadithEvaluationData
{
    /**
     * @param  array<int,string>  $missingWords
     * @param  array<int,string>  $extraWords
     * @param  array<int,array{expected:string,received:string,explanation_ar:string}>  $incorrectSegments
     */
    public function __construct(
        public readonly bool $available,
        public readonly ?bool $isTextuallyCorrect = null,
        public readonly ?int $score = null,
        public readonly ?EvaluationVerdict $verdict = null,
        public readonly array $missingWords = [],
        public readonly array $extraWords = [],
        public readonly array $incorrectSegments = [],
        public readonly ?string $feedbackAr = null,
        public readonly ?string $recommendedAction = null,
        public readonly ?string $model = null,
        public readonly ?string $interactionId = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
    ) {}

    public static function unavailable(string $failureCode, string $failureMessage): self
    {
        return new self(
            available: false,
            failureCode: $failureCode,
            failureMessage: $failureMessage,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function toReport(): ?array
    {
        if (! $this->available) {
            return null;
        }

        return [
            'is_textually_correct' => $this->isTextuallyCorrect,
            'score' => $this->score,
            'verdict' => $this->verdict?->value,
            'missing_words' => $this->missingWords,
            'extra_words' => $this->extraWords,
            'incorrect_segments' => $this->incorrectSegments,
            'feedback_ar' => $this->feedbackAr,
            'recommended_action' => $this->recommendedAction,
        ];
    }
}
