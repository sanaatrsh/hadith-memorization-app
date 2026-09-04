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
            // The hadith's number inside its own collection: hadith #100 in
            // the table is the 4th hadith of الأربعون النووية.
            'number_in_book' => $this->number_in_book,
            'narrator_id' => $this->narrator_id,
            'title' => $this->title,
            // The isnad/context line the hadith opens with (مقدمة الحديث).
            'intro' => $this->intro,
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
            // Whether the hadith is sitting on the user's memorization stack.
            'on_memorization_stack' => $this->when(
                $this->resource->relationLoaded('currentUserStackItem'),
                fn () => $this->currentUserStackItem !== null,
            ),
            'stack_source' => $this->whenLoaded('currentUserStackItem', fn () => $this->currentUserStackItem?->source->value),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
