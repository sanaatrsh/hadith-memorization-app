<?php

namespace App\Http\Resources;

use App\Models\Hadith;
use App\Models\MemorizationStackItem;
use App\Models\UserHadithProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of the memorization stack as the app reads it: the hadith to
 * memorize, why it is at this position, and the user's progress on it.
 *
 * @property-read array{hadith:Hadith, progress:?UserHadithProgress, stack_item:?MemorizationStackItem, queue_reason:string, position:int} $resource
 */
class MemorizationQueueItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Hadith $hadith */
        $hadith = $this->resource['hadith'];
        /** @var ?MemorizationStackItem $item */
        $item = $this->resource['stack_item'];
        /** @var ?UserHadithProgress $progress */
        $progress = $this->resource['progress'];

        return [
            'position' => $this->resource['position'],
            'queue_reason' => $this->resource['queue_reason'],
            'source' => $item?->source->value,
            'reason' => $item?->reason,
            'pushed_at' => $item?->pushed_at,
            'hadith' => new HadithResource($hadith),
            'progress' => $progress === null ? null : new UserHadithProgressResource($progress),
        ];
    }
}
