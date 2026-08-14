<?php

namespace App\Http\Resources;

use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Exam */
class ExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'book_id' => $this->book_id,
            'book_title' => $this->whenLoaded('book', fn () => $this->book->title),
            'status' => $this->status,
            'question_count' => $this->question_count,
            'score' => $this->score,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'questions' => ExamQuestionResource::collection($this->whenLoaded('questions')),
        ];
    }
}
