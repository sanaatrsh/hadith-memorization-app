<?php

namespace App\Services;

use App\Enums\MemorizationStatus;
use App\Models\Hadith;
use App\Models\MemorizationAttempt;
use App\Models\MemorizationStackItem;
use App\Models\ReviewSession;
use App\Models\ReviewSessionItem;
use App\Models\User;
use App\Models\UserHadithProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One review sitting: the hadiths whose review falls due today, and the result
 * of working through them.
 *
 * The sitting holds two things and nothing else:
 *
 *   1. What is genuinely due — anything scheduled up to the end of today, so a
 *      hadith set for this evening belongs in it and one that fell due last
 *      week is overdue and belongs too.
 *   2. What the learner started working on today or yesterday and has not
 *      recited yet — the new material of the last couple of days.
 *
 * A hadith already scheduled into a future day is in neither: reciting one well
 * books it forward, and pulling it back into the very next sitting is exactly
 * the behaviour this avoids. That also keeps the sitting short — it is the work
 * for today, not the backlog of everything ever touched.
 */
class ReviewSessionService
{
    /**
     * The session in progress, or null.
     */
    public function current(User $user): ?ReviewSession
    {
        return ReviewSession::where('user_id', $user->id)
            ->where('status', ReviewSession::STATUS_IN_PROGRESS)
            ->latest('id')
            ->first();
    }

    /**
     * Start a session over today's due hadiths. A session already in progress
     * is returned as-is rather than replaced, so a retry from the app does not
     * discard work.
     */
    public function start(User $user): ReviewSession
    {
        if ($existing = $this->current($user)) {
            return $existing->load('items.hadith');
        }

        $hadiths = $this->sessionHadiths($user);

        return DB::transaction(function () use ($user, $hadiths) {
            $session = ReviewSession::create([
                'user_id' => $user->id,
                'status' => ReviewSession::STATUS_IN_PROGRESS,
                'started_at' => Carbon::now(),
            ]);

            foreach ($hadiths->values() as $index => $hadith) {
                $session->items()->create([
                    'hadith_id' => $hadith->id,
                    'sort_order' => $index,
                ]);
            }

            return $session->load('items.hadith');
        });
    }

    /**
     * Everything the sitting should contain: what is due, then the recent
     * material not yet recited. Due hadiths lead, earliest first.
     *
     * @return Collection<int,Hadith>
     */
    public function sessionHadiths(User $user): Collection
    {
        $ids = $this->dueHadithIds($user)
            ->concat($this->recentlyStartedHadithIds($user))
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return new Collection;
        }

        $hadiths = Hadith::whereIn('id', $ids)
            ->where('is_active', true)
            ->with(['book', 'narrator'])
            ->get()
            ->keyBy('id');

        return $ids
            ->map(fn (int $id) => $hadiths->get($id))
            ->filter()
            ->values();
    }

    /**
     * Hadiths whose review date has arrived, earliest first.
     *
     * @return Collection<int,int>
     */
    private function dueHadithIds(User $user): Collection
    {
        return UserHadithProgress::where('user_id', $user->id)
            ->whereNotNull('next_review_at')
            ->where('next_review_at', '<=', Carbon::now()->endOfDay())
            ->orderBy('next_review_at')
            ->pluck('hadith_id');
    }

    /**
     * Hadiths the learner started working on in the last couple of days —
     * pushed onto the stack or given a progress record — and has not recited
     * yet. Anything already booked for a future day is left out, so a hadith
     * recited well today does not come straight back this evening.
     *
     * @return Collection<int,int>
     */
    private function recentlyStartedHadithIds(User $user): Collection
    {
        $days = max((int) config('athar.reviews.recent_days', 2), 1);
        $since = Carbon::today()->subDays($days - 1)->startOfDay();

        $fromProgress = UserHadithProgress::where('user_id', $user->id)
            ->where('created_at', '>=', $since)
            ->where('status', '!=', MemorizationStatus::Memorized->value)
            ->where(function ($q) {
                $q->whereNull('next_review_at')
                    ->orWhere('next_review_at', '<=', Carbon::now()->endOfDay());
            })
            ->orderByDesc('created_at')
            ->pluck('hadith_id');

        $scheduledAhead = UserHadithProgress::where('user_id', $user->id)
            ->where(function ($q) {
                $q->where('status', MemorizationStatus::Memorized->value)
                    ->orWhere('next_review_at', '>', Carbon::now()->endOfDay());
            })
            ->pluck('hadith_id')
            ->all();

        $fromStack = MemorizationStackItem::where('user_id', $user->id)
            ->whereNull('resolved_at')
            ->where('pushed_at', '>=', $since)
            ->when($scheduledAhead !== [], fn ($q) => $q->whereNotIn('hadith_id', $scheduledAhead))
            ->orderByDesc('pushed_at')
            ->pluck('hadith_id');

        return $fromProgress->concat($fromStack)->unique()->values();
    }

    /**
     * Attach a recitation to the session item for that hadith, if the hadith is
     * part of the session. Returns false when it is not.
     */
    public function recordAttempt(ReviewSession $session, MemorizationAttempt $attempt): bool
    {
        $item = $session->items()->where('hadith_id', $attempt->hadith_id)->first();

        if ($item === null) {
            return false;
        }

        $item->update(['memorization_attempt_id' => $attempt->id]);

        return true;
    }

    /**
     * Finish the session and store its overall score: the average over every
     * hadith in it, with anything left unrecited counting as zero, so the score
     * reflects the whole sitting rather than only the parts attempted.
     */
    public function complete(ReviewSession $session): ReviewSession
    {
        $session->loadMissing('items.attempt');

        $total = max($session->items->count(), 1);
        $sum = $session->items->sum(fn (ReviewSessionItem $item) => (int) ($item->attempt?->final_score ?? 0));

        $session->update([
            'status' => ReviewSession::STATUS_COMPLETED,
            'completed_at' => Carbon::now(),
            'score' => (int) round($sum / $total),
        ]);

        return $session->load(['items.hadith', 'items.attempt']);
    }

    /**
     * @return array<string,int>
     */
    public function summary(ReviewSession $session): array
    {
        $session->loadMissing('items.attempt');

        $passing = (int) config('athar.scoring.passing');
        $answered = $session->items->filter(fn (ReviewSessionItem $item) => $item->attempt !== null);

        return [
            'total_hadiths' => $session->items->count(),
            'recited_count' => $answered->count(),
            'skipped_count' => max($session->items->count() - $answered->count(), 0),
            'correct_count' => $answered->filter(fn ($i) => (int) $i->attempt->final_score >= $passing)->count(),
            'incorrect_count' => $answered->filter(fn ($i) => (int) $i->attempt->final_score < $passing)->count(),
            'passing_score' => $passing,
        ];
    }
}
