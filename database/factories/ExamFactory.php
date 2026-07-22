<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\Exam;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exam>
 */
class ExamFactory extends Factory
{
    protected $model = Exam::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'book_id' => Book::factory(),
            'question_count' => 3,
            'status' => 'in_progress',
            'started_at' => now(),
        ];
    }
}
