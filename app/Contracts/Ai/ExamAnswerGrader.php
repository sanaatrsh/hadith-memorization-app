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
}
