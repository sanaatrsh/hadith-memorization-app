<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(ArabicDemoSeeder::class);

        // Runs after the demo catalogue so the authentic wording of the shared
        // hadiths (نية / من كان يؤمن بالله واليوم الآخر) is the one that lands.
        $this->call(NawawiFortySeeder::class);

        // Needs the collection above: its exams quote real hadiths from it.
        $this->call(ArabicExamSeeder::class);
    }
}
