<?php

namespace App\Services;

use App\Enums\ExamQuestionType;
use App\Models\ExamQuestion;

/**
 * Evaluates exam answers deterministically. Written factual answers
 * (narrator/source) are exact-matched after Arabic normalization; recall
 * answers (complete/voice recitation) reuse the same word-sequence
 * comparison used by the memorization flow (Phase 4).
 */
class ExamAnswerEvaluator
{
    public function __construct(
        private readonly ArabicTextNormalizer $normalizer,
        private readonly HadithTextComparisonService $comparison,
    ) {}

    /**
     * @return array{score:int, is_correct:bool, report:array<string,mixed>}
     */
    public function evaluate(ExamQuestion $question, string $answerText): array
    {
        $correct = (string) ($question->correct_answer ?? '');
        $passing = (int) config('athar.scoring.passing');

        // Factual short answers: exact match after normalization.
        if ($question->type === ExamQuestionType::Written && $this->isFactual($correct)) {
            $isCorrect = $this->normalizer->normalize($answerText) === $this->normalizer->normalize($correct)
                && $correct !== '';

            return [
                'score' => $isCorrect ? 100 : 0,
                'is_correct' => $isCorrect,
                'report' => ['mode' => 'exact_match'],
            ];
        }

        // Recall answers (complete / voice recitation): word-sequence comparison.
        $report = $this->comparison->compare(
            $this->normalizer->normalize($correct),
            $this->normalizer->normalize($answerText),
        );

        return [
            'score' => (int) $report['score'],
            'is_correct' => $report['score'] >= $passing,
            'report' => $report,
        ];
    }

    /**
     * A short factual answer (narrator name / source) is a single line with a
     * small number of words.
     */
    private function isFactual(string $correct): bool
    {
        $wordCount = count($this->normalizer->tokenize($this->normalizer->normalize($correct)));

        return $wordCount > 0 && $wordCount <= 6 && mb_strlen($correct) <= 80;
    }
}
