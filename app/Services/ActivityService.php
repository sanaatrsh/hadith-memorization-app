<?php

namespace App\Services;

use App\Models\MemorizationAttempt;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The streak (الحماسة) and the activity heatmap.
 *
 * A day counts as active when the learner recited at least once that day.
 * Everything is derived from the attempts already stored, so there is no
 * counter to keep in sync and no way for the streak to drift from what the
 * learner actually did.
 */
class ActivityService
{
    /**
     * Distinct days the learner recited on, newest first.
     *
     * @return array<int,string> Y-m-d
     */
    public function activeDays(User $user, int $withinDays = 400): array
    {
        return MemorizationAttempt::where('user_id', $user->id)
            ->where('created_at', '>=', Carbon::today()->subDays($withinDays))
            ->orderByDesc('created_at')
            ->pluck('created_at')
            ->map(fn (Carbon $at) => $at->toDateString())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Days recited in an unbroken run ending today.
     *
     * Today not being active yet does not break the streak — the day is not
     * over — so a run ending yesterday still counts. It breaks once a whole
     * day passes with nothing recited.
     *
     * @param  array<int,string>  $activeDays  newest first
     */
    public function currentStreak(array $activeDays): int
    {
        if ($activeDays === []) {
            return 0;
        }

        $today = Carbon::today();
        $first = Carbon::parse($activeDays[0]);

        if ($this->wholeDaysBetween($first, $today) > 1) {
            return 0;
        }

        $streak = 1;
        $previous = $first;

        foreach (array_slice($activeDays, 1) as $day) {
            $date = Carbon::parse($day);

            if ($this->wholeDaysBetween($date, $previous) !== 1) {
                break;
            }

            $streak++;
            $previous = $date;
        }

        return $streak;
    }

    /**
     * The longest unbroken run the learner has ever had.
     *
     * @param  array<int,string>  $activeDays  newest first
     */
    public function longestStreak(array $activeDays): int
    {
        if ($activeDays === []) {
            return 0;
        }

        $longest = 1;
        $run = 1;
        $previous = Carbon::parse($activeDays[0]);

        foreach (array_slice($activeDays, 1) as $day) {
            $date = Carbon::parse($day);

            $run = $this->wholeDaysBetween($date, $previous) === 1 ? $run + 1 : 1;
            $longest = max($longest, $run);
            $previous = $date;
        }

        return $longest;
    }

    /**
     * Whole days between two dates, direction ignored.
     *
     * Carbon returns a float here, so the result is rounded to an int before it
     * is compared: a bare `!== 1` against 1.0 is never true and would end every
     * streak at its first day.
     */
    private function wholeDaysBetween(Carbon $a, Carbon $b): int
    {
        return (int) round(abs($a->diffInDays($b)));
    }

    /**
     * One entry per day for the last `$days` days, oldest first — the shape a
     * heatmap renders directly, including the empty days.
     *
     * @return array<int,array{date:string, attempts:int, active:bool}>
     */
    public function heatmap(User $user, int $days = 90): array
    {
        $from = Carbon::today()->subDays($days - 1);

        $counts = MemorizationAttempt::where('user_id', $user->id)
            ->where('created_at', '>=', $from)
            ->get(['created_at'])
            ->groupBy(fn (MemorizationAttempt $attempt) => $attempt->created_at->toDateString())
            ->map(fn ($group) => $group->count());

        $heatmap = [];

        for ($day = 0; $day < $days; $day++) {
            $date = $from->copy()->addDays($day)->toDateString();
            $attempts = (int) ($counts[$date] ?? 0);

            $heatmap[] = [
                'date' => $date,
                'attempts' => $attempts,
                'active' => $attempts > 0,
            ];
        }

        return $heatmap;
    }
}
