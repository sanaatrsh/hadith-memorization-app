<?php

namespace Tests\Feature\User;

use App\Enums\MemorizationStatus;
use App\Models\Book;
use App\Models\Hadith;
use App\Models\MemorizationAttempt;
use App\Models\User;
use App\Models\UserHadithProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemorizedAndActivityTest extends TestCase
{
    use RefreshDatabase;

    private function hadith(): Hadith
    {
        return Hadith::factory()->create([
            'book_id' => Book::factory()->create()->id,
            'text' => 'انما الاعمال بالنيات',
        ]);
    }

    /**
     * A recitation on a given day, which is what the streak counts.
     */
    private function attemptOn(User $user, Hadith $hadith, string $daysAgo): void
    {
        MemorizationAttempt::create([
            'client_attempt_uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'hadith_id' => $hadith->id,
            'type' => 'memorization',
            'status' => 'completed',
            'recognized_text' => 'انما الاعمال بالنيات',
            'normalized_recognized_text' => 'انما الاعمال بالنيات',
            'normalized_reference_text' => 'انما الاعمال بالنيات',
            'deterministic_score' => 100,
            'final_score' => 100,
            'verdict' => 'correct',
        ])->forceFill(['created_at' => now()->subDays((int) $daysAgo)])->save();
    }

    // --- the "تم الحفظ" button ------------------------------------------

    public function test_marking_a_hadith_memorized_records_it_and_clears_the_schedule(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = $this->hadith();

        UserHadithProgress::create([
            'user_id' => $user->id,
            'hadith_id' => $hadith->id,
            'status' => MemorizationStatus::Reviewing->value,
            'srs_level' => 1,
            'next_review_at' => now()->addDay(),
        ]);

        $this->postJson("/api/v1/user/hadiths/{$hadith->id}/memorized")
            ->assertOk()
            ->assertJsonPath('data.already_memorized', false)
            ->assertJsonPath('data.progress.status', 'memorized')
            ->assertJsonPath('data.progress.next_review_at', null);

        $this->assertNotNull($user->hadithProgress()->first()->memorized_at);
    }

    public function test_pressing_it_again_reports_that_it_was_already_memorized(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $hadith = $this->hadith();

        $first = $this->postJson("/api/v1/user/hadiths/{$hadith->id}/memorized")
            ->assertOk()
            ->assertJsonPath('data.already_memorized', false)
            ->json('data.memorized_at');

        $second = $this->postJson("/api/v1/user/hadiths/{$hadith->id}/memorized")
            ->assertOk()
            ->assertJsonPath('data.already_memorized', true)
            ->json('data.memorized_at');

        // The original date is kept rather than reset by the second press.
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('user_hadith_progress', 1);
    }

    public function test_marking_it_memorized_takes_it_off_the_memorization_stack(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = $this->hadith();

        $this->postJson('/api/v1/user/memorization/stack', ['hadith_id' => $hadith->id])->assertCreated();
        $this->postJson("/api/v1/user/hadiths/{$hadith->id}/memorized")->assertOk();

        $this->assertDatabaseMissing('memorization_stack_items', [
            'user_id' => $user->id,
            'hadith_id' => $hadith->id,
            'resolved_at' => null,
        ]);
    }

    public function test_an_inactive_hadith_cannot_be_marked_memorized(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $hadith = Hadith::factory()->inactive()->create();

        $this->postJson("/api/v1/user/hadiths/{$hadith->id}/memorized")->assertStatus(422);
    }

    // --- streak and activity heatmap -------------------------------------

    public function test_the_streak_counts_consecutive_days_of_recitation(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = $this->hadith();

        foreach (['0', '1', '2'] as $daysAgo) {
            $this->attemptOn($user, $hadith, $daysAgo);
        }
        // A gap, then an older run that must not extend the current streak.
        $this->attemptOn($user, $hadith, '5');
        $this->attemptOn($user, $hadith, '6');

        $this->getJson('/api/v1/user/progress')
            ->assertOk()
            ->assertJsonPath('data.current_streak_days', 3)
            ->assertJsonPath('data.longest_streak_days', 3);
    }

    public function test_a_run_ending_yesterday_still_counts_because_today_is_not_over(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = $this->hadith();

        $this->attemptOn($user, $hadith, '1');
        $this->attemptOn($user, $hadith, '2');

        $this->getJson('/api/v1/user/progress')
            ->assertOk()
            ->assertJsonPath('data.current_streak_days', 2);
    }

    public function test_the_streak_breaks_once_a_whole_day_is_missed(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = $this->hadith();

        $this->attemptOn($user, $hadith, '3');
        $this->attemptOn($user, $hadith, '4');

        $this->getJson('/api/v1/user/progress')
            ->assertOk()
            ->assertJsonPath('data.current_streak_days', 0)
            ->assertJsonPath('data.longest_streak_days', 2);
    }

    public function test_a_learner_who_never_recited_has_no_streak(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/user/progress')
            ->assertOk()
            ->assertJsonPath('data.current_streak_days', 0)
            ->assertJsonPath('data.longest_streak_days', 0);
    }

    public function test_the_activity_heatmap_covers_every_day_including_the_empty_ones(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = $this->hadith();

        $this->attemptOn($user, $hadith, '0');
        $this->attemptOn($user, $hadith, '0');

        $activity = $this->getJson('/api/v1/user/progress')->assertOk()->json('data.activity');

        $this->assertCount(90, $activity);
        $this->assertSame(now()->toDateString(), end($activity)['date']);
        $this->assertSame(2, end($activity)['attempts']);
        $this->assertTrue(end($activity)['active']);
        // The oldest day in the window had nothing.
        $this->assertFalse($activity[0]['active']);
    }
}
