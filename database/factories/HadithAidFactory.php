<?php

namespace Database\Factories;

use App\Models\Hadith;
use App\Models\HadithAid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HadithAid>
 */
class HadithAidFactory extends Factory
{
    protected $model = HadithAid::class;

    public function definition(): array
    {
        return [
            'hadith_id' => Hadith::factory(),
            'title' => fake()->sentence(3),
            'content' => fake()->paragraph(),
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
