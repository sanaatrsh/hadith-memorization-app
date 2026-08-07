<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AttemptStatus;
use App\Enums\AttemptType;
use App\Enums\EvaluationVerdict;
use App\Enums\MemorizationStatus;
use App\Enums\UserRole;
use App\Models\Book;
use App\Models\Hadith;
use App\Models\MemorizationAttempt;
use App\Models\ProgressAudit;
use App\Models\User;
use App\Models\UserBook;
use App\Models\UserHadithProgress;
use App\Services\ArabicTextNormalizer;
use App\Services\HadithTextComparisonService;
use App\Services\SpacedRepetitionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;

/**
 * Gives the learners already in the database a recitation history over
 * الأربعون النووية: memorization attempts with their comparison reports, and
 * the user_hadith_progress those attempts add up to.
 *
 * Users are never created here — the seeder attaches to whoever already has the
 * `user` role, in id order, cycling through the profiles in the data file. That
 * way it works against an existing database rather than assuming its own.
 *
 * Attempts are replayed through the real HadithTextComparisonService and
 * SpacedRepetitionService, so every score, verdict, SRS level and review date
 * is what the app itself would have written. Nothing is hand-numbered. The AI
 * columns stay null: seeding must not call Gemini.
 *
 * It is idempotent — each attempt gets a UUID derived from (user, hadith,
 * position), and progress is recomputed from the same history every run.
 */
class ArabicMemorizationSeeder extends Seeder
{
    public function run(
        ArabicTextNormalizer $normalizer,
        HadithTextComparisonService $comparison,
        SpacedRepetitionService $srs,
    ): void {
        /** @var array{book:string, profiles:array<int,array<string,mixed>>, audits:array<int,array<string,mixed>>} $catalogue */
        $catalogue = require __DIR__.'/data/arabic_memorization.php';

        $book = $this->book($catalogue['book']);

        $learners = User::where('role', UserRole::User)->orderBy('id')->get();

        if ($learners->isEmpty()) {
            $this->command?->warn('No users with the "user" role — skipping memorization history.');

            return;
        }

        $hadiths = $book->hadiths()->get()->keyBy('title');
        $profiles = $catalogue['profiles'];

        /** @var array<int,User> $profileOwners */
        $profileOwners = [];

        foreach ($learners->values() as $index => $learner) {
            $profile = $profiles[$index % count($profiles)];
            $profileOwners[$index % count($profiles)] ??= $learner;

            $this->command?->info("  {$learner->email} → {$profile['label']}");

            UserBook::updateOrCreate(
                ['user_id' => $learner->id, 'book_id' => $book->id],
                ['started_at' => now()->subDays(60)],
            );

            foreach ($profile['hadiths'] as $entry) {
                /** @var Hadith $hadith */
                $hadith = $hadiths[$entry['hadith']];

                $this->replay($learner, $hadith, $entry['attempts'], $normalizer, $comparison, $srs);
            }
        }

        $this->audits($catalogue['audits'], $profileOwners, $hadiths);
    }

    /**
     * Rebuild one hadith's attempt history and the progress it produces.
     *
     * @param  array<int,array{days_ago:int, type?:string, text:string|null}>  $attempts
     */
    private function replay(
        User $learner,
        Hadith $hadith,
        array $attempts,
        ArabicTextNormalizer $normalizer,
        HadithTextComparisonService $comparison,
        SpacedRepetitionService $srs,
    ): void {
        $normalizedReference = $normalizer->normalize($hadith->text);

        // Carries the SRS level between attempts without ever being saved.
        $state = new UserHadithProgress(['srs_level' => 0]);

        $bestScore = 0;
        $memorizedAt = null;
        $lastAttemptAt = null;
        $transition = null;

        foreach (array_values($attempts) as $position => $attempt) {
            $recognizedText = $attempt['text'] ?? $hadith->text;
            $attemptedAt = Carbon::now()->subDays($attempt['days_ago'])->startOfHour();

            $normalizedRecognized = $normalizer->normalize($recognizedText);
            $report = $comparison->compare($normalizedReference, $normalizedRecognized);
            $score = (int) $report['score'];

            $this->store($learner, $hadith, $position, $attempt, $recognizedText, [
                'normalized_recognized_text' => $normalizedRecognized,
                'normalized_reference_text' => $normalizedReference,
                'score' => $score,
                'report' => $report,
            ], $attemptedAt);

            $transition = $srs->transition($state, $score, $attemptedAt);
            $state->srs_level = $transition['srs_level'];

            $bestScore = max($bestScore, $score);
            $lastAttemptAt = $attemptedAt;

            if ($transition['memorized'] && $memorizedAt === null) {
                $memorizedAt = $attemptedAt;
            }
        }

        if ($transition === null) {
            return;
        }

        UserHadithProgress::updateOrCreate(
            ['user_id' => $learner->id, 'hadith_id' => $hadith->id],
            [
                'status' => $transition['status'],
                'srs_level' => $transition['srs_level'],
                'best_score' => $bestScore,
                'attempts_count' => count($attempts),
                'last_attempt_at' => $lastAttemptAt,
                'next_review_at' => $transition['next_review_at'],
                'memorized_at' => $memorizedAt,
            ],
        );
    }

