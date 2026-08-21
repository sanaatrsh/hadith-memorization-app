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
 *  3. Hadiths not memorized yet from the books the user is already working in,
 *     most recent activity first, then the book's own order.
 *
 * Nothing here depends on a book being "selected": every active book is open to
 * every user, and a book counts as one the user works in as soon as they push a
 * hadith of it onto the stack or recite one. Layer 3 can also be scoped to one
 * book explicitly, so the app can show the queue for a book being browsed.
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

            foreach ($this->unmemorized($user, $remaining, $seen, $bookId) as $hadith) {
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
     * they have pushed a hadith of onto the stack, recited from, or (for
     * installations that still use the optional learning list) added.
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

        // The learning list is optional now, but when a book is in it that
        // still counts as working in it.
        foreach ($user->books()->whereNull('completed_at')->get() as $userBook) {
            $record($userBook->book_id, $userBook->started_at ?? $userBook->created_at);
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

        $memorizedIds = UserHadithProgress::where('user_id', $user->id)
            ->where('status', MemorizationStatus::Memorized->value)
            ->pluck('hadith_id')
            ->all();

        $skip = array_keys($seen + array_fill_keys($memorizedIds, true));

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
