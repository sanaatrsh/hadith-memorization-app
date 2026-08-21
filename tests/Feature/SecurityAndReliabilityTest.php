<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Hadith;
use App\Models\MemorizationAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityAndReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private function hadithForUser(User $user): Hadith
    {
        $book = Book::factory()->create();

        return Hadith::factory()->create(['book_id' => $book->id, 'text' => 'انما الاعمال بالنيات']);
    }

    public function test_gemini_500_falls_back_to_deterministic(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = $this->hadithForUser($user);
        config()->set('services.gemini.api_key', 'test-key');
        Http::fake(['*' => Http::response([], 500)]);

        $this->postJson('/api/v1/memorization/attempts', [
            'client_attempt_uuid' => (string) Str::uuid(),
            'hadith_id' => $hadith->id,
            'recognized_text' => 'انما الاعمال بالنيات',
        ])
            ->assertOk()
            ->assertJsonPath('data.ai_feedback_available', false);

        $this->assertDatabaseHas('memorization_attempts', [
            'hadith_id' => $hadith->id,
            'failure_code' => 'gemini_http_error',
        ]);
    }

    public function test_api_response_never_leaks_gemini_key(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $hadith = $this->hadithForUser($user);
        config()->set('services.gemini.api_key', 'super-secret-key');
        Http::fake(['*' => Http::response(['output_text' => 'x'], 200)]);

        $response = $this->postJson('/api/v1/memorization/attempts', [
            'client_attempt_uuid' => (string) Str::uuid(),
            'hadith_id' => $hadith->id,
            'recognized_text' => 'انما الاعمال بالنيات',
        ])->assertOk();

        $this->assertStringNotContainsString('super-secret-key', $response->getContent());
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_transcript_prune_command_clears_old_transcripts(): void
    {
        $attempt = MemorizationAttempt::create([
            'client_attempt_uuid' => (string) Str::uuid(),
            'user_id' => User::factory()->create()->id,
            'hadith_id' => Hadith::factory()->create()->id,
            'type' => 'memorization',
            'status' => 'completed',
            'recognized_text' => 'نص طويل',
            'normalized_recognized_text' => 'نص طويل',
            'normalized_reference_text' => 'نص طويل',
            'deterministic_score' => 90,
            'final_score' => 90,
            'verdict' => 'correct',
        ]);
        // Age it beyond retention.
        $attempt->forceFill(['created_at' => now()->subDays(365)])->save();

        config()->set('athar.attempts.transcript_retention_days', 180);
        $this->artisan('attempts:prune-transcripts')->assertSuccessful();

        $attempt->refresh();
        $this->assertSame('', $attempt->recognized_text);
        $this->assertSame(90, $attempt->final_score); // score preserved
    }
}
