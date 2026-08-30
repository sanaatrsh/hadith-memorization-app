<?php

namespace Tests\Feature\User;

use App\Enums\MemorizationStatus;
use App\Enums\StackSource;
use App\Models\Book;
use App\Models\Hadith;
use App\Models\User;
use App\Models\UserHadithProgress;
use App\Services\MemorizationStackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The stack never depends on a book being "selected": every active book is open
 * to every user, and a book joins the queue as soon as the user pushes a hadith
 * of it or recites one.
 */
class MemorizationStackTest extends TestCase
{
    use RefreshDatabase;

    private function makeBook(int $hadiths = 3): Book
    {
        $book = Book::factory()->create();

        Hadith::factory($hadiths)
            ->sequence(fn ($sequence) => ['sort_order' => $sequence->index + 1])
            ->create(['book_id' => $book->id, 'text' => 'انما الاعمال بالنيات']);

        return $book;
    }

    private function fakeGemini(string $action = 'continue', string $verdict = 'correct'): void
    {
        config()->set('services.gemini.api_key', 'test-key');

        Http::fake([
            '*' => Http::response([
                'id' => 'int_1',
                'output_text' => json_encode([
                    'is_textually_correct' => $verdict === 'correct',
                    'score' => 95,
                    'verdict' => $verdict,
                    'missing_words' => [],
                    'extra_words' => [],
                    'incorrect_segments' => [],
                    'feedback_ar' => 'تسميع جيد',
                    'recommended_action' => $action,
                ], JSON_UNESCAPED_UNICODE),
            ], 200),
        ]);
    }

    public function test_any_active_hadith_can_be_pushed_without_selecting_a_book(): void
    {
        Sanctum::actingAs(User::factory()->create());

        // No learning list, no book selection — just a hadith from the catalogue.
        $hadith = $this->makeBook(hadiths: 2)->hadiths()->first();

        $this->postJson('/api/v1/user/memorization/stack', ['hadith_id' => $hadith->id])
            ->assertCreated()
            ->assertJsonPath('data.hadith_id', $hadith->id)
            ->assertJsonPath('data.source', 'user');

        $this->getJson('/api/v1/user/memorization/stack')
            ->assertOk()
            ->assertJsonPath('data.pushed_count', 1)
            ->assertJsonPath('data.items.0.hadith.id', $hadith->id);
    }

    public function test_an_inactive_hadith_cannot_be_pushed(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $hadith = Hadith::factory()->inactive()->create();

        $this->postJson('/api/v1/user/memorization/stack', ['hadith_id' => $hadith->id])
            ->assertStatus(422);
    }

    public function test_the_stack_is_empty_until_the_user_works_on_something(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->makeBook(hadiths: 5);

        // The catalogue is not dumped into the queue on its own.
        $this->getJson('/api/v1/user/memorization/stack')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.items', []);
    }

    public function test_the_daily_list_holds_the_pushed_hadith_and_not_the_rest_of_its_book(): void
    {
        // The list is a day's work, not a catalogue: pushing one hadith does
        // not drag in every other hadith of its book to pad out the limit.
        Sanctum::actingAs(User::factory()->create());
        $book = $this->makeBook(hadiths: 3);
        $pushed = $book->hadiths()->orderByDesc('sort_order')->first();

        $this->postJson('/api/v1/user/memorization/stack', ['hadith_id' => $pushed->id])->assertCreated();

        $this->getJson('/api/v1/user/memorization/stack')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.hadith.id', $pushed->id)
            ->assertJsonPath('data.items.0.queue_reason', 'pushed');
    }

