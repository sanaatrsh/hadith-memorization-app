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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
