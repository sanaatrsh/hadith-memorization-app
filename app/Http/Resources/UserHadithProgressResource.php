<?php

namespace App\Http\Resources;

use App\Models\UserHadithProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserHadithProgress */
class UserHadithProgressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hadith_id' => $this->hadith_id,
            'status' => $this->status->value,
            'srs_level' => $this->srs_level,
            'best_score' => $this->best_score,
            'attempts_count' => $this->attempts_count,
            'last_attempt_at' => $this->last_attempt_at,
            'next_review_at' => $this->next_review_at,
            'memorized_at' => $this->memorized_at,
            'hadith' => new HadithResource($this->whenLoaded('hadith')),
        ];
    }
}
