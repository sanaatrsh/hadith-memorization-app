<?php

namespace App\Services;

use App\Enums\MemorizationStatus;
use App\Enums\StackSource;
use App\Models\Hadith;
use App\Models\MemorizationStackItem;
use App\Models\User;
use App\Models\UserHadithProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the user's memorization queue — the "stack" the app reads from.
 *
 * The queue is layered, top first:
 *
 *  1. Pushed items (LIFO): hadiths explicitly put on the stack, the user's own
 *     requests before Gemini's / the evaluator's, newest push first.
 *  2. Reviews that are due now, earliest due date first.
 *  3. Hadiths the user started working on in the last couple of days and has
 *     not recited yet — this couple of days' new material.
 *
 * This is a day's work, not a catalogue. A hadith whose review is scheduled for
 * a later day is in none of the three layers, so the list is as long as the
 * work actually waiting rather than being padded out to the requested limit.
 *
 * Passing a book changes layer 3 only: browsing a book the learner has not
 * started should still offer its hadiths, so that case lists the book's
 * unscheduled hadiths in the book's own order.
 */
class MemorizationStackService
{
    public const REASON_PUSHED = 'pushed';

    public const REASON_DUE_REVIEW = 'due_review';

    public const REASON_NEW = 'new';

    /**
     * Push a hadith onto the top of the stack (idempotent per hadith: pushing
     * again just moves it back to the top).
     */
    public function push(
        User $user,
        Hadith $hadith,
        StackSource $source = StackSource::User,
        ?string $reason = null,
        ?int $triggerScore = null,
    ): MemorizationStackItem {
        $item = MemorizationStackItem::firstOrNew([
            'user_id' => $user->id,
            'hadith_id' => $hadith->id,
        ]);

        // A user request outranks an automatic one, so it is never downgraded
        // by a later evaluation push while it is still pending.
        $keepSource = $item->exists
            && $item->resolved_at === null
            && $item->source === StackSource::User
            && $source !== StackSource::User;

        $item->fill([
            'source' => $keepSource ? $item->source : $source,
            'reason' => $reason ?? ($keepSource ? $item->reason : null),
            'trigger_score' => $triggerScore,
            'pushed_at' => Carbon::now(),
            'resolved_at' => null,
        ]);

        $item->save();

        return $item;
    }

    /**
     * Pop a hadith off the stack. Returns false when it was not on it.
     */
    public function resolve(User $user, Hadith $hadith): bool
    {
        $item = MemorizationStackItem::where('user_id', $user->id)
            ->where('hadith_id', $hadith->id)
            ->whereNull('resolved_at')
            ->first();

        if ($item === null) {
            return false;
        }

        $item->update(['resolved_at' => Carbon::now()]);

        return true;
    }

    /**
     * The ordered queue of hadiths the user should memorize next. Passing a
     * book narrows every layer to that book.
     *
     * @return Collection<int,array{hadith:Hadith, progress:?UserHadithProgress, stack_item:?MemorizationStackItem, queue_reason:string, position:int}>
     */
    public function queue(User $user, int $limit = 20, ?int $bookId = null): Collection
    {
        $progress = UserHadithProgress::where('user_id', $user->id)
            ->get()
            ->keyBy('hadith_id');

        $entries = new Collection;
        $seen = [];

        foreach ($this->pushedItems($user, $bookId) as $item) {
            if ($item->hadith === null || ! $item->hadith->is_active) {
                continue;
            }

            $seen[$item->hadith_id] = true;
            $entries->push([
                'hadith' => $item->hadith,
                'progress' => $progress->get($item->hadith_id),
                'stack_item' => $item,
                'queue_reason' => self::REASON_PUSHED,
            ]);
        }

        if ($entries->count() < $limit) {
            foreach ($this->dueReviews($user, $seen, $bookId) as $hadith) {
                $seen[$hadith->id] = true;
                $entries->push([
                    'hadith' => $hadith,
                    'progress' => $progress->get($hadith->id),
                    'stack_item' => null,
                    'queue_reason' => self::REASON_DUE_REVIEW,
                ]);
            }
        }

        if ($entries->count() < $limit) {
            $remaining = $limit - $entries->count();

            // Browsing one book offers that book's material; the daily list
            // offers only what the learner has actually started.
            $newMaterial = $bookId !== null
                ? $this->unmemorized($user, $remaining, $seen, $bookId)
                : $this->recentlyStarted($user, $remaining, $seen);

            foreach ($newMaterial as $hadith) {
                $seen[$hadith->id] = true;
                $entries->push([
                    'hadith' => $hadith,
                    'progress' => $progress->get($hadith->id),
                    'stack_item' => null,
                    'queue_reason' => self::REASON_NEW,
                ]);
            }
        }

        return $entries
            ->take($limit)
            ->values()
            ->map(fn (array $entry, int $index) => $entry + ['position' => $index + 1]);
    }

