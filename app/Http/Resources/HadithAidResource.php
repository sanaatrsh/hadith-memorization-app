<?php

namespace App\Http\Resources;

use App\Models\HadithAid;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin HadithAid */
class HadithAidResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'sort_order' => $this->sort_order,
        ];
    }
}
