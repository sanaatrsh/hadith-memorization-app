<?php

namespace App\Http\Resources;

use App\Models\Hadith;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Hadith */
class HadithResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'book_id' => $this->book_id,
            'narrator_id' => $this->narrator_id,
            'title' => $this->title,
            'text' => $this->text,
            'source' => $this->source,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'audio_url' => $this->audioUrl(),
            'attachment_url' => $this->attachmentUrl(),
            'narrator' => new NarratorResource($this->whenLoaded('narrator')),
            'book' => new BookResource($this->whenLoaded('book')),
            'terms' => HadithTermResource::collection($this->whenLoaded('terms')),
            'aids' => HadithAidResource::collection($this->whenLoaded('aids')),
            // Current authenticated-user progress is attached in Phase 3.
            'progress' => $this->whenLoaded('currentUserProgress', fn () => new UserHadithProgressResource($this->currentUserProgress)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
