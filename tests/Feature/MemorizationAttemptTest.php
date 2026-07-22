<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Hadith;
use App\Models\User;
use App\Models\UserBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemorizationAttemptTest extends TestCase
{
    use RefreshDatabase;

    private function makeHadithForUser(User $user, string $text = 'انما الاعمال بالنيات'): Hadith
    {
        $book = Book::factory()->create();
        UserBook::create(['user_id' => $user->id, 'book_id' => $book->id, 'started_at' => now()]);

        return Hadith::factory()->create(['book_id' => $book->id, 'text' => $text]);
    }

    private function fakeValidGemini(int $score = 86): void
    {
        config()->set('services.gemini.api_key', 'test-key');

        Http::fake([
            '*' => Http::response([
                'id' => 'int_1',
                'output_text' => json_encode([
                    'is_textually_correct' => false,
                    'score' => $score,
                    'verdict' => 'needs_review',
                    'missing_words' => ['إنما'],
                    'extra_words' => [],
                    'incorrect_segments' => [],
                    'feedback_ar' => 'أعد المقطع الأول',
                    'recommended_action' => 'review_later',
                ], JSON_UNESCAPED_UNICODE),
            ], 200),
        ]);
    }

    private function payload(Hadith $hadith, string $recognized = 'انما الاعمال بالنيات', ?string $uuid = null): array
    {
        return [
            'client_attempt_uuid' => $uuid ?? (string) Str::uuid(),
            'hadith_id' => $hadith->id,
            'recognized_text' => $recognized,
            'locale' => 'ar',
        ];
    }

    public function test_user_can_submit_attempt_and_receive_report(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = $this->makeHadithForUser($user);
        $this->fakeValidGemini();

        $this->postJson('/api/v1/memorization/attempts', $this->payload($hadith))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.score', 100) // deterministic authority: exact match
            ->assertJsonPath('data.is_correct', true)
            ->assertJsonStructure(['data' => ['attempt_id', 'verdict', 'feedback', 'progress_status', 'next_review_at']]);

        $this->assertDatabaseCount('memorization_attempts', 1);
        $this->assertDatabaseHas('user_hadith_progress', ['user_id' => $user->id, 'hadith_id' => $hadith->id]);
    }

    public function test_deterministic_score_is_authority_not_ai_score(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = $this->makeHadithForUser($user);
        $this->fakeValidGemini(score: 40); // AI disagrees

        $this->postJson('/api/v1/memorization/attempts', $this->payload($hadith))
            ->assertOk()
            ->assertJsonPath('data.score', 100); // final score = deterministic, not AI's 40
    }

    public function test_duplicate_uuid_does_not_create_duplicate_attempt(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = $this->makeHadithForUser($user);
        $this->fakeValidGemini();
        $uuid = (string) Str::uuid();

        $this->postJson('/api/v1/memorization/attempts', $this->payload($hadith, uuid: $uuid))->assertOk();
        $this->postJson('/api/v1/memorization/attempts', $this->payload($hadith, uuid: $uuid))->assertOk();

        $this->assertDatabaseCount('memorization_attempts', 1);
    }

    public function test_user_without_selected_book_is_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = Book::factory()->create();
        $hadith = Hadith::factory()->create(['book_id' => $book->id]);
        $this->fakeValidGemini();

        $this->postJson('/api/v1/memorization/attempts', $this->payload($hadith))->assertStatus(403);
    }

    public function test_gemini_timeout_falls_back_to_deterministic(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = $this->makeHadithForUser($user);
        config()->set('services.gemini.api_key', 'test-key');
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->postJson('/api/v1/memorization/attempts', $this->payload($hadith))
            ->assertOk()
            ->assertJsonPath('data.ai_feedback_available', false)
            ->assertJsonPath('data.score', 100);

        $this->assertDatabaseHas('memorization_attempts', ['hadith_id' => $hadith->id, 'failure_code' => 'gemini_timeout']);
    }

    public function test_gemini_rate_limit_falls_back_to_deterministic(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = $this->makeHadithForUser($user);
        config()->set('services.gemini.api_key', 'test-key');
        Http::fake(['*' => Http::response([], 429)]);

        $this->postJson('/api/v1/memorization/attempts', $this->payload($hadith))
            ->assertOk()
            ->assertJsonPath('data.ai_feedback_available', false);

        $this->assertDatabaseHas('memorization_attempts', ['hadith_id' => $hadith->id, 'failure_code' => 'gemini_rate_limited']);
    }

    public function test_invalid_gemini_json_falls_back_to_deterministic(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = $this->makeHadithForUser($user);
        config()->set('services.gemini.api_key', 'test-key');
        Http::fake(['*' => Http::response(['output_text' => 'not-json'], 200)]);

        $this->postJson('/api/v1/memorization/attempts', $this->payload($hadith))
            ->assertOk()
            ->assertJsonPath('data.ai_feedback_available', false);

        $this->assertDatabaseHas('memorization_attempts', ['hadith_id' => $hadith->id, 'failure_code' => 'invalid_ai_json']);
    }

    public function test_request_uses_x_goog_api_key_header(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = $this->makeHadithForUser($user);
        $this->fakeValidGemini();

        $this->postJson('/api/v1/memorization/attempts', $this->payload($hadith))->assertOk();

        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'test-key')
            && ! $request->hasHeader('Authorization'));
    }

    public function test_invalid_transcript_returns_422(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = $this->makeHadithForUser($user);

        $this->postJson('/api/v1/memorization/attempts', [
            'client_attempt_uuid' => 'not-a-uuid',
            'hadith_id' => $hadith->id,
        ])->assertStatus(422);
    }
}
