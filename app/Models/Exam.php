<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Exam extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'book_id',
        'question_count',
        'status',
        'started_at',
        'completed_at',
        'score',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'score' => 'integer',
            'question_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * The exam's questions — their wording only, one row per wording.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Every item of the exam: each question paired with one hadith. This is
     * what is graded and what the score averages over, so the count of these
     * rows — not of the questions — is the exam's length.
     */
    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(ExamAnswer::class, ExamQuestion::class, 'exam_id', 'exam_question_id')
            ->orderBy('exam_answers.sort_order')
            ->orderBy('exam_answers.id');
    }
}
