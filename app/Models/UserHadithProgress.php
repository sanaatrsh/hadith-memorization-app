<?php

namespace App\Models;

use App\Enums\MemorizationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserHadithProgress extends Model
{
    use HasFactory;

    protected $table = 'user_hadith_progress';

    protected $fillable = [
        'user_id',
        'hadith_id',
        'status',
        'srs_level',
        'best_score',
        'attempts_count',
        'last_attempt_at',
        'next_review_at',
        'memorized_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MemorizationStatus::class,
            'srs_level' => 'integer',
            'best_score' => 'integer',
            'attempts_count' => 'integer',
            'last_attempt_at' => 'datetime',
            'next_review_at' => 'datetime',
            'memorized_at' => 'datetime',
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
}
