<?php

namespace App\Http\Resources;

use App\Models\ExamAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExamAnswer */
class ExamAnswerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_question_id' => $this->exam_question_id,
            'answer_text' => $this->answer_text,
            'score' => $this->score,
            'is_correct' => $this->is_correct,
            'evaluation_report' => $this->evaluation_report,
        ];
    }
}
