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

        // Both need the collection above: they quote real hadiths from it, and
        // the memorization history attaches to whichever users already exist.
        $this->call(ArabicExamSeeder::class);
        $this->call(ArabicMemorizationSeeder::class);
    }
}