    public function test_browsing_one_book_still_offers_that_book_s_hadiths(): void
    {
        // Scoping to a book is the browse case, so it may offer material the
        // learner has not started — the daily list may not.
        Sanctum::actingAs(User::factory()->create());
        $book = $this->makeBook(hadiths: 3);

        $this->getJson("/api/v1/user/memorization/stack?book_id={$book->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.items.0.queue_reason', 'new');

        // Without the book, nothing has been started, so there is no work.
        $this->getJson('/api/v1/user/memorization/stack')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_the_queue_can_be_scoped_to_one_book(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $wanted = $this->makeBook(hadiths: 2);
        $other = $this->makeBook(hadiths: 2);

        $this->postJson('/api/v1/user/memorization/stack', [
            'hadith_id' => $other->hadiths()->first()->id,
        ])->assertCreated();

        $response = $this->getJson("/api/v1/user/memorization/stack?book_id={$wanted->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        $bookIds = collect($response->json('data.items'))->pluck('hadith.book_id')->unique();

        $this->assertSame([$wanted->id], $bookIds->values()->all());
    }

    public function test_pushing_the_same_hadith_again_moves_it_back_to_the_top_without_duplicating(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->makeBook(hadiths: 2);
        [$first, $second] = $book->hadiths()->orderBy('sort_order')->get()->all();

        $this->postJson('/api/v1/user/memorization/stack', ['hadith_id' => $first->id])->assertCreated();
        $this->travel(1)->minutes();
        $this->postJson('/api/v1/user/memorization/stack', ['hadith_id' => $second->id])->assertCreated();
        $this->travel(1)->minutes();
        $this->postJson('/api/v1/user/memorization/stack', ['hadith_id' => $first->id])->assertCreated();

        $this->assertDatabaseCount('memorization_stack_items', 2);

        $this->getJson('/api/v1/user/memorization/stack')
            ->assertOk()
            ->assertJsonPath('data.pushed_count', 2)
            ->assertJsonPath('data.items.0.hadith.id', $first->id)
            ->assertJsonPath('data.items.1.hadith.id', $second->id);
    }

    public function test_user_requests_sit_above_the_ones_the_ai_flagged(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->makeBook(hadiths: 2);
        [$flagged, $requested] = $book->hadiths()->orderBy('sort_order')->get()->all();

        // The evaluator flags one hadith...
        app(MemorizationStackService::class)->push(
            $user,
            $flagged,
            StackSource::Ai,
            'يحتاج مراجعة',
        );

        $this->travel(1)->minutes();

        // ...and the user asks for another one, earlier in the book.
        $this->postJson('/api/v1/user/memorization/stack', ['hadith_id' => $requested->id])->assertCreated();

        $this->getJson('/api/v1/user/memorization/stack')
            ->assertOk()
            ->assertJsonPath('data.items.0.hadith.id', $requested->id)
            ->assertJsonPath('data.items.0.source', 'user')
            ->assertJsonPath('data.items.1.hadith.id', $flagged->id)
            ->assertJsonPath('data.items.1.source', 'ai');
    }

    public function test_a_hadith_can_be_popped_off_the_stack(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $hadith = $this->makeBook(hadiths: 1)->hadiths()->first();

        $this->postJson('/api/v1/user/memorization/stack', ['hadith_id' => $hadith->id])->assertCreated();

        $this->deleteJson("/api/v1/user/memorization/stack/{$hadith->id}")->assertOk();
        $this->deleteJson("/api/v1/user/memorization/stack/{$hadith->id}")->assertStatus(404);

        $this->getJson('/api/v1/user/memorization/stack')
            ->assertOk()
            ->assertJsonPath('data.pushed_count', 0);
    }

    public function test_due_reviews_come_before_recently_started_hadiths(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->makeBook(hadiths: 3);
        $due = $book->hadiths()->orderByDesc('sort_order')->first();

        UserHadithProgress::create([
            'user_id' => $user->id,
            'hadith_id' => $due->id,
            'status' => MemorizationStatus::Reviewing->value,
            'srs_level' => 2,
            'next_review_at' => now()->subDay(),
        ]);

        // A second hadith started today but never recited: recent material,
        // which sits behind what is actually due.
        $recent = $book->hadiths()->orderBy('sort_order')->first();
        UserHadithProgress::create([
            'user_id' => $user->id,
            'hadith_id' => $recent->id,
            'status' => MemorizationStatus::Memorizing->value,
            'srs_level' => 0,
            'next_review_at' => null,
        ]);

        $this->getJson('/api/v1/user/memorization/stack')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.items.0.hadith.id', $due->id)
            ->assertJsonPath('data.items.0.queue_reason', 'due_review')
            ->assertJsonPath('data.items.1.hadith.id', $recent->id)
            ->assertJsonPath('data.items.1.queue_reason', 'new');
    }

    public function test_memorized_hadiths_leave_the_stack(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->makeBook(hadiths: 2);
        $memorized = $book->hadiths()->orderBy('sort_order')->first();

        UserHadithProgress::create([
            'user_id' => $user->id,
            'hadith_id' => $memorized->id,
            'status' => MemorizationStatus::Memorized->value,
            'srs_level' => 4,
            'memorized_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/user/memorization/stack')->assertOk();

        $this->assertNotContains(
            $memorized->id,
            collect($response->json('data.items'))->pluck('hadith.id')->all(),
        );
    }

    public function test_a_weak_recitation_pushes_the_hadith_back_onto_the_stack(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->fakeGemini();
        $hadith = $this->makeBook(hadiths: 2)->hadiths()->orderByDesc('sort_order')->first();

        $this->postJson('/api/v1/memorization/attempts', [
            'client_attempt_uuid' => (string) Str::uuid(),
            'hadith_id' => $hadith->id,
            'recognized_text' => 'كلمة خاطئة تماما',
            'locale' => 'ar',
        ])->assertOk();

        $this->assertDatabaseHas('memorization_stack_items', [
            'user_id' => $user->id,
            'hadith_id' => $hadith->id,
            'source' => StackSource::Evaluation->value,
            'resolved_at' => null,
        ]);

        $this->getJson('/api/v1/user/memorization/stack')
            ->assertOk()
            ->assertJsonPath('data.items.0.hadith.id', $hadith->id)
            ->assertJsonPath('data.items.0.queue_reason', 'pushed')
            ->assertJsonPath('data.items.0.source', 'evaluation');
    }

    public function test_a_gemini_review_recommendation_pushes_a_passing_recitation_back(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        // Gemini asks for the hadith to be repeated even though the recitation
        // was exact.
        $this->fakeGemini(action: 'repeat_now', verdict: 'needs_review');
        $hadith = $this->makeBook(hadiths: 1)->hadiths()->first();

        $this->postJson('/api/v1/memorization/attempts', [
            'client_attempt_uuid' => (string) Str::uuid(),
            'hadith_id' => $hadith->id,
            'recognized_text' => 'انما الاعمال بالنيات',
            'locale' => 'ar',
        ])->assertOk();

        $this->assertDatabaseHas('memorization_stack_items', [
            'user_id' => $user->id,
            'hadith_id' => $hadith->id,
            'source' => StackSource::Ai->value,
            'resolved_at' => null,
        ]);
    }

    public function test_a_passing_recitation_pops_the_hadith_off_the_stack(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->fakeGemini();
        $hadith = $this->makeBook(hadiths: 1)->hadiths()->first();

        $this->postJson('/api/v1/user/memorization/stack', ['hadith_id' => $hadith->id])->assertCreated();

        $this->postJson('/api/v1/memorization/attempts', [
            'client_attempt_uuid' => (string) Str::uuid(),
            'hadith_id' => $hadith->id,
            'recognized_text' => 'انما الاعمال بالنيات',
            'locale' => 'ar',
        ])->assertOk();

        $this->assertDatabaseMissing('memorization_stack_items', [
            'user_id' => $user->id,
            'hadith_id' => $hadith->id,
            'resolved_at' => null,
        ]);
    }

    public function test_a_hadith_already_scheduled_for_a_later_day_is_not_offered_as_new(): void
    {
        // Reciting a hadith well books it for a later day. It used to come back
        // here as "new" work, which is how a clean recitation reappeared in the
        // next session.
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->makeBook(hadiths: 2);
        [$scheduled, $stillPending] = $book->hadiths()->orderBy('sort_order')->get()->all();

        // Started today and recited well: booked three days out.
        UserHadithProgress::create([
            'user_id' => $user->id,
            'hadith_id' => $scheduled->id,
            'status' => MemorizationStatus::Reviewing->value,
            'srs_level' => 2,
            'next_review_at' => now()->addDays(3),
        ]);

        // Started today and not recited yet: still this day's work.
        UserHadithProgress::create([
            'user_id' => $user->id,
            'hadith_id' => $stillPending->id,
            'status' => MemorizationStatus::Memorizing->value,
            'srs_level' => 0,
            'next_review_at' => null,
        ]);

        $response = $this->getJson('/api/v1/user/memorization/stack')->assertOk();
        $ids = collect($response->json('data.items'))->pluck('hadith.id');

        $this->assertNotContains($scheduled->id, $ids->all());
        $this->assertContains($stillPending->id, $ids->all());
    }

    public function test_a_hadith_whose_review_date_has_arrived_is_still_offered(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = $this->makeBook(hadiths: 1)->hadiths()->first();

        UserHadithProgress::create([
            'user_id' => $user->id,
            'hadith_id' => $hadith->id,
            'status' => MemorizationStatus::Reviewing->value,
            'srs_level' => 1,
            'next_review_at' => now()->subHour(),
        ]);

        $this->getJson('/api/v1/user/memorization/stack')
            ->assertOk()
            ->assertJsonPath('data.items.0.hadith.id', $hadith->id)
            ->assertJsonPath('data.items.0.queue_reason', 'due_review');
    }

    public function test_the_stack_is_private_to_each_user(): void
    {
        $owner = User::factory()->create();
        $hadith = $this->makeBook(hadiths: 1)->hadiths()->first();

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/user/memorization/stack', ['hadith_id' => $hadith->id])->assertCreated();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/user/memorization/stack')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_the_stack_requires_authentication(): void
    {
        $this->getJson('/api/v1/user/memorization/stack')->assertStatus(401);
        $this->postJson('/api/v1/user/memorization/stack', ['hadith_id' => 1])->assertStatus(401);
    }
}
