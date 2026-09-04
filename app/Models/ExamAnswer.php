<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item of an exam: a question put about a specific hadith.
 *
 * The row is created with the exam, already carrying `correct_answer` — the
 * reference for that hadith — and is filled in when the learner answers. So
 * the same question row («من هو راوي هذا الحديث؟») has as many answer rows as
 * the hadiths the exam covers, each with its own reference and its own result.
 */
class ExamAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_question_id',
        'hadith_id',
        'correct_answer',
        'answer_text',
        'score',
        'is_correct',
        'evaluation_report',
        'answered_at',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'is_correct' => 'boolean',
            'evaluation_report' => 'array',
            'answered_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'exam_question_id');
    }

    public function hadith(): BelongsTo
    {
        return $this->belongsTo(Hadith::class);
    }

    /**
     * Whether the learner has submitted something for this item. An item is
     * created empty, so a stored row is not by itself an answer.
     */
    public function isAnswered(): bool
    {
        return $this->answered_at !== null;
    }
}
