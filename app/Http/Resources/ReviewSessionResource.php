<?php

namespace App\Http\Resources;

use App\Models\ReviewSession;
use App\Models\ReviewSessionItem;
use App\Services\ReviewSessionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review session and its hadiths. The result — the overall score and the
 * per-hadith outcome — is released once the session is completed, the same way
 * an exam releases its results.
 *
 * @mixin ReviewSession
 */
class ReviewSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $completed = $this->isCompleted();

        $data = [
            'id' => $this->id,
            'status' => $this->status,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'is_completed' => $completed,
            'score' => $completed ? $this->score : null,
            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(fn (ReviewSessionItem $item) => $this->item($item, $completed)),
            ),
        ];

        if ($this->relationLoaded('items')) {
            $data['summary'] = app(ReviewSessionService::class)->summary($this->resource);
        }

        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    private function item(ReviewSessionItem $item, bool $completed): array
    {
        $attempt = $item->relationLoaded('attempt') ? $item->attempt : null;
        $passing = (int) config('athar.scoring.passing');

        $data = [
            'sort_order' => $item->sort_order,
            'hadith_id' => $item->hadith_id,
            'is_recited' => $item->isAnswered(),
            'hadith' => $item->relationLoaded('hadith') && $item->hadith !== null
                ? new HadithResource($item->hadith)
                : null,
        ];

        if (! $completed) {
            return $data;
        }

        $data['score'] = $attempt !== null ? (int) $attempt->final_score : null;
        $data['is_correct'] = $attempt !== null ? (int) $attempt->final_score >= $passing : null;
        $data['verdict'] = $attempt?->verdict?->value;
        // The learner's next appointment with this hadith, which is what the
        // result screen is for.
        $data['next_review_at'] = $item->hadith?->currentUserProgress?->next_review_at;

        return $data;
    }
}
