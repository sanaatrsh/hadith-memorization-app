<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Enums\MemorizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookResource;
use App\Models\Book;
use App\Models\Hadith;
use App\Models\MemorizationStackItem;
use App\Models\UserHadithProgress;
use App\Services\MemorizationStackService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DashboardController extends Controller
{
    public function __construct(private readonly MemorizationStackService $stack) {}

    #[OA\Get(
        path: '/user/progress',
        operationId: 'memorizationDashboard',
        summary: 'Get the memorization dashboard',
        description: 'Counts across the whole catalogue (every user has every book): totals, status breakdown, pending stack size, and reviews due today. `active_books` are the books the user is working in — the ones they pushed a hadith of onto the stack or recited from.',
        tags: ['Memorization'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Dashboard.', content: new OA\JsonContent(ref: '#/components/schemas/DashboardResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
        ],
    )]
    public function show(Request $request)
    {
        $user = $request->user();
        $userId = $user->id;

        // The books the user is actually working in, most recent first.
        $engagedBookIds = $this->stack->engagedBookIds($user);

        $activeBooks = Book::whereIn('id', $engagedBookIds)
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (Book $book) => $engagedBookIds->search($book->id))
            ->values();

        // Every active book is available to every user, so the totals cover the
        // whole catalogue rather than a selection.
        $totalHadiths = Hadith::where('is_active', true)->count();
        $totalBooks = Book::where('is_active', true)->count();

        $statusCounts = UserHadithProgress::where('user_id', $userId)
            ->whereHas('hadith', fn ($q) => $q->where('is_active', true))
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

        $stackCount = MemorizationStackItem::where('user_id', $userId)
            ->whereNull('resolved_at')
            ->count();

        return ApiResponse::success([
            'active_books' => BookResource::collection($activeBooks),
            'total_books' => $totalBooks,
            'total_hadiths' => $totalHadiths,
            'not_started' => $notStarted,
            'memorizing' => $memorizing,
            'reviewing' => $reviewing,
            'memorized' => $memorized,
            'reviews_due_today' => $reviewsDueToday,
            'stack_count' => $stackCount,
            // Recent attempts are populated once the attempts module (Phase 4) exists.
            'recent_attempts' => [],
        ], 'Dashboard retrieved successfully.');
    }
}
