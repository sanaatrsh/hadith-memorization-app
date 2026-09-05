<?php

namespace App\Services;

use App\Contracts\Ai\ExamAnswerGrader;
use App\Data\Ai\ExamAnswerGradeData;
use App\Enums\ExamQuestionType;
use App\Models\ExamAnswer;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates exam answers with Gemini as the judge.
 *
 * Answers are typed by the learner, never picked from a list, so a strict
 * string match against the stored answer rejects perfectly good answers over
 * ordinary Arabic spelling and grammatical-case variance («تميم بن أوس
 * الداري» is «تميم الداري»). Gemini is asked to compare the submitted answer
 * with the stored reference and judge whether it is correct, tolerant of that
 * variance for factual answers (narrator / takhrij) while still holding
 * recall answers (complete / recite the matn) to the full wording.
 *
 * If Gemini is unavailable the exam must still be gradable, so evaluation
 * falls back to a deterministic comparison: token-by-token for a factual
 * answer, word-sequence for a recall answer — the same authoritative
 * comparison the live memorization flow uses.
 */
class ExamAnswerEvaluator
{
    public function __construct(
        private readonly ArabicTextNormalizer $normalizer,
        private readonly HadithTextComparisonService $comparison,
        private readonly ExamAnswerGrader $grader,
    ) {}

    /**
     * Grades one exam item.
     *
     * The reference answer lives on the item, not on the question: the same
     * question is asked about several hadiths and each of them has its own
     * correct answer.
     *
     * @return array{score:int, is_correct:bool, report:array<string,mixed>}
     */
    public function evaluate(ExamAnswer $item, string $answerText): array
    {
        $question = $item->question;

        return $this->evaluateAnswer(
            (string) $question?->question_text,
            $question?->type ?? ExamQuestionType::Written,
            (string) ($item->correct_answer ?? ''),
            $answerText,
        );
    }

    /**
     * Grade a whole set of items at once.
     *
     * Completing an exam submits every answer together, so grading them one
     * call after another makes the request as slow as their sum. This asks the
     * grader for the whole batch, which issues the calls concurrently; an
     * entry Gemini could not judge still falls back on its own.
     *
     * @param  array<array-key, array{question_text:string, type:ExamQuestionType, correct_answer:string, answer_text:string}>  $items
     * @return array<array-key, array{score:int, is_correct:bool, report:array<string,mixed>}>
     */
    public function evaluateMany(array $items): array
    {
        $results = [];
        $toGrade = [];

        foreach ($items as $key => $item) {
            $correct = $item['correct_answer'];
            $answer = $item['answer_text'];
            $isFactual = $item['type'] === ExamQuestionType::Written && $this->isFactual($correct);

            // Nothing to send: no answer, or no reference to judge it against.
            if (trim($answer) === '' || trim($correct) === '') {
                $results[$key] = $this->fallback($correct, $answer, $isFactual);

                continue;
            }

            $toGrade[$key] = [
                'question_text' => $item['question_text'],
                'correct_answer' => $correct,
                'answer_text' => $answer,
                'question_kind' => $isFactual ? 'factual' : 'recall',
            ];
        }

        $grades = $this->grader->gradeMany($toGrade);

        foreach ($toGrade as $key => $context) {
            $grade = $grades[$key] ?? null;
            $isFactual = $context['question_kind'] === 'factual';

            $results[$key] = $grade !== null && $grade->available
                ? $this->fromGrade($grade)
                : $this->fallback($context['correct_answer'], $context['answer_text'], $isFactual, $grade);
        }

        // Back into the order the caller passed them in.
        return array_map(fn ($key) => $results[$key], array_combine(array_keys($items), array_keys($items)));
    }

    /**
     * @return array{score:int, is_correct:bool, report:array<string,mixed>}
     */
    public function evaluateAnswer(string $questionText, ExamQuestionType $type, string $correct, string $answerText): array
    {
        $isFactual = $type === ExamQuestionType::Written && $this->isFactual($correct);

        if (trim($answerText) === '' || trim($correct) === '') {
            return $this->fallback($correct, $answerText, $isFactual);
        }

        $grade = $this->grader->grade([
            'question_text' => $questionText,
            'correct_answer' => $correct,
            'answer_text' => $answerText,
            'question_kind' => $isFactual ? 'factual' : 'recall',
        ]);

        if ($grade->available) {
            return $this->fromGrade($grade);
        }

        return $this->fallback($correct, $answerText, $isFactual, $grade);
    }

    /**
     * @return array{score:int, is_correct:bool, report:array<string,mixed>}
     */
    private function fromGrade(ExamAnswerGradeData $grade): array
    {
        $score = $grade->score ?? 0;

        return [
            'score' => $score,
            'is_correct' => $grade->isCorrect ?? false,
            'report' => [
                'mode' => 'gemini',
                'feedback_ar' => $grade->feedbackAr,
                'gemini_model' => $grade->model,
                'gemini_interaction_id' => $grade->interactionId,
            ],
        ];
    }

    /**
     * The deterministic comparison, used only when Gemini could not grade the
     * answer (or there is nothing to grade), so an exam is never stuck.
     *
     * @return array{score:int, is_correct:bool, report:array<string,mixed>}
     */
    private function fallback(string $correct, string $answerText, bool $isFactual, ?ExamAnswerGradeData $grade = null): array
    {
        $result = $isFactual
            ? $this->matchFactual($correct, $answerText)
            : $this->matchRecall($correct, $answerText);

        $result['report']['ai_available'] = false;

        if ($grade !== null) {
            $result['report']['failure_code'] = $grade->failureCode;
            $result['report']['failure_message'] = $grade->failureMessage;

            // Without this, a misconfigured or unreachable Gemini looks exactly
            // like a working deterministic grade and goes unnoticed.
            Log::warning('exam.answer.graded_without_ai', [
                'failure_code' => $grade->failureCode,
                'failure_message' => $grade->failureMessage,
            ]);
        }

        return $result;
    }

    /**
     * @return array{score:int, is_correct:bool, report:array<string,mixed>}
     */
    private function matchRecall(string $correct, string $answerText): array
    {
        $passing = (int) config('athar.scoring.passing');

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
