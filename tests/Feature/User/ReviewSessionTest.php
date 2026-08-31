<?php

namespace Tests\Feature\User;

use App\Enums\MemorizationStatus;
use App\Models\Book;
use App\Models\Hadith;
use App\Models\User;
use App\Models\UserHadithProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A review sitting contains the hadiths due today and nothing else, and ends
 * with a result.
 */
class ReviewSessionTest extends TestCase
{
    use RefreshDatabase;

    private function hadith(string $text = 'انما الاعمال بالنيات'): Hadith
    {
        return Hadith::factory()->create([
            'book_id' => Book::factory()->create()->id,
            'text' => $text,
        ]);
    }

    private function due(User $user, Hadith $hadith, ?string $at = '-1 day'): UserHadithProgress
    {
        return UserHadithProgress::create([
            'user_id' => $user->id,
            'hadith_id' => $hadith->id,
            'status' => MemorizationStatus::Reviewing->value,
            'srs_level' => 1,
            'next_review_at' => $at === null ? null : now()->modify($at),
        ]);
    }

    private function fakeGemini(): void
    {
        config()->set('services.gemini.api_key', 'test-key');

        Http::fake(['*' => Http::response([
            'responseId' => 'r1',
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'is_textually_correct' => true,
                'score' => 95,
                'verdict' => 'correct',
                'missing_words' => [],
                'extra_words' => [],
                'incorrect_segments' => [],
                'feedback_ar' => 'تسميع جيد',
                'recommended_action' => 'continue',
            ], JSON_UNESCAPED_UNICODE)]]]]],
        ], 200)]);
    }

    private function recite(Hadith $hadith, string $text, ?int $sessionId = null): TestResponse
    {
        return $this->postJson('/api/v1/memorization/attempts', array_filter([
            'client_attempt_uuid' => (string) Str::uuid(),
            'hadith_id' => $hadith->id,
            'recognized_text' => $text,
            'review_session_id' => $sessionId,
        ]));
    }

    public function test_a_session_holds_what_is_due_and_leaves_out_what_is_scheduled_ahead(): void
    {
        // Frozen mid-morning: "later today" has to stay inside today, which it
        // would not if the suite happened to run late in the evening.
        $this->travelTo(Carbon::today()->addHours(9));

        Sanctum::actingAs($user = User::factory()->create());

        $overdue = $this->hadith();
        $dueLaterToday = $this->hadith();
        $scheduledAhead = $this->hadith();
        $neverTouched = $this->hadith();

        $this->due($user, $overdue, '-3 days');
        $this->due($user, $dueLaterToday, '+2 hours');
        // Started today but recited well, so it is booked for a later day.
        $this->due($user, $scheduledAhead, '+7 days');
        // $neverTouched has no progress row and was never pushed.

        $response = $this->postJson('/api/v1/user/review-sessions')
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.summary.total_hadiths', 2);

        $ids = collect($response->json('data.items'))->pluck('hadith_id');

        $this->assertEqualsCanonicalizing([$overdue->id, $dueLaterToday->id], $ids->all());
        $this->assertNotContains($scheduledAhead->id, $ids->all());
        $this->assertNotContains($neverTouched->id, $ids->all());
    }

    public function test_a_hadith_added_today_and_not_yet_recited_joins_the_session(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $hadith = $this->hadith();

        // Pushing it onto the stack is how a learner "adds" a hadith; it has no
        // review date yet, but it is this couple of days' new material.
        $this->postJson('/api/v1/user/memorization/stack', ['hadith_id' => $hadith->id])->assertCreated();

        $this->postJson('/api/v1/user/review-sessions')
            ->assertCreated()
            ->assertJsonPath('data.summary.total_hadiths', 1)
            ->assertJsonPath('data.items.0.hadith_id', $hadith->id);
    }

    public function test_a_hadith_added_before_the_recent_window_does_not_join_the_session(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $old = $this->hadith();

        // Started a week ago and never recited: it is backlog, not today's work,
        // and it has no review date to make it due either.
        $progress = UserHadithProgress::create([
            'user_id' => $user->id,
            'hadith_id' => $old->id,
            'status' => MemorizationStatus::Memorizing->value,
            'srs_level' => 0,
            'next_review_at' => null,
        ]);
        $progress->forceFill(['created_at' => now()->subDays(7)])->save();

        $this->postJson('/api/v1/user/review-sessions')
            ->assertCreated()
            ->assertJsonPath('data.summary.total_hadiths', 0);
    }

    public function test_an_overdue_hadith_from_last_week_is_still_included(): void
    {
        // Spaced repetition only works if the backlog of genuinely due hadiths
        // keeps coming back, however old it is.
        Sanctum::actingAs($user = User::factory()->create());
        $old = $this->hadith();

        $progress = $this->due($user, $old, '-9 days');
        $progress->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->postJson('/api/v1/user/review-sessions')
            ->assertCreated()
            ->assertJsonPath('data.summary.total_hadiths', 1)
            ->assertJsonPath('data.items.0.hadith_id', $old->id);
    }

    public function test_the_overdue_hadith_comes_before_the_one_due_later_today(): void
    {
        $this->travelTo(Carbon::today()->addHours(9));

        Sanctum::actingAs($user = User::factory()->create());

        $later = $this->hadith();
        $overdue = $this->hadith();
        $this->due($user, $later, '+5 hours');
        $this->due($user, $overdue, '-2 days');

        $response = $this->postJson('/api/v1/user/review-sessions')->assertCreated();

        $this->assertSame(
            [$overdue->id, $later->id],
            collect($response->json('data.items'))->pluck('hadith_id')->all(),
        );
    }

    public function test_starting_again_returns_the_session_already_in_progress(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->due($user, $this->hadith());

        $first = $this->postJson('/api/v1/user/review-sessions')->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/user/review-sessions')->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('review_sessions', 1);
    }

    public function test_a_session_in_progress_withholds_the_result(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->fakeGemini();
        $hadith = $this->hadith();
        $this->due($user, $hadith);

        $sessionId = $this->postJson('/api/v1/user/review-sessions')->json('data.id');
        $this->recite($hadith, 'انما الاعمال بالنيات', $sessionId)->assertOk();

        $item = $this->getJson("/api/v1/user/review-sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.is_completed', false)
            ->assertJsonPath('data.score', null)
            ->assertJsonPath('data.items.0.is_recited', true)
            ->json('data.items.0');

        $this->assertArrayNotHasKey('score', $item);
        $this->assertArrayNotHasKey('is_correct', $item);
    }

    public function test_completing_the_session_releases_the_result_and_the_next_review_date(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->fakeGemini();

        $good = $this->hadith();
        $poor = $this->hadith();
        $this->due($user, $good, '-2 days');
        $this->due($user, $poor, '-1 day');

        $sessionId = $this->postJson('/api/v1/user/review-sessions')->json('data.id');

        $this->recite($good, 'انما الاعمال بالنيات', $sessionId)->assertOk();
        $this->recite($poor, 'كلمة خاطئة تماما', $sessionId)->assertOk();

        $response = $this->postJson("/api/v1/user/review-sessions/{$sessionId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.is_completed', true)
            ->assertJsonPath('data.summary.total_hadiths', 2)
            ->assertJsonPath('data.summary.recited_count', 2)
            ->assertJsonPath('data.summary.correct_count', 1)
            ->assertJsonPath('data.summary.incorrect_count', 1);

        $items = collect($response->json('data.items'))->keyBy('hadith_id');

        $this->assertSame(100, $items[$good->id]['score']);
        $this->assertTrue($items[$good->id]['is_correct']);
        $this->assertNotNull($items[$good->id]['next_review_at']);
        $this->assertFalse($items[$poor->id]['is_correct']);

        // The overall score averages the whole sitting.
        $this->assertSame(50, $response->json('data.score'));
    }

    public function test_a_hadith_left_unrecited_counts_as_zero(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->fakeGemini();

        $recited = $this->hadith();
        $skipped = $this->hadith();
        $this->due($user, $recited, '-2 days');
        $this->due($user, $skipped, '-1 day');

        $sessionId = $this->postJson('/api/v1/user/review-sessions')->json('data.id');
        $this->recite($recited, 'انما الاعمال بالنيات', $sessionId)->assertOk();

        $this->postJson("/api/v1/user/review-sessions/{$sessionId}/complete")
            ->assertOk()
            ->assertJsonPath('data.score', 50)
            ->assertJsonPath('data.summary.skipped_count', 1);
    }

    public function test_a_completed_session_cannot_be_completed_twice(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->due($user, $this->hadith());

        $sessionId = $this->postJson('/api/v1/user/review-sessions')->json('data.id');
        $this->postJson("/api/v1/user/review-sessions/{$sessionId}/complete")->assertOk();
        $this->postJson("/api/v1/user/review-sessions/{$sessionId}/complete")->assertStatus(422);
    }

    public function test_completing_a_session_lets_the_next_one_start(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->due($user, $this->hadith());

        $first = $this->postJson('/api/v1/user/review-sessions')->assertCreated()->json('data.id');
        $this->postJson("/api/v1/user/review-sessions/{$first}/complete")->assertOk();

        $this->getJson('/api/v1/user/review-sessions/current')->assertNotFound();

        $second = $this->postJson('/api/v1/user/review-sessions')->assertCreated()->json('data.id');
        $this->assertNotSame($first, $second);
    }

    public function test_a_hadith_recited_well_does_not_come_back_in_the_next_session(): void
    {
        // The reported bug: reciting a hadith with no mistakes still put it in
        // the following session, even though it was rescheduled days out.
        Sanctum::actingAs($user = User::factory()->create());
        $this->fakeGemini();

        $hadith = $this->hadith();
        $this->due($user, $hadith, '-1 day');

        $sessionId = $this->postJson('/api/v1/user/review-sessions')->json('data.id');
        $this->recite($hadith, 'انما الاعمال بالنيات', $sessionId)->assertOk();
        $this->postJson("/api/v1/user/review-sessions/{$sessionId}/complete")->assertOk();

        // It is now scheduled for a later day, so today's next sitting is empty.
        $this->assertTrue($user->hadithProgress()->first()->next_review_at->isFuture());

        $this->postJson('/api/v1/user/review-sessions')
            ->assertCreated()
            ->assertJsonPath('data.summary.total_hadiths', 0);
    }

    public function test_a_recitation_joins_the_open_session_without_being_told_its_id(): void
    {
        // The app recites without passing review_session_id. The result screen
        // was empty because nothing was ever linked to the sitting.
        Sanctum::actingAs($user = User::factory()->create());
        $this->fakeGemini();

        $hadith = $this->hadith();
        $this->due($user, $hadith, '-1 day');

        $sessionId = $this->postJson('/api/v1/user/review-sessions')->json('data.id');

        // No review_session_id in the payload.
        $this->recite($hadith, 'انما الاعمال بالنيات')->assertOk();

        $this->postJson("/api/v1/user/review-sessions/{$sessionId}/complete")
            ->assertOk()
            ->assertJsonPath('data.score', 100)
            ->assertJsonPath('data.summary.recited_count', 1)
            ->assertJsonPath('data.summary.skipped_count', 0)
            ->assertJsonPath('data.summary.correct_count', 1)
            ->assertJsonPath('data.items.0.score', 100);
    }

    public function test_a_recitation_outside_any_session_is_still_evaluated(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->fakeGemini();

        $hadith = $this->hadith();
        $this->due($user, $hadith, '-1 day');

        // No session started at all.
        $this->recite($hadith, 'انما الاعمال بالنيات')
            ->assertOk()
            ->assertJsonPath('data.score', 100);

        $this->assertDatabaseCount('review_sessions', 0);
    }

    public function test_a_session_belongs_to_its_owner_only(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->due($owner, $this->hadith());
        $sessionId = $this->postJson('/api/v1/user/review-sessions')->json('data.id');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/user/review-sessions/{$sessionId}")->assertStatus(403);
        $this->postJson("/api/v1/user/review-sessions/{$sessionId}/complete")->assertStatus(403);
    }

    public function test_review_sessions_require_authentication(): void
    {
        $this->postJson('/api/v1/user/review-sessions')->assertStatus(401);
        $this->getJson('/api/v1/user/review-sessions/current')->assertStatus(401);
    }
}
