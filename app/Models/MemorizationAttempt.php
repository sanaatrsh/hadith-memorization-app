<?php

namespace App\Models;

use App\Enums\AttemptStatus;
use App\Enums\AttemptType;
use App\Enums\EvaluationVerdict;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemorizationAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_attempt_uuid',
        'user_id',
        'hadith_id',
        'type',
        'status',
        'recognized_text',
        'normalized_recognized_text',
        'normalized_reference_text',
        'deterministic_score',
        'ai_score',
        'final_score',
        'verdict',
        'comparison_report',
        'ai_report',
        'gemini_model',
        'gemini_interaction_id',
        'processing_ms',
        'failure_code',
        'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'type' => AttemptType::class,
            'status' => AttemptStatus::class,
            'verdict' => EvaluationVerdict::class,
            'comparison_report' => 'array',
            'ai_report' => 'array',
            'deterministic_score' => 'integer',
            'ai_score' => 'integer',
            'final_score' => 'integer',
            'processing_ms' => 'integer',
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
