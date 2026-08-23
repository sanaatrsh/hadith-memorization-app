<?php

namespace App\Data\Ai;

/**
 * Typed, validated result of a Gemini-graded exam answer.
 *
 * When `available` is false the caller must ignore this result and fall back
 * to the deterministic comparison, so an exam never stalls just because
 * Gemini is unreachable or misconfigured.
 */
class ExamAnswerGradeData
{
    public function __construct(
        public readonly bool $available,
        public readonly ?bool $isCorrect = null,
        public readonly ?int $score = null,
        public readonly ?string $feedbackAr = null,
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
}
