<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Enums\MemorizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookResource;
use App\Models\Hadith;
use App\Models\UserBook;
use App\Models\UserHadithProgress;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DashboardController extends Controller
{
    #[OA\Get(
        path: '/user/progress',
        operationId: 'memorizationDashboard',
        summary: 'Get the memorization dashboard',
        description: 'Aggregated counts across the user\'s selected books: totals, status breakdown, and reviews due today.',
        tags: ['Memorization'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Dashboard.', content: new OA\JsonContent(ref: '#/components/schemas/DashboardResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
        ],
    )]
    public function show(Request $request)
    {
        $userId = $request->user()->id;

        // Active selected books.
        $activeBooks = UserBook::where('user_id', $userId)
            ->whereNull('completed_at')
            ->with('book')
            ->get()
            ->pluck('book')
            ->filter();

        $bookIds = $activeBooks->pluck('id');

        // Total active hadiths inside the selected books.
        $totalHadiths = Hadith::whereIn('book_id', $bookIds)
            ->where('is_active', true)
            ->count();

        // Progress counts per status for hadiths in the selected books.
        $statusCounts = UserHadithProgress::where('user_id', $userId)
            ->whereHas('hadith', fn ($q) => $q->whereIn('book_id', $bookIds)->where('is_active', true))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $memorizing = (int) ($statusCounts[MemorizationStatus::Memorizing->value] ?? 0);
        $reviewing = (int) ($statusCounts[MemorizationStatus::Reviewing->value] ?? 0);
        $memorized = (int) ($statusCounts[MemorizationStatus::Memorized->value] ?? 0);
        $notStarted = max($totalHadiths - ($memorizing + $reviewing + $memorized), 0);

        $reviewsDueToday = UserHadithProgress::where('user_id', $userId)
            ->whereNotNull('next_review_at')
            ->where('next_review_at', '<=', now())
            ->count();

        return ApiResponse::success([
            'active_books' => BookResource::collection($activeBooks->values()),
            'total_hadiths' => $totalHadiths,
            'not_started' => $notStarted,
            'memorizing' => $memorizing,
            'reviewing' => $reviewing,
            'memorized' => $memorized,
            'reviews_due_today' => $reviewsDueToday,
            // Recent attempts are populated once the attempts module (Phase 4) exists.
            'recent_attempts' => [],
        ], 'Dashboard retrieved successfully.');
    }
}
