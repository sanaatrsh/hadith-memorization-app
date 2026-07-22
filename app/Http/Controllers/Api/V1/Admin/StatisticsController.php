<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\MemorizationStatus;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Hadith;
use App\Models\MemorizationAttempt;
use App\Models\User;
use App\Models\UserHadithProgress;
use App\Support\ApiResponse;
use OpenApi\Attributes as OA;

class StatisticsController extends Controller
{
    #[OA\Get(
        path: '/admin/statistics/overview',
        operationId: 'statisticsOverview',
        summary: 'Overview statistics (admin)',
        description: 'Aggregate totals plus Gemini failure rate and average latency. No provider secrets or prompts are exposed.',
        tags: ['Admin - Statistics'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Overview.', content: new OA\JsonContent(ref: '#/components/schemas/SuccessMessage')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
        ],
    )]
    public function overview()
    {
        $totalAttempts = MemorizationAttempt::count();
        $failedAi = MemorizationAttempt::whereNotNull('failure_code')->count();

        return ApiResponse::success([
            'total_users' => User::count(),
            'active_users' => User::where('is_active', true)->count(),
            'total_books' => Book::count(),
            'total_hadiths' => Hadith::count(),
            'total_attempts' => $totalAttempts,
            'average_score' => round((float) MemorizationAttempt::avg('final_score'), 1),
            'reviews_due' => UserHadithProgress::whereNotNull('next_review_at')
                ->where('next_review_at', '<=', now())
                ->count(),
            'gemini' => [
                'failure_rate' => $totalAttempts > 0 ? round($failedAi / $totalAttempts, 3) : 0.0,
                'average_latency_ms' => round((float) MemorizationAttempt::avg('processing_ms'), 1),
            ],
        ], 'Overview statistics retrieved successfully.');
    }

    #[OA\Get(
        path: '/admin/statistics/memorization',
        operationId: 'statisticsMemorization',
        summary: 'Memorization statistics (admin)',
        description: 'Derived from attempts and progress: users memorizing, completed hadiths, attempts by date, and most difficult hadiths.',
        tags: ['Admin - Statistics'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Memorization stats.', content: new OA\JsonContent(ref: '#/components/schemas/SuccessMessage')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
        ],
    )]
    public function memorization()
    {
        $usersMemorizing = UserHadithProgress::whereIn('status', [
            MemorizationStatus::Memorizing->value,
            MemorizationStatus::Reviewing->value,
        ])->distinct('user_id')->count('user_id');

        $attemptsByDate = MemorizationAttempt::query()
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $mostDifficult = MemorizationAttempt::query()
            ->selectRaw('hadith_id, AVG(final_score) as avg_score, COUNT(*) as attempts')
            ->groupBy('hadith_id')
            ->havingRaw('COUNT(*) >= 1')
            ->orderBy('avg_score')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'hadith_id' => (int) $row->hadith_id,
                'average_score' => round((float) $row->avg_score, 1),
                'attempts' => (int) $row->attempts,
            ]);

        return ApiResponse::success([
            'users_memorizing' => $usersMemorizing,
            'completed_hadiths' => UserHadithProgress::where('status', MemorizationStatus::Memorized->value)->count(),
            'average_score' => round((float) MemorizationAttempt::avg('final_score'), 1),
            'attempts_by_date' => $attemptsByDate,
            'most_difficult_hadiths' => $mostDifficult,
        ], 'Memorization statistics retrieved successfully.');
    }

    #[OA\Get(
        path: '/admin/statistics/users',
        operationId: 'statisticsUsers',
        summary: 'User statistics (admin)',
        tags: ['Admin - Statistics'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'User stats.', content: new OA\JsonContent(ref: '#/components/schemas/SuccessMessage')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
        ],
    )]
    public function users()
    {
        $byRole = User::selectRaw('role, COUNT(*) as total')->groupBy('role')->pluck('total', 'role');

        $topLearners = UserHadithProgress::query()
            ->selectRaw('user_id, COUNT(*) as memorized_count')
            ->where('status', MemorizationStatus::Memorized->value)
            ->groupBy('user_id')
            ->orderByDesc('memorized_count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'memorized_count' => (int) $row->memorized_count,
            ]);

        return ApiResponse::success([
            'total_users' => User::count(),
            'active_users' => User::where('is_active', true)->count(),
            'inactive_users' => User::where('is_active', false)->count(),
            'by_role' => $byRole,
            'new_last_30_days' => User::where('created_at', '>=', now()->subDays(30))->count(),
            'top_learners' => $topLearners,
        ], 'User statistics retrieved successfully.');
    }
}
