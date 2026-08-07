<?php

namespace Tests\Feature\Database;

use App\Enums\AttemptStatus;
use App\Enums\AttemptType;
use App\Enums\EvaluationVerdict;
use App\Enums\MemorizationStatus;
use App\Enums\UserRole;
use App\Models\Hadith;
use App\Models\MemorizationAttempt;
use App\Models\ProgressAudit;
use App\Models\User;
use App\Models\UserBook;
use App\Models\UserHadithProgress;
use Database\Seeders\ArabicMemorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ArabicMemorizationSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @return Collection<int,User> */
    private function learners(int $count = 3)
    {
        User::factory()->create(['email' => 'admin@athar.test', 'role' => UserRole::Admin]);

        return User::factory()->count($count)->sequence(
            fn ($sequence) => ['email' => "learner{$sequence->index}@athar.test", 'role' => UserRole::User],
        )->create();
    }

    public function test_it_attaches_a_recitation_history_to_the_existing_learners(): void
    {
        $learners = $this->learners();

        $this->seed(ArabicMemorizationSeeder::class);
        $this->seed(ArabicMemorizationSeeder::class);

        // No users are invented, and every learner gets a history and the book.
        $this->assertSame(4, User::count());

        foreach ($learners as $learner) {
            $this->assertTrue(MemorizationAttempt::where('user_id', $learner->id)->exists());
            $this->assertTrue(UserHadithProgress::where('user_id', $learner->id)->exists());
            $this->assertTrue(UserBook::where('user_id', $learner->id)->exists());
        }

        // Reseeding updates the same attempts instead of duplicating them.
        $this->assertSame(34, MemorizationAttempt::count());
        $this->assertSame(13, UserHadithProgress::count());

        $this->assertSame(0, MemorizationAttempt::where('status', '!=', AttemptStatus::Completed->value)->count());
        $this->assertSame(0, MemorizationAttempt::whereNull('comparison_report')->count());

        // Seeding must never call Gemini, so no attempt carries AI output.
        $this->assertSame(0, MemorizationAttempt::whereNotNull('ai_score')->count());
        $this->assertSame(0, MemorizationAttempt::whereNotNull('gemini_model')->count());

        // All three attempt types are represented.
        foreach (AttemptType::cases() as $type) {
            $this->assertTrue(MemorizationAttempt::where('type', $type->value)->exists(), $type->value);
        }
    }

    public function test_scores_and_reports_match_the_deterministic_comparison(): void
    {
        $this->learners();
        $this->seed(ArabicMemorizationSeeder::class);

        $passing = (int) config('athar.scoring.passing');

        foreach (MemorizationAttempt::with('hadith')->get() as $attempt) {
            $this->assertSame($attempt->deterministic_score, $attempt->final_score);
            $this->assertSame($attempt->comparison_report['score'], $attempt->final_score);

            // A verbatim recitation scores 100; anything else is a real slip.
            $recitedExactly = $attempt->recognized_text === $attempt->hadith->text;
            $this->assertSame($recitedExactly, $attempt->final_score === 100);

            if ($attempt->final_score >= $passing) {
                $this->assertSame(EvaluationVerdict::Correct, $attempt->verdict);
            }
        }

        // The recitation that dropped a word reports exactly which one.
        $attempt = MemorizationAttempt::where('recognized_text', 'لا يؤمن أحدكم حتى يحب لأخيه ما يحب')->firstOrFail();
        $this->assertSame(['لنفسه'], $attempt->comparison_report['missing_words']);
    }

    public function test_progress_is_the_result_of_replaying_the_attempts(): void
    {
        $learners = $this->learners();
        $this->seed(ArabicMemorizationSeeder::class);

        $advanced = $learners->first();

        // Five passing attempts carry a hadith to the top level and memorize it.
        $hadith = Hadith::where('title', 'إنما الأعمال بالنيات')->sole();
        $progress = UserHadithProgress::where('user_id', $advanced->id)->where('hadith_id', $hadith->id)->sole();

        $this->assertSame(MemorizationStatus::Memorized, $progress->status);
        $this->assertSame(4, $progress->srs_level);
        $this->assertSame(5, $progress->attempts_count);
        $this->assertSame(100, $progress->best_score);
        $this->assertNotNull($progress->memorized_at);
        $this->assertNull($progress->next_review_at);

        // A single below-threshold attempt leaves the learner still memorizing.
        $beginner = $learners->last();
        $hadith = Hadith::where('title', 'إن الله كتب الإحسان على كل شيء')->sole();
        $progress = UserHadithProgress::where('user_id', $beginner->id)->where('hadith_id', $hadith->id)->sole();

        $this->assertSame(MemorizationStatus::Memorizing, $progress->status);
        $this->assertSame(0, $progress->srs_level);
        $this->assertSame(1, $progress->attempts_count);
        $this->assertNull($progress->memorized_at);
        $this->assertNotNull($progress->next_review_at);

        // Every progress row agrees with the attempts behind it.
        foreach (UserHadithProgress::all() as $progress) {
            $attempts = MemorizationAttempt::where('user_id', $progress->user_id)
                ->where('hadith_id', $progress->hadith_id)
                ->get();

            $this->assertSame($attempts->count(), $progress->attempts_count);
            $this->assertSame((int) $attempts->max('final_score'), $progress->best_score);
        }
    }

    public function test_it_records_the_supervisor_adjustment_as_an_audit(): void
    {
        $learners = $this->learners();
        $this->seed(ArabicMemorizationSeeder::class);
        $this->seed(ArabicMemorizationSeeder::class);

        $this->assertSame(1, ProgressAudit::count());

        $audit = ProgressAudit::sole();
        $hadith = Hadith::where('title', 'من رأى منكم منكرا فليغيره')->sole();

        $this->assertSame($learners->last()->id, $audit->user_id);
        $this->assertSame($hadith->id, $audit->hadith_id);
        $this->assertSame('memorizing', $audit->changes['old']['status']);
        $this->assertSame('reviewing', $audit->changes['new']['status']);
        $this->assertNotEmpty($audit->reason);

        // The adjustment is applied, not just logged.
        $progress = UserHadithProgress::where('user_id', $audit->user_id)->where('hadith_id', $hadith->id)->sole();
        $this->assertSame(MemorizationStatus::Reviewing, $progress->status);
        $this->assertSame(1, $progress->srs_level);
    }

    public function test_it_does_nothing_when_there_are_no_learners(): void
    {
        $this->seed(ArabicMemorizationSeeder::class);

        $this->assertSame(0, MemorizationAttempt::count());
        $this->assertSame(0, UserHadithProgress::count());
    }
}
