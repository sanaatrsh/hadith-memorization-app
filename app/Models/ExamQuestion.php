<?php

namespace App\Models;

use App\Enums\ExamQuestionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExamQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id',
        'hadith_id',
        'question_template_id',
        'type',
        'question_text',
        'correct_answer',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'type' => ExamQuestionType::class,
            'sort_order' => 'integer',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function hadith(): BelongsTo
    {
        return $this->belongsTo(Hadith::class);
    }

    public function answer(): HasOne
    {
        return $this->hasOne(ExamAnswer::class);
    }
}
