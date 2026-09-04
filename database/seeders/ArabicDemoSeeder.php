<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MemorizationStatus;
use App\Enums\UserRole;
use App\Models\Book;
use App\Models\Hadith;
use App\Models\HadithAid;
use App\Models\HadithTerm;
use App\Models\Narrator;
use App\Models\QuestionTemplate;
use App\Models\User;
use App\Models\UserHadithProgress;
use Database\Seeders\Concerns\SeedsBookCovers;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ArabicDemoSeeder extends Seeder
{
    use SeedsBookCovers;

    /**
     * Seed a stable Arabic dataset for Swagger and local API exploration.
     *
     * It is idempotent: rerunning db:seed updates the same records instead of
     * adding another copy of the demo catalogue.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@athar.test'],
            [
                'name' => 'مدير أثر',
                'password' => Hash::make('password'),
                'role' => UserRole::Admin,
                'is_active' => true,
            ],
        );

        $learner = User::updateOrCreate(
            ['email' => 'user@athar.test'],
            [
                'name' => 'سناء أحمد',
                'password' => Hash::make('password'),
                'birth_date' => '1998-05-20',
                'role' => UserRole::User,
                'is_active' => true,
            ],
        );

        $narrators = collect([
            ['name' => 'عمر بن الخطاب', 'biography' => 'أمير المؤمنين وثاني الخلفاء الراشدين.'],
            ['name' => 'أبو هريرة', 'biography' => 'عبد الرحمن بن صخر الدوسي، من أكثر الصحابة رواية للحديث.'],
            ['name' => 'أنس بن مالك', 'biography' => 'خادم رسول الله صلى الله عليه وسلم وصحابيه.'],
        ])->mapWithKeys(function (array $attributes): array {
            $narrator = Narrator::updateOrCreate(['name' => $attributes['name']], $attributes);

            return [$attributes['name'] => $narrator];
        });

        $books = collect([
            [
                'title' => 'الأربعون النووية',
                'description' => 'مجموعة مختارة من جوامع كلم النبي صلى الله عليه وسلم للتدرّب على الحفظ والمراجعة.',
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'title' => 'رياض الصالحين',
                'description' => 'أحاديث في الآداب والفضائل والسلوك الإسلامي.',
                'is_active' => true,
                'sort_order' => 2,
            ],
        ])->mapWithKeys(function (array $attributes): array {
            $book = Book::updateOrCreate(['title' => $attributes['title']], $attributes);
            $this->attachCover($book);

            return [$attributes['title'] => $book];
        });

        $hadiths = [];
        foreach ([
            [
                'book' => 'الأربعون النووية',
                'narrator' => 'عمر بن الخطاب',
                'title' => 'إنما الأعمال بالنيات',
                'text' => 'إنما الأعمال بالنيات وإنما لكل امرئ ما نوى',
                'source' => 'صحيح البخاري وصحيح مسلم',
                // Its number in الأربعون النووية, which NawawiFortySeeder
                // later fills the rest of — so the two agree.
                'number' => 1,
                'sort_order' => 1,
                'terms' => [
                    ['term' => 'النيات', 'explanation' => 'المقاصد التي يقصدها الإنسان بعمله.'],
                    ['term' => 'امرئ', 'explanation' => 'إنسان أو شخص.'],
                ],
                'aids' => [
                    ['title' => 'مفتاح الحفظ', 'content' => 'قسّم النص إلى عبارتين: الأعمال بالنيات، ثم لكل امرئ ما نوى.', 'sort_order' => 1],
                ],
            ],
            [
                'book' => 'الأربعون النووية',
                'narrator' => 'أبو هريرة',
                'title' => 'من كان يؤمن بالله واليوم الآخر',
                'text' => 'من كان يؤمن بالله واليوم الآخر فليقل خيرا أو ليصمت',
                'source' => 'صحيح البخاري وصحيح مسلم',
                'number' => 15,
                'sort_order' => 2,
                'terms' => [
                    ['term' => 'فليقل', 'explanation' => 'لام الأمر؛ أي ليقل قولاً حسناً.'],
                    ['term' => 'ليصمت', 'explanation' => 'يمتنع عن الكلام الذي لا خير فيه.'],
                ],
                'aids' => [
                    ['title' => 'مفتاح الحفظ', 'content' => 'تذكّر المقابلة: فليقل خيرا أو ليصمت.', 'sort_order' => 1],
                ],
            ],
            [
                'book' => 'رياض الصالحين',
                'narrator' => 'أنس بن مالك',
                'title' => 'لا يؤمن أحدكم حتى يحب لأخيه',
                'text' => 'لا يؤمن أحدكم حتى يحب لأخيه ما يحب لنفسه',
                'source' => 'صحيح البخاري وصحيح مسلم',
                'number' => 1,
                'sort_order' => 1,
                'terms' => [
                    ['term' => 'لأخيه', 'explanation' => 'لأخيه في الإسلام.'],
                    ['term' => 'لنفسه', 'explanation' => 'ما يتمناه الإنسان لنفسه من الخير.'],
                ],
                'aids' => [
                    ['title' => 'مفتاح الحفظ', 'content' => 'ابدأ بالنفي: لا يؤمن، ثم الغاية: حتى يحب لأخيه.', 'sort_order' => 1],
                ],
            ],
            [
                'book' => 'رياض الصالحين',
                'narrator' => 'عبد الله بن عمرو بن العاص',
                'title' => 'المسلم من سلم المسلمون',
                'text' => 'المسلم من سلم المسلمون من لسانه ويده',
                'source' => 'صحيح البخاري وصحيح مسلم',
                'number' => 2,
                'sort_order' => 2,
                'terms' => [
                    ['term' => 'سلم', 'explanation' => 'أمن ونجا من الأذى.'],
                    ['term' => 'لسانه ويده', 'explanation' => 'القول والفعل اللذان قد يقع بهما الأذى.'],
                ],
                'aids' => [
                    ['title' => 'مفتاح الحفظ', 'content' => 'اختم بموضع الأذى: من لسانه ويده.', 'sort_order' => 1],
                ],
            ],
        ] as $attributes) {
            $book = $books->get($attributes['book']);
            $narrator = $narrators->get($attributes['narrator'])
                ?? Narrator::updateOrCreate(['name' => $attributes['narrator']], ['biography' => null]);

            $hadith = Hadith::updateOrCreate(
                ['book_id' => $book->id, 'title' => $attributes['title']],
                [
                    'narrator_id' => $narrator->id,
                    'text' => $attributes['text'],
                    'source' => $attributes['source'],
                    'is_active' => true,
                    'number_in_book' => $attributes['number'],
                    'sort_order' => $attributes['sort_order'],
                ],
            );

            foreach ($attributes['terms'] as $term) {
                HadithTerm::updateOrCreate(
                    ['hadith_id' => $hadith->id, 'term' => $term['term']],
                    ['explanation' => $term['explanation']],
                );
            }

            foreach ($attributes['aids'] as $aid) {
                HadithAid::updateOrCreate(
                    ['hadith_id' => $hadith->id, 'title' => $aid['title']],
                    ['content' => $aid['content'], 'sort_order' => $aid['sort_order']],
                );
            }

            $hadiths[$attributes['title']] = $hadith;
        }

        foreach ([
            ['type' => 'written', 'prompt_template' => 'من هو راوي هذا الحديث؟'],
            ['type' => 'written', 'prompt_template' => 'ما مصدر هذا الحديث؟'],
            ['type' => 'written', 'prompt_template' => 'أكمل نص هذا الحديث.'],
            ['type' => 'voice', 'prompt_template' => 'اتل هذا الحديث من حفظك.'],
        ] as $template) {
            QuestionTemplate::updateOrCreate(
                ['prompt_template' => $template['prompt_template']],
                ['type' => $template['type'], 'is_active' => true],
            );
        }

        foreach ([
            ['title' => 'إنما الأعمال بالنيات', 'status' => MemorizationStatus::Reviewing, 'srs_level' => 2, 'best_score' => 96, 'attempts_count' => 3, 'next_review_at' => now()->subHour()],
            ['title' => 'من كان يؤمن بالله واليوم الآخر', 'status' => MemorizationStatus::Memorizing, 'srs_level' => 1, 'best_score' => 82, 'attempts_count' => 2, 'next_review_at' => now()->addDays(2)],
            ['title' => 'لا يؤمن أحدكم حتى يحب لأخيه', 'status' => MemorizationStatus::Memorized, 'srs_level' => 4, 'best_score' => 100, 'attempts_count' => 4, 'next_review_at' => null],
        ] as $progress) {
            $hadith = $hadiths[$progress['title']];
            UserHadithProgress::updateOrCreate(
                ['user_id' => $learner->id, 'hadith_id' => $hadith->id],
                [
                    'status' => $progress['status'],
                    'srs_level' => $progress['srs_level'],
                    'best_score' => $progress['best_score'],
                    'attempts_count' => $progress['attempts_count'],
                    'next_review_at' => $progress['next_review_at'],
                    'last_attempt_at' => now()->subDay(),
                    'memorized_at' => $progress['status'] === MemorizationStatus::Memorized ? now()->subDays(3) : null,
                ],
            );
        }
    }
}
