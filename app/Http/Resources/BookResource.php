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
            // Every active book is open to every user, so what matters is how
            // far this user has got in it, not whether they "added" it.
            'is_started' => $this->when(
                $this->resource->offsetExists('progress_count'),
                fn () => (int) $this->progress_count > 0,
            ),
            'progress_count' => $this->whenCounted('progress'),
            'memorized_count' => $this->whenCounted('memorized'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
