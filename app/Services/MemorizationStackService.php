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
 *  1. Pushed items (LIFO): hadiths explicitly put back on the stack, the
 *     user's own requests before Gemini's / the evaluator's, newest push first.
 *  2. Reviews that are due now, earliest due date first.
 *  3. Hadiths not memorized yet, newest added book first (the book the user
 *     just added has priority), then the book's own order.
 *
 * Only books the user added to the learning list feed layers 2 and 3 — reading
 * a book is open to everyone, but memorizing it starts by adding it.
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
     * The ordered queue of hadiths the user should memorize next.
     *
     * @return Collection<int,array{hadith:Hadith, progress:?UserHadithProgress, stack_item:?MemorizationStackItem, queue_reason:string, position:int}>
     */
    public function queue(User $user, int $limit = 20): Collection
    {
        $bookIds = $user->books()
            ->whereNull('completed_at')
            ->pluck('book_id');

        $pushed = $this->pushedItems($user);

        $progress = UserHadithProgress::where('user_id', $user->id)
            ->get()
            ->keyBy('hadith_id');

        $entries = new Collection;
        $seen = [];

        foreach ($pushed as $item) {
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

        if ($bookIds->isNotEmpty() && $entries->count() < $limit) {
            foreach ($this->dueReviews($user, $bookIds, $seen) as $hadith) {
                $seen[$hadith->id] = true;
                $entries->push([
                    'hadith' => $hadith,
                    'progress' => $progress->get($hadith->id),
                    'stack_item' => null,
                    'queue_reason' => self::REASON_DUE_REVIEW,
                ]);
            }
        }

        if ($bookIds->isNotEmpty() && $entries->count() < $limit) {
            $remaining = $limit - $entries->count();

            foreach ($this->unmemorized($user, $remaining, $seen) as $hadith) {
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
    public function pushedItems(User $user): Collection
    {
        return MemorizationStackItem::where('user_id', $user->id)
            ->whereNull('resolved_at')
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
     * @param  Collection<int,int>  $bookIds
     * @param  array<int,bool>  $seen
     * @return Collection<int,Hadith>
     */
    private function dueReviews(User $user, $bookIds, array $seen): Collection
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
            ->whereIn('book_id', $bookIds)
            ->where('is_active', true)
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
     * Hadiths still to memorize, newest added book first, then book order.
     *
     * @param  array<int,bool>  $seen
     * @return Collection<int,Hadith>
     */
    private function unmemorized(User $user, int $limit, array $seen): Collection
    {
        // The book the user added most recently is on top of the stack.
        $orderedBookIds = $user->books()
            ->whereNull('completed_at')
            ->orderByRaw('COALESCE(started_at, created_at) DESC')
            ->orderByDesc('id')
            ->pluck('book_id');

        if ($orderedBookIds->isEmpty()) {
            return new Collection;
        }

        $memorizedIds = UserHadithProgress::where('user_id', $user->id)
            ->where('status', MemorizationStatus::Memorized->value)
            ->pluck('hadith_id')
            ->all();

        $skip = array_keys($seen + array_fill_keys($memorizedIds, true));

        $collected = new Collection;

        foreach ($orderedBookIds as $bookId) {
            if ($collected->count() >= $limit) {
                break;
            }

            $hadiths = Hadith::where('book_id', $bookId)
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
