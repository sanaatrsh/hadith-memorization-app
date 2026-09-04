<?php

namespace App\Http\Resources;

use App\Models\ExamAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One item of an exam: a question put about a specific hadith.
 *
 * This is what the learner answers and what carries the result, so its `id` is
 * the id to send back when submitting an answer. The wording lives on the
 * question; the hadith and the reference answer live here.
 *
 * Scores and the correct answer are withheld until the exam is completed —
 * ExamResource turns revealing on.
 *
 * @mixin ExamAnswer
 */
class ExamAnswerResource extends JsonResource
{
    private bool $reveal = false;

    private bool $withQuestion = true;

    /**
     * Reveal this item's result (correct answer, score, report).
     */
    public function reveal(bool $reveal = true): self
    {
        $this->reveal = $reveal;

        return $this;
    }

    /**
     * Drop the repeated wording when the item is already nested under its
     * question.
     */
    public function withoutQuestion(): self
    {
        $this->withQuestion = false;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'exam_question_id' => $this->exam_question_id,
            'hadith_id' => $this->hadith_id,
            'sort_order' => $this->sort_order,
            'is_answered' => $this->isAnswered(),
            'answer_text' => $this->answer_text,
            'submitted_at' => $this->answered_at,
        ];

        if ($this->withQuestion && $this->relationLoaded('question')) {
            // Denormalized onto the item so a client can render a flat list of
            // items without walking back up to the question.
            $data['type'] = $this->question?->type->value;
            $data['question_text'] = $this->question?->question_text;
        }

        if ($this->relationLoaded('hadith') && $this->hadith !== null) {
            // Which hadith is being asked about. The number is the one inside
            // the book (الحديث الرابع من الأربعين), not the row id.
            $data['hadith'] = [
                'id' => $this->hadith->id,
                'number_in_book' => $this->hadith->number_in_book,
                'title' => $this->hadith->title,
                'intro' => $this->hadith->intro,
            ];
        }

        if ($this->reveal) {
            $data['correct_answer'] = $this->correct_answer;
            $data['is_correct'] = (bool) $this->is_correct;
            $data['score'] = (int) $this->score;
            $data['evaluation_report'] = $this->evaluation_report;
        }

        return $data;
    }
}
