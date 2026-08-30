<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewSessionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'review_session_id',
        'hadith_id',
        'sort_order',
        'memorization_attempt_id',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ReviewSession::class, 'review_session_id');
    }

    public function hadith(): BelongsTo
    {
        return $this->belongsTo(Hadith::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(MemorizationAttempt::class, 'memorization_attempt_id');
    }

    public function isAnswered(): bool
    {
        return $this->memorization_attempt_id !== null;
    }
}
