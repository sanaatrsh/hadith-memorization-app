<?php

namespace App\Http\Resources;

use App\Models\ExamQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A question of the exam: its wording, stored once, plus the answer rows —
 * one per hadith it is asked about.
 *
 * @mixin ExamQuestion
 */
class ExamQuestionResource extends JsonResource
{
    private bool $reveal = false;

    /**
     * Reveal the results of this question's items.
     */
    public function reveal(bool $reveal = true): self
    {
        $this->reveal = $reveal;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'question_text' => $this->question_text,
            'sort_order' => $this->sort_order,
            'answers' => $this->whenLoaded(
                'answers',
                fn () => $this->answers->map(
                    fn ($answer) => (new ExamAnswerResource($answer))
                        ->withoutQuestion()
                        ->reveal($this->reveal)
                )
            ),
        ];
    }
}
