<?php

namespace App\Http\Resources;

use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A completed exam carries its result: the overall score plus, for every
 * question, the score and the correct answer. While it is still in progress
 * none of that is exposed — the examinee sees only which questions they have
 * already answered.
 *
 * @mixin Exam
 */
class ExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $reveal = $this->status === 'completed';

        $data = [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'book_id' => $this->book_id,
            'book_title' => $this->whenLoaded('book', fn () => $this->book->title),
            'status' => $this->status,
            'question_count' => $this->question_count,
            'results_released' => $reveal,
            // The score stays hidden until the exam is completed.
            'score' => $reveal ? $this->score : null,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'questions' => $this->whenLoaded(
                'questions',
                fn () => $this->questions->map(
                    fn ($question) => (new ExamQuestionResource($question))->reveal($reveal)
                )
            ),
        ];

        if ($reveal && $this->relationLoaded('questions')) {
            $data['summary'] = $this->summary();
        }

        return $data;
    }

    /**
     * @return array<string,int>
     */
    private function summary(): array
    {
        $answers = $this->questions
            ->filter(fn ($question) => $question->relationLoaded('answer') && $question->answer !== null)
            ->map(fn ($question) => $question->answer);

        return [
            'total_questions' => $this->questions->count(),
            'answered_count' => $answers->count(),
            'unanswered_count' => max($this->questions->count() - $answers->count(), 0),
            'correct_count' => $answers->where('is_correct', true)->count(),
            'incorrect_count' => $answers->where('is_correct', false)->count(),
            'passing_score' => (int) config('athar.scoring.passing'),
        ];
    }
}
