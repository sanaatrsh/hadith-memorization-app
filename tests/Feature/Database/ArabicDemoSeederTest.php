<?php

namespace Tests\Feature\Database;

use Database\Seeders\ArabicDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArabicDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_an_idempotent_arabic_catalogue_for_api_examples(): void
    {
        $this->seed(ArabicDemoSeeder::class);
        $this->seed(ArabicDemoSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'admin@athar.test',
            'name' => 'مدير أثر',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'user@athar.test',
            'name' => 'سناء أحمد',
            'role' => 'user',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('books', ['title' => 'الأربعون النووية', 'is_active' => true]);
        $this->assertDatabaseHas('hadiths', [
            'title' => 'إنما الأعمال بالنيات',
            'text' => 'إنما الأعمال بالنيات وإنما لكل امرئ ما نوى',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('hadith_terms', ['term' => 'النيات']);
        $this->assertDatabaseHas('hadith_aids', ['title' => 'مفتاح الحفظ']);
        $this->assertDatabaseHas('question_templates', ['prompt_template' => 'من هو راوي هذا الحديث؟']);

        $this->assertDatabaseCount('books', 2);
        $this->assertDatabaseCount('hadiths', 4);
        $this->assertDatabaseCount('question_templates', 4);
        $this->assertDatabaseCount('user_hadith_progress', 3);
    }
}
