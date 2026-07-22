<?php

namespace Tests\Feature\User;

use App\Enums\MemorizationStatus;
use App\Models\Book;
use App\Models\Hadith;
use App\Models\User;
use App\Models\UserHadithProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LearningSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_select_multiple_books(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $bookA = Book::factory()->create();
        $bookB = Book::factory()->create();

        $this->postJson("/api/v1/user/books/{$bookA->id}/start")->assertCreated();
        $this->postJson("/api/v1/user/books/{$bookB->id}/start")->assertCreated();

        $this->assertDatabaseCount('user_books', 2);
        $this->getJson('/api/v1/user/books')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_starting_same_book_twice_is_idempotent(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = Book::factory()->create();

        $this->postJson("/api/v1/user/books/{$book->id}/start")->assertCreated();
        $this->postJson("/api/v1/user/books/{$book->id}/start")->assertOk();

        $this->assertDatabaseCount('user_books', 1);
    }

    public function test_removing_book_does_not_delete_progress_history(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = Book::factory()->create();
        $hadith = Hadith::factory()->create(['book_id' => $book->id]);
        UserHadithProgress::factory()->create(['user_id' => $user->id, 'hadith_id' => $hadith->id]);

        $this->postJson("/api/v1/user/books/{$book->id}/start")->assertCreated();
        $this->deleteJson("/api/v1/user/books/{$book->id}")->assertOk();

        $this->assertDatabaseMissing('user_books', ['user_id' => $user->id, 'book_id' => $book->id]);
        // Progress history is preserved.
        $this->assertDatabaseHas('user_hadith_progress', ['user_id' => $user->id, 'hadith_id' => $hadith->id]);
    }

    public function test_dashboard_aggregates_progress_counts(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = Book::factory()->create();
        $hadiths = Hadith::factory(4)->create(['book_id' => $book->id]);

        $this->postJson("/api/v1/user/books/{$book->id}/start")->assertCreated();

        UserHadithProgress::factory()->create([
            'user_id' => $user->id,
            'hadith_id' => $hadiths[0]->id,
            'status' => MemorizationStatus::Memorized,
        ]);

        $this->getJson('/api/v1/user/progress')
            ->assertOk()
            ->assertJsonPath('data.total_hadiths', 4)
            ->assertJsonPath('data.memorized', 1)
            ->assertJsonPath('data.not_started', 3);
    }

    public function test_due_reviews_are_calculated_from_next_review_at(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = Hadith::factory()->create();
        UserHadithProgress::factory()->dueForReview()->create([
            'user_id' => $user->id,
            'hadith_id' => $hadith->id,
        ]);
        // Not due yet.
        UserHadithProgress::factory()->create([
            'user_id' => $user->id,
            'hadith_id' => Hadith::factory()->create()->id,
            'next_review_at' => now()->addDays(5),
        ]);

        $this->getJson('/api/v1/user/reviews/due')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_learning_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/user/progress')->assertUnauthorized();
    }
}
