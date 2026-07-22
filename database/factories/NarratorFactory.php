<?php

namespace Database\Factories;

use App\Models\Narrator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Narrator>
 */
class NarratorFactory extends Factory
{
    protected $model = Narrator::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'biography' => fake()->paragraph(),
        ];
    }
}
