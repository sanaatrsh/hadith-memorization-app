<?php

namespace App\Contracts\Ai;

use App\Data\Ai\ExamAnswerGradeData;

interface ExamAnswerGrader
{
    /**
     * Ask the AI provider to judge a written exam answer against the stored
     * reference answer.
     *
     * Implementations MUST validate the provider response and return a typed
     * result. On any failure they should return a result whose `available`
     * flag is false rather than throwing, so the caller can fall back to a
     * deterministic comparison.
     *
     * @param  array<string,mixed>  $context  question_text, correct_answer, answer_text, question_kind ('factual'|'recall').
     */
    public function grade(array $context): ExamAnswerGradeData;

    /**
     * Judge a whole batch of answers.
     *
     * Completing an exam grades every answer at once, and doing that one call
     * after another makes the request as slow as their sum — slow enough for a
     * gateway to time the learner out before any score is returned. An
     * implementation MUST issue the calls concurrently, and MUST keep the same
     * keys so the caller can match each result to its answer.
     *
     * The same failure rule applies per entry: an entry that could not be
     * graded comes back with `available` false rather than throwing, so the
     * caller falls back to the deterministic comparison for that one alone.
     *
     * @param  array<array-key, array<string,mixed>>  $contexts
     * @return array<array-key, ExamAnswerGradeData>
     */
    public function gradeMany(array $contexts): array;
}
