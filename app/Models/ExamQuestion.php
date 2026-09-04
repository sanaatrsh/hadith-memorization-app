<?php

namespace App\Models;

use App\Enums\ExamQuestionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One question of an exam, stored once for its wording only — «من هو راوي هذا
 * الحديث؟», «أكمل نص هذا الحديث», «اتل هذا الحديث من حفظك».
 *
 * The wording carries no hadith: which hadiths it is asked about, and the
 * reference answer for each of them, live on its answers. That is what makes
 * the relationship one-to-many.
 */
class ExamQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id',
        'question_template_id',
        'type',
        'question_text',
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

    /**
     * One row per hadith this question is asked about: the reference answer
     * for that hadith plus whatever the learner submitted for it.
     */
    public function answers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class)->orderBy('sort_order')->orderBy('id');
    }
}
