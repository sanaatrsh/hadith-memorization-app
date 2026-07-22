<?php

namespace Database\Factories;

use App\Enums\MemorizationStatus;
use App\Models\Hadith;
use App\Models\User;
use App\Models\UserHadithProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserHadithProgress>
 */
class UserHadithProgressFactory extends Factory
{
    protected $model = UserHadithProgress::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'hadith_id' => Hadith::factory(),
            'status' => MemorizationStatus::Memorizing,
            'srs_level' => 0,
            'best_score' => 0,
            'attempts_count' => 1,
            'last_attempt_at' => now(),
            'next_review_at' => now()->addDay(),
        ];
    }

    public function dueForReview(): static
    {
        return $this->state(fn () => [
            'status' => MemorizationStatus::Reviewing,
            'next_review_at' => now()->subDay(),
        ]);
    }
}