    /**
     * Pending pushes, user requests first, then newest push.
     *
     * @return Collection<int,MemorizationStackItem>
     */
    public function pushedItems(User $user, ?int $bookId = null): Collection
    {
        return MemorizationStackItem::where('user_id', $user->id)
            ->whereNull('resolved_at')
            ->when($bookId !== null, fn ($q) => $q->whereHas('hadith', fn ($h) => $h->where('book_id', $bookId)))
            ->with(['hadith' => fn ($q) => $q->with(['book', 'narrator'])])
            ->get()
            ->sortBy([
                fn (MemorizationStackItem $item) => $item->source->priority(),
                fn (MemorizationStackItem $item) => -($item->pushed_at?->getTimestamp() ?? 0),
                fn (MemorizationStackItem $item) => -$item->id,
            ])
            ->values();
    }

    /**
     * The books the user is working in, most recent activity first: any book
     * they have pushed a hadith of onto the stack or recited from.
     *
     * @return Collection<int,int>
     */
    public function engagedBookIds(User $user): Collection
    {
        $activity = [];

        $record = function (?int $bookId, mixed $at) use (&$activity): void {
            if ($bookId === null) {
                return;
            }

            $timestamp = $at instanceof Carbon ? $at->getTimestamp() : (int) strtotime((string) $at);
            $activity[$bookId] = max($activity[$bookId] ?? 0, $timestamp);
        };

        $progressActivity = UserHadithProgress::query()
            ->join('hadiths', 'hadiths.id', '=', 'user_hadith_progress.hadith_id')
            ->where('user_hadith_progress.user_id', $user->id)
            ->groupBy('hadiths.book_id')
            ->selectRaw('hadiths.book_id as book_id, MAX(COALESCE(user_hadith_progress.last_attempt_at, user_hadith_progress.updated_at)) as last_activity')
            ->get();

        foreach ($progressActivity as $row) {
            $record((int) $row->book_id, $row->last_activity);
        }

        $stackActivity = MemorizationStackItem::query()
            ->join('hadiths', 'hadiths.id', '=', 'memorization_stack_items.hadith_id')
            ->where('memorization_stack_items.user_id', $user->id)
            ->groupBy('hadiths.book_id')
            ->selectRaw('hadiths.book_id as book_id, MAX(memorization_stack_items.pushed_at) as last_activity')
            ->get();

        foreach ($stackActivity as $row) {
            $record((int) $row->book_id, $row->last_activity);
        }

        arsort($activity);

        return new Collection(array_keys($activity));
    }

    /**
     * @param  array<int,bool>  $seen
     * @return Collection<int,Hadith>
     */
    private function dueReviews(User $user, array $seen, ?int $bookId = null): Collection
    {
        $dueHadithIds = UserHadithProgress::where('user_id', $user->id)
            ->whereNotNull('next_review_at')
            ->where('next_review_at', '<=', Carbon::now())
            ->orderBy('next_review_at')
            ->pluck('hadith_id')
            ->reject(fn (int $id) => isset($seen[$id]))
            ->values();

        if ($dueHadithIds->isEmpty()) {
            return new Collection;
        }

        $hadiths = Hadith::whereIn('id', $dueHadithIds)
            ->where('is_active', true)
            ->when($bookId !== null, fn ($q) => $q->where('book_id', $bookId))
            ->with(['book', 'narrator'])
            ->get()
            ->keyBy('id');

        // Keep the due-date order the progress query produced.
        return $dueHadithIds
            ->map(fn (int $id) => $hadiths->get($id))
            ->filter()
            ->values();
    }

