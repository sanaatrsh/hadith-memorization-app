<?php

namespace App\Http\Resources;

use App\Models\UserBook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserBook */
class UserBookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'book_id' => $this->book_id,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'book' => new BookResource($this->whenLoaded('book')),
        ];
    }
}
