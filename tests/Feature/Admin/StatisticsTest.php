<?php

namespace Tests\Feature\Admin;

use App\Enums\MemorizationStatus;
use App\Models\Hadith;
use App\Models\User;
use App\Models\UserHadithProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_statistics_are_returned(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        User::factory(3)->create();

        $this->getJson('/api/v1/admin/statistics/overview')
            ->assertOk()
            ->assertJsonStructure(['data' => ['total_users', 'active_users', 'total_attempts', 'average_score', 'reviews_due', 'gemini' => ['failure_rate', 'average_latency_ms']]]);
    }

    public function test_memorization_statistics_derive_from_progress(): void
    {
        Sanctum::actingAs($admin = User::factory()->admin()->create());
        $hadith = Hadith::factory()->create();
        UserHadithProgress::factory()->create([
            'user_id' => $admin->id,
            'hadith_id' => $hadith->id,
            'status' => MemorizationStatus::Memorized,
        ]);

        $this->getJson('/api/v1/admin/statistics/memorization')
            ->assertOk()
            ->assertJsonPath('data.completed_hadiths', 1)
            ->assertJsonStructure(['data' => ['users_memorizing', 'attempts_by_date', 'most_difficult_hadiths']]);
    }

    public function test_user_statistics_are_returned(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/statistics/users')
            ->assertOk()
            ->assertJsonStructure(['data' => ['total_users', 'active_users', 'by_role', 'top_learners']]);
    }

    public function test_non_admin_cannot_view_statistics(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/statistics/overview')->assertStatus(403);
    }
}
