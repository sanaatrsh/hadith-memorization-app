<?php

namespace Tests\Unit;

use App\Enums\MemorizationStatus;
use App\Models\UserHadithProgress;
use App\Services\SpacedRepetitionService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SpacedRepetitionServiceTest extends TestCase
{
    private SpacedRepetitionService $srs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->srs = new SpacedRepetitionService;
    }

    private function progress(int $level, MemorizationStatus $status = MemorizationStatus::Memorizing): UserHadithProgress
    {
        $p = new UserHadithProgress(['srs_level' => $level, 'status' => $status]);
        $p->exists = true;

        return $p;
    }

    public function test_passing_score_advances_one_level(): void
    {
        $now = Carbon::parse('2026-07-22T08:00:00Z');
        $result = $this->srs->transition($this->progress(1), 95, $now);

        $this->assertSame(2, $result['srs_level']);
        $this->assertSame(MemorizationStatus::Reviewing, $result['status']);
        // level 2 => 7 days.
        $this->assertSame('2026-07-29', $result['next_review_at']->toDateString());
    }

    public function test_acceptable_score_keeps_same_level(): void
    {
        $result = $this->srs->transition($this->progress(2), 80, Carbon::now());

        $this->assertSame(2, $result['srs_level']);
    }

    public function test_failing_score_moves_back_a_level(): void
    {
        $result = $this->srs->transition($this->progress(3), 60, Carbon::now());

        $this->assertSame(2, $result['srs_level']);
    }

    public function test_level_never_goes_below_zero(): void
    {
        $result = $this->srs->transition($this->progress(0), 10, Carbon::now());

        $this->assertSame(0, $result['srs_level']);
        $this->assertSame(MemorizationStatus::Memorizing, $result['status']);
    }

    public function test_passing_at_max_level_marks_memorized(): void
    {
        $result = $this->srs->transition($this->progress(4), 100, Carbon::now());

        $this->assertTrue($result['memorized']);
        $this->assertSame(MemorizationStatus::Memorized, $result['status']);
        $this->assertNull($result['next_review_at']);
    }

    public function test_first_attempt_with_null_progress(): void
    {
        $now = Carbon::parse('2026-07-22T08:00:00Z');
        $result = $this->srs->transition(null, 95, $now);

        $this->assertSame(1, $result['srs_level']);
        // level 1 => 3 days.
        $this->assertSame('2026-07-25', $result['next_review_at']->toDateString());
    }
}
