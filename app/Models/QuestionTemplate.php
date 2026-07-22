<?php

namespace App\Models;

use App\Enums\ExamQuestionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuestionTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'prompt_template',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => ExamQuestionType::class,
            'is_active' => 'boolean',
        ];
    }
}
