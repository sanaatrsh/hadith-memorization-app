<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamAnswer extends Model
{
    protected $fillable = [
        'exam_question_id',
        'answer_text',
        'score',
        'is_correct',
        'evaluation_report',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'is_correct' => 'boolean',
            'evaluation_report' => 'array',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'exam_question_id');
    }
}
