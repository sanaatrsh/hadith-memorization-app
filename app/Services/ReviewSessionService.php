<?php

namespace App\Services;

use App\Models\Hadith;
use App\Models\MemorizationAttempt;
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
 * "Due today" means anything scheduled up to the end of today — a hadith set
 * for later this evening belongs in today's session, and one that fell due last
 * week is overdue and belongs too. Hadiths scheduled for a future day are not
 * in it, which is the whole point: a hadith recited well is scheduled forward
 * and must not come back in the next session.
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

        $hadiths = $this->dueToday($user);

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
     * The hadiths whose review is due by the end of today, earliest first.
     *
     * @return Collection<int,Hadith>
     */
    public function dueToday(User $user): Collection
    {
        $dueIds = UserHadithProgress::where('user_id', $user->id)
            ->whereNotNull('next_review_at')
            ->where('next_review_at', '<=', Carbon::now()->endOfDay())
            ->orderBy('next_review_at')
            ->pluck('hadith_id');

        if ($dueIds->isEmpty()) {
            return new Collection;
        }

        $hadiths = Hadith::whereIn('id', $dueIds)
            ->where('is_active', true)
            ->with(['book', 'narrator'])
            ->get()
            ->keyBy('id');

        return $dueIds
            ->map(fn (int $id) => $hadiths->get($id))
            ->filter()
            ->values();
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
