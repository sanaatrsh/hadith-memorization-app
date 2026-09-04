<?php

namespace App\Http\Resources;

use App\Models\Exam;
use App\Models\ExamAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A book's exam.
 *
 * It is exposed twice over, from the same rows: `questions` is the stored
 * structure — each wording once, with one answer row per hadith it is asked
 * about — and `items` is that flattened into the list the learner works
 * through. An item's `id` is what an answer is submitted against.
 *
 * A completed exam carries its result: the overall score plus, for every item,
 * the score and the correct answer. While it is still in progress none of that
 * is exposed — the examinee sees only which items they have already answered.
 *
 * @mixin Exam
 */
class ExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $reveal = $this->status === 'completed';
        $items = $this->items();

        $data = [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'book_id' => $this->book_id,
            'book_title' => $this->whenLoaded('book', fn () => $this->book->title),
            'status' => $this->status,
            // The exam's length: how many items are graded.
            'question_count' => $this->question_count,
            // How many distinct wordings those items draw on.
            'distinct_question_count' => $this->whenLoaded('questions', fn () => $this->questions->count()),
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

        if ($items !== null) {
            $data['items'] = $items->map(
                fn (ExamAnswer $item) => (new ExamAnswerResource($item))->reveal($reveal)
            )->values();
        }

        if ($reveal && $items !== null) {
            $data['summary'] = $this->summary($items);
        }

        return $data;
    }

    /**
     * Every item of the exam, in the order the learner works through them.
     *
     * Taken from the loaded questions so a single eager load serves both
     * views; null when the questions were not loaded at all (the exam
     * listing), which is what keeps `items` out of that payload.
     *
     * @return Collection<int,ExamAnswer>|null
     */
    private function items(): ?Collection
    {
        if (! $this->resource->relationLoaded('questions')) {
            return null;
        }

        return $this->questions
            ->flatMap(fn ($question) => $question->relationLoaded('answers') ? $question->answers : [])
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * @param  Collection<int,ExamAnswer>  $items
     * @return array<string,int>
     */
    private function summary(Collection $items): array
    {
        $answered = $items->filter(fn (ExamAnswer $item) => $item->isAnswered());

        return [
            'total_questions' => $items->count(),
            'distinct_question_count' => $this->questions->count(),
            'answered_count' => $answered->count(),
            'unanswered_count' => max($items->count() - $answered->count(), 0),
            'correct_count' => $answered->where('is_correct', true)->count(),
            'incorrect_count' => $answered->where('is_correct', false)->count(),
            'passing_score' => (int) config('athar.scoring.passing'),
        ];
    }
}
