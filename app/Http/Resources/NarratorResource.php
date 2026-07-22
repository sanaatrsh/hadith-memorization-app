<?php

namespace App\Http\Resources;

use App\Models\Narrator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Narrator */
class NarratorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'biography' => $this->biography,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
