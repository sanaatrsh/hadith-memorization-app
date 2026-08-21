<?php

namespace Tests\Feature\User;

use App\Enums\MemorizationStatus;
use App\Enums\StackSource;
use App\Models\Book;
use App\Models\Hadith;
use App\Models\User;
use App\Models\UserBook;
use App\Models\UserHadithProgress;
use App\Services\MemorizationStackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemorizationStackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function addBook(User $user, array $attributes = [], int $hadiths = 3): Book
    {
        $book = Book::factory()->create();
        UserBook::create(['user_id' => $user->id, 'book_id' => $book->id] + $attributes + ['started_at' => now()]);

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

    public function test_the_stack_starts_with_the_hadiths_of_the_most_recently_added_book(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $older = $this->addBook($user, ['started_at' => now()->subDays(5)], hadiths: 2);
        $newer = $this->addBook($user, ['started_at' => now()->subMinute()], hadiths: 2);

        $response = $this->getJson('/api/v1/user/memorization/stack')
            ->assertOk()
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.items.0.queue_reason', 'new');

        $bookIds = collect($response->json('data.items'))->pluck('hadith.book_id');

        // The book the user just added has priority.
        $this->assertSame([$newer->id, $newer->id, $older->id, $older->id], $bookIds->all());

        // Inside a book the collection's own order is kept.
        $sortOrders = collect($response->json('data.items'))->pluck('hadith.sort_order');
        $this->assertSame([1, 2, 1, 2], $sortOrders->all());
    }

    public function test_a_user_can_push_a_hadith_onto_the_top_of_the_stack(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->addBook($user, hadiths: 3);
        $last = $book->hadiths()->orderByDesc('sort_order')->first();

        $this->postJson('/api/v1/user/memorization/stack', [
            'hadith_id' => $last->id,
            'reason' => 'أحتاج مراجعته',
        ])
            ->assertCreated()
            ->assertJsonPath('data.hadith_id', $last->id)
            ->assertJsonPath('data.source', 'user');

        $this->getJson('/api/v1/user/memorization/stack')
            ->assertOk()
            ->assertJsonPath('data.pushed_count', 1)
            ->assertJsonPath('data.items.0.hadith.id', $last->id)
            ->assertJsonPath('data.items.0.queue_reason', 'pushed')
            ->assertJsonPath('data.items.0.source', 'user')
            ->assertJsonPath('data.items.0.reason', 'أحتاج مراجعته')
            ->assertJsonPath('data.items.0.position', 1);
    }

    public function test_pushing_the_same_hadith_again_moves_it_back_to_the_top_without_duplicating(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->addBook($user, hadiths: 2);
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
        $book = $this->addBook($user, hadiths: 2);
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

    public function test_pushing_requires_the_book_to_be_in_the_learning_list(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $book = Book::factory()->create();
        $hadith = Hadith::factory()->create(['book_id' => $book->id]);

        $this->postJson('/api/v1/user/memorization/stack', ['hadith_id' => $hadith->id])
            ->assertStatus(403);
    }

    public function test_a_hadith_can_be_popped_off_the_stack(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->addBook($user, hadiths: 1);
        $hadith = $book->hadiths()->first();

        $this->postJson('/api/v1/user/memorization/stack', ['hadith_id' => $hadith->id])->assertCreated();

        $this->deleteJson("/api/v1/user/memorization/stack/{$hadith->id}")->assertOk();
        $this->deleteJson("/api/v1/user/memorization/stack/{$hadith->id}")->assertStatus(404);

        $this->getJson('/api/v1/user/memorization/stack')
            ->assertOk()
            ->assertJsonPath('data.pushed_count', 0);
    }

    public function test_due_reviews_come_before_hadiths_that_were_never_started(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->addBook($user, hadiths: 3);
        $due = $book->hadiths()->orderByDesc('sort_order')->first();

        UserHadithProgress::create([
            'user_id' => $user->id,
            'hadith_id' => $due->id,
            'status' => MemorizationStatus::Reviewing->value,
            'srs_level' => 2,
            'next_review_at' => now()->subDay(),
        ]);

        $this->getJson('/api/v1/user/memorization/stack')
            ->assertOk()
            ->assertJsonPath('data.items.0.hadith.id', $due->id)
            ->assertJsonPath('data.items.0.queue_reason', 'due_review');
    }

    public function test_memorized_hadiths_leave_the_stack(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->addBook($user, hadiths: 2);
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
        $book = $this->addBook($user, hadiths: 2);
        $hadith = $book->hadiths()->orderByDesc('sort_order')->first();

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
        $book = $this->addBook($user, hadiths: 1);
        $hadith = $book->hadiths()->first();

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
        $book = $this->addBook($user, hadiths: 1);
        $hadith = $book->hadiths()->first();

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

    public function test_the_stack_is_private_to_each_user(): void
    {
        $owner = User::factory()->create();
        $book = $this->addBook($owner, hadiths: 1);
        $hadith = $book->hadiths()->first();

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
