<?php

namespace Database\Factories;

use App\Enums\ExamQuestionType;
use App\Models\QuestionTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionTemplate>
 */
class QuestionTemplateFactory extends Factory
{
    protected $model = QuestionTemplate::class;

    public function definition(): array
    {
        return [
            'type' => ExamQuestionType::Written,
            'prompt_template' => 'أكمل الحديث الذي يبدأ بـ: {opening}',
            'is_active' => true,
        ];
    }

    public function voice(): static
    {
        return $this->state(fn () => [
            'type' => ExamQuestionType::Voice,
            'prompt_template' => 'اذكر الحديث كاملاً من عنوانه: {title}',
        ]);
    }

    public function narrator(): static
    {
        return $this->state(fn () => [
            'type' => ExamQuestionType::Written,
            'prompt_template' => 'من هو راوي هذا الحديث؟',
        ]);
    }
}
