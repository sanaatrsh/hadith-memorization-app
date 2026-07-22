<?php

namespace App\Http\Resources;

use App\Models\ExamQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExamQuestion */
class ExamQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hadith_id' => $this->hadith_id,
            'type' => $this->type->value,
            'question_text' => $this->question_text,
            'sort_order' => $this->sort_order,
            // correct_answer is intentionally NOT exposed to the examinee.
            'answer' => new ExamAnswerResource($this->whenLoaded('answer')),
        ];
    }
}
