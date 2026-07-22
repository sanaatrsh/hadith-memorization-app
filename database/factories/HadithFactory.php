<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\Hadith;
use App\Models\Narrator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hadith>
 */
class HadithFactory extends Factory
{
    protected $model = Hadith::class;

    public function definition(): array
    {
        return [
            'book_id' => Book::factory(),
            'narrator_id' => Narrator::factory(),
            'title' => fake()->sentence(4),
            'text' => fake()->paragraph(),
            'source' => fake()->words(2, true),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
