<?php

namespace App\Models;

use App\Enums\StackSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hadith sitting on the user's memorization stack. Unresolved items are the
 * top of the queue; resolving one (passing recitation, or the user popping it)
 * keeps the row for history.
 */
class MemorizationStackItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'hadith_id',
        'source',
        'reason',
        'trigger_score',
        'pushed_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => StackSource::class,
            'trigger_score' => 'integer',
            'pushed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hadith(): BelongsTo
    {
        return $this->belongsTo(Hadith::class);
    }

    /** @param  Builder<self>  $query */
    public function scopePending($query)
    {
        return $query->whereNull('resolved_at');
    }
}
