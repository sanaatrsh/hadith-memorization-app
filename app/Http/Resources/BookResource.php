<?php

namespace App\Http\Resources;

use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Book */
class BookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'cover_url' => $this->coverUrl(),
            'hadiths_count' => $this->whenCounted('hadiths'),
            // Every active book is readable by every user; this only says
            // whether the user added it to their learning list to memorize it.
            'is_added' => $this->when(
                $this->resource->relationLoaded('currentUserBook'),
                fn () => $this->currentUserBook !== null,
            ),
            'added_at' => $this->whenLoaded('currentUserBook', fn () => $this->currentUserBook?->started_at),
            'memorized_count' => $this->whenCounted('memorized'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
