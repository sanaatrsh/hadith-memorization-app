<?php

namespace Database\Factories;

use App\Models\Hadith;
use App\Models\HadithTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HadithTerm>
 */
class HadithTermFactory extends Factory
{
    protected $model = HadithTerm::class;

    public function definition(): array
    {
        return [
            'hadith_id' => Hadith::factory(),
            'term' => fake()->word(),
            'explanation' => fake()->sentence(),
        ];
    }
}