    /**
     * @param  array{days_ago:int, type?:string, text:string|null}  $attempt
     * @param  array{normalized_recognized_text:string, normalized_reference_text:string, score:int, report:array<string,mixed>}  $evaluation
     */
    private function store(
        User $learner,
        Hadith $hadith,
        int $position,
        array $attempt,
        string $recognizedText,
        array $evaluation,
        Carbon $attemptedAt,
    ): void {
        // Derived rather than random so reseeding updates the same attempt.
        $uuid = Uuid::uuid5(Uuid::NAMESPACE_OID, "athar:{$learner->id}:{$hadith->id}:{$position}")->toString();

        $model = MemorizationAttempt::firstOrNew([
            'user_id' => $learner->id,
            'client_attempt_uuid' => $uuid,
        ]);

        // forceFill so the backdated timestamps land; they are not fillable.
        $model->forceFill([
            'hadith_id' => $hadith->id,
            'type' => AttemptType::from($attempt['type'] ?? 'memorization'),
            'status' => AttemptStatus::Completed,
            'recognized_text' => $recognizedText,
            'normalized_recognized_text' => $evaluation['normalized_recognized_text'],
            'normalized_reference_text' => $evaluation['normalized_reference_text'],
            'deterministic_score' => $evaluation['score'],
            'final_score' => $evaluation['score'],
            'verdict' => $this->verdict($evaluation['score']),
            'comparison_report' => $evaluation['report'],
            'processing_ms' => 40 + ($position * 7),
            'created_at' => $attemptedAt,
            'updated_at' => $attemptedAt,
        ])->save();
    }

    /**
     * Manual supervisor adjustments, recorded the way the admin endpoint
     * records them: the progress row is changed and the change is audited.
     *
     * @param  array<int,array<string,mixed>>  $audits
     * @param  array<int,User>  $profileOwners
     * @param  Collection<string,Hadith>  $hadiths
     */
    private function audits(array $audits, array $profileOwners, $hadiths): void
    {
        $admin = User::where('role', UserRole::Admin)->orderBy('id')->first();

        if ($admin === null) {
            return;
        }

        foreach ($audits as $entry) {
            $learner = $profileOwners[$entry['profile']] ?? null;

            if ($learner === null) {
                continue;
            }

            $hadith = $hadiths[$entry['hadith']];

            $progress = UserHadithProgress::firstOrNew([
                'user_id' => $learner->id,
                'hadith_id' => $hadith->id,
            ]);

            $old = [
                'status' => $progress->status?->value,
                'srs_level' => $progress->srs_level,
                'next_review_at' => $progress->next_review_at?->toIso8601String(),
            ];

            $progress->status = MemorizationStatus::from($entry['status']);
            $progress->srs_level = $entry['srs_level'];
            $progress->next_review_at = $entry['next_review_days'] === null
                ? null
                : Carbon::now()->addDays($entry['next_review_days'])->startOfHour();
            $progress->save();

            $new = [
                'status' => $progress->status->value,
                'srs_level' => $progress->srs_level,
                'next_review_at' => $progress->next_review_at?->toIso8601String(),
            ];

            ProgressAudit::updateOrCreate(
                ['admin_id' => $admin->id, 'user_id' => $learner->id, 'hadith_id' => $hadith->id],
                ['changes' => ['old' => $old, 'new' => $new], 'reason' => $entry['reason']],
            );
        }
    }

    /** Mirrors EvaluateMemorizationAttempt::verdictFromScore. */
    private function verdict(int $score): EvaluationVerdict
    {
        $passing = (int) config('athar.scoring.passing');
        $acceptable = (int) config('athar.scoring.acceptable_min');

        return match (true) {
            $score >= $passing => EvaluationVerdict::Correct,
            $score >= $acceptable => EvaluationVerdict::Acceptable,
            $score >= 50 => EvaluationVerdict::NeedsReview,
            default => EvaluationVerdict::Incorrect,
        };
    }

    /** Seed the collection when it is missing so this seeder can run on its own. */
    private function book(string $title): Book
    {
        $book = Book::where('title', $title)->first();

        if ($book === null || $book->hadiths()->doesntExist()) {
            $this->call(NawawiFortySeeder::class);

            $book = Book::where('title', $title)->sole();
        }

        return $book;
    }
}