    /**
     * The hadiths the learner started working on in the last couple of days —
     * pushed onto the stack or given a progress record — and has not recited
     * yet, newest first.
     *
     * A hadith already booked for a later day is left out: that is settled
     * work, and offering it again is what made a clean recitation reappear in
     * the very next sitting.
     *
     * @return Collection<int,int>
     */
    public function recentlyStartedHadithIds(User $user): Collection
    {
        $days = max((int) config('athar.reviews.recent_days', 2), 1);
        $since = Carbon::today()->subDays($days - 1)->startOfDay();
        $endOfToday = Carbon::now()->endOfDay();

        $fromProgress = UserHadithProgress::where('user_id', $user->id)
            ->where('created_at', '>=', $since)
            ->where('status', '!=', MemorizationStatus::Memorized->value)
            ->where(function ($q) use ($endOfToday) {
                $q->whereNull('next_review_at')
                    ->orWhere('next_review_at', '<=', $endOfToday);
            })
            ->orderByDesc('created_at')
            ->pluck('hadith_id');

        $settled = UserHadithProgress::where('user_id', $user->id)
            ->where(function ($q) use ($endOfToday) {
                $q->where('status', MemorizationStatus::Memorized->value)
                    ->orWhere('next_review_at', '>', $endOfToday);
            })
            ->pluck('hadith_id')
            ->all();

        $fromStack = MemorizationStackItem::where('user_id', $user->id)
            ->whereNull('resolved_at')
            ->where('pushed_at', '>=', $since)
            ->when($settled !== [], fn ($q) => $q->whereNotIn('hadith_id', $settled))
            ->orderByDesc('pushed_at')
            ->pluck('hadith_id');

        return $fromProgress->concat($fromStack)->unique()->values();
    }

    /**
     * Layer 3 of the daily list: the recently started hadiths, as models.
     *
     * @param  array<int,bool>  $seen
     * @return Collection<int,Hadith>
     */
    private function recentlyStarted(User $user, int $limit, array $seen): Collection
    {
        $ids = $this->recentlyStartedHadithIds($user)
            ->reject(fn (int $id) => isset($seen[$id]))
            ->take($limit)
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
     * Hadiths still to memorize, from the books the user is working in (or the
     * requested book), then the book's own order.
     *
     * @param  array<int,bool>  $seen
     * @return Collection<int,Hadith>
     */
    private function unmemorized(User $user, int $limit, array $seen, ?int $bookId = null): Collection
    {
        $orderedBookIds = $bookId !== null
            ? new Collection([$bookId])
            : $this->engagedBookIds($user);

        if ($orderedBookIds->isEmpty()) {
            return new Collection;
        }

        // Anything already scheduled is not pending work: a hadith recited
        // well is booked for a later day, and pulling it back in here is what
        // made a clean recitation reappear in the very next session. Only
        // hadiths with no schedule at all — never started — belong in this
        // layer; the ones whose date has arrived come from the due layer above.
        $scheduledIds = UserHadithProgress::where('user_id', $user->id)
            ->where(function ($q) {
                $q->where('status', MemorizationStatus::Memorized->value)
                    ->orWhere('next_review_at', '>', Carbon::now());
            })
            ->pluck('hadith_id')
            ->all();

        $skip = array_keys($seen + array_fill_keys($scheduledIds, true));

        $collected = new Collection;

        foreach ($orderedBookIds as $id) {
            if ($collected->count() >= $limit) {
                break;
            }

            $hadiths = Hadith::where('book_id', $id)
                ->where('is_active', true)
                ->when($skip !== [], fn ($q) => $q->whereNotIn('id', $skip))
                ->with(['book', 'narrator'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit($limit - $collected->count())
                ->get();

            $collected = $collected->concat($hadiths);
        }

        return $collected->values();
    }
}
