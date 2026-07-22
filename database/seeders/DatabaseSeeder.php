<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Book;
use App\Models\Hadith;
use App\Models\HadithAid;
use App\Models\HadithTerm;
use App\Models\Narrator;
use App\Models\QuestionTemplate;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Admin account
        User::updateOrCreate(
            ['email' => 'admin@athar.test'],
            [
                'name' => 'Athar Admin',
                'password' => Hash::make('password'),
                'role' => UserRole::Admin,
                'is_active' => true,
            ]
        );

        // Regular test account
        User::updateOrCreate(
            ['email' => 'user@athar.test'],
            [
                'name' => 'Athar User',
                'password' => Hash::make('password'),
                'role' => UserRole::User,
                'is_active' => true,
            ]
        );

        // Exam question templates
        $templates = [
            ['type' => 'written', 'prompt_template' => 'من هو راوي هذا الحديث؟'],
            ['type' => 'written', 'prompt_template' => 'ما هو مصدر هذا الحديث؟'],
            ['type' => 'written', 'prompt_template' => 'أكمل الحديث الذي يبدأ بـ: {opening}'],
            ['type' => 'voice', 'prompt_template' => 'اذكر الحديث كاملاً من عنوانه: {title}'],
        ];
        foreach ($templates as $t) {
            QuestionTemplate::updateOrCreate(
                ['prompt_template' => $t['prompt_template']],
                ['type' => $t['type'], 'is_active' => true]
            );
        }

        // Sample content
        $narrators = Narrator::factory(3)->create();

        Book::factory(2)->create()->each(function (Book $book) use ($narrators) {
            Hadith::factory(5)->create([
                'book_id' => $book->id,
                'narrator_id' => $narrators->random()->id,
            ])->each(function (Hadith $hadith) {
                HadithTerm::factory(2)->create(['hadith_id' => $hadith->id]);
                HadithAid::factory(2)->create(['hadith_id' => $hadith->id]);
            });
        });
    }
}
