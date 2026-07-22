<?php

namespace App\Http\Resources;

use App\Models\HadithTerm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin HadithTerm */
class HadithTermResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'term' => $this->term,
            'explanation' => $this->explanation,
        ];
    }
}
