<?php

namespace App\Http\Resources;

use App\Models\ExamQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * While the exam is in progress the examinee only learns that an answer was
 * received. Scores and the correct answer are revealed once the exam is
 * completed — see ExamResource, which turns revealing on.
 *
 * @mixin ExamQuestion
 */
class ExamQuestionResource extends JsonResource
{
    private bool $reveal = false;

    /**
     * Reveal the result of this question (correct answer, score, report).
     */
    public function reveal(bool $reveal = true): self
    {
        $this->reveal = $reveal;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'hadith_id' => $this->hadith_id,
            'type' => $this->type->value,
            'question_text' => $this->question_text,
            'sort_order' => $this->sort_order,
            'is_answered' => $this->relationLoaded('answer') ? $this->answer !== null : null,
        ];

        if (! $this->reveal) {
            // Results are withheld until every question is done.
            $data['answer'] = $this->whenLoaded('answer', fn () => [
                'answer_text' => $this->answer?->answer_text,
                'submitted_at' => $this->answer?->updated_at,
            ]);

            return $data;
        }

        $data['correct_answer'] = $this->correct_answer;
        $data['answer'] = $this->relationLoaded('answer') && $this->answer !== null
            ? new ExamAnswerResource($this->answer)
            : null;
        $data['is_correct'] = $this->relationLoaded('answer') ? (bool) $this->answer?->is_correct : null;
        $data['score'] = $this->relationLoaded('answer') ? (int) ($this->answer?->score ?? 0) : null;

        return $data;
    }
}
