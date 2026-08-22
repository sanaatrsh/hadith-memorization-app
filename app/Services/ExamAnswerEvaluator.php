<?php

namespace App\Services;

use App\Enums\ExamQuestionType;
use App\Models\ExamQuestion;

/**
 * Evaluates exam answers deterministically.
 *
 * Answers are typed by the learner, never picked from a list, so short factual
 * answers (narrator / takhrij) are compared token by token instead of character
 * by character: an answer that carries the whole stored answer — or is itself
 * wholly part of it — counts as correct. «تميم بن أوس الداري» therefore answers
 * «تميم الداري», and «البخاري ومسلم» answers «رواه البخاري ومسلم».
 *
 * Recall answers (complete / voice recitation) keep the strict word-sequence
 * comparison used by the memorization flow — reciting part of a matn is not the
 * same as reciting it.
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

        // Factual short answers: token comparison, tolerant of a fuller or a
        // shorter wording of the same name or takhrij.
        if ($question->type === ExamQuestionType::Written && $this->isFactual($correct)) {
            return $this->matchFactual($correct, $answerText);
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
     * @return array{score:int, is_correct:bool, report:array<string,mixed>}
     */
    private function matchFactual(string $correct, string $answerText): array
    {
        $reference = $this->significantTokens($correct);
        $answer = $this->significantTokens($answerText);

        if ($reference === [] || $answer === []) {
            return [
                'score' => 0,
                'is_correct' => false,
                'report' => [
                    'mode' => 'token_match',
                    'matched_words' => 0,
                    'total_reference_words' => count($reference),
                    'coverage' => 0.0,
                    'missing_words' => $reference,
                    'extra_words' => $answer,
                ],
            ];
        }

        $matchedReference = array_values(array_filter(
            $reference,
            fn (string $token) => $this->matchesAny($token, $answer),
        ));

        $matchedAnswer = array_values(array_filter(
            $answer,
            fn (string $token) => $this->matchesAny($token, $reference),
        ));

        $coverage = count($matchedReference) / count($reference);
        $minCoverage = (float) config('athar.answers.partial_min_coverage', 0.5);

        // Correct when the stored answer is fully covered — extra words such as
        // a fuller lineage are welcome — or when everything the learner wrote
        // belongs to it and enough of it is there.
        $everythingWrittenIsCorrect = count($matchedAnswer) === count($answer);
        $isCorrect = $coverage >= 1.0
            || ($everythingWrittenIsCorrect && $coverage >= $minCoverage);

        return [
            'score' => $isCorrect ? 100 : (int) round($coverage * 100),
            'is_correct' => $isCorrect,
            'report' => [
                'mode' => 'token_match',
                'matched_words' => count($matchedReference),
                'total_reference_words' => count($reference),
                'coverage' => round($coverage, 2),
                'missing_words' => array_values(array_diff($reference, $matchedReference)),
                'extra_words' => array_values(array_diff($answer, $matchedAnswer)),
            ],
        ];
    }

    /**
     * The tokens that actually identify a name or a takhrij: normalized, with
     * the connective words («بن»، «رضي الله عنه»، «رواه») dropped.
     *
     * @return array<int,string>
     */
    private function significantTokens(string $text): array
    {
        $ignored = $this->ignoredWords();

        $tokens = $this->normalizer->tokenize($this->normalizer->normalize($text));

        return array_values(array_filter(
            $tokens,
            fn (string $token) => ! in_array($token, $ignored, true),
        ));
    }

    /**
     * A token matches when it is identical to one of the others, or contained
     * in it while long enough — «مسلم» matches «ومسلم» and «داري» matches
     * «الداري», without «عمر» matching «عمرو».
     *
     * @param  array<int,string>  $others
     */
    private function matchesAny(string $token, array $others): bool
    {
        $minLength = (int) config('athar.answers.containment_min_length', 4);

        foreach ($others as $other) {
            if ($token === $other) {
                return true;
            }

            $shorter = mb_strlen($token) <= mb_strlen($other) ? $token : $other;
            $longer = $shorter === $token ? $other : $token;

            if (mb_strlen($shorter) >= $minLength && str_contains($longer, $shorter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private function ignoredWords(): array
    {
        return array_map(
            fn (string $word) => $this->normalizer->normalize($word),
            (array) config('athar.answers.ignored_words', []),
        );
    }

    /**
     * A short factual answer (narrator name / takhrij) is a single line with a
     * small number of words.
     */
    private function isFactual(string $correct): bool
    {
        $wordCount = count($this->normalizer->tokenize($this->normalizer->normalize($correct)));
        $maxWords = (int) config('athar.answers.factual_max_words', 6);
        $maxChars = (int) config('athar.answers.factual_max_chars', 80);

        return $wordCount > 0 && $wordCount <= $maxWords && mb_strlen($correct) <= $maxChars;
    }
}
