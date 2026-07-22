<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Enums\MemorizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserHadithProgressResource;
use App\Models\UserHadithProgress;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ReviewController extends Controller
{
    #[OA\Get(
        path: '/user/reviews/due',
        operationId: 'dueReviews',
        summary: 'List reviews due now',
        description: 'Progress items whose next_review_at is due, ordered by due date.',
        tags: ['Reviews'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Due reviews.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/UserProgress')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
        ],
    )]
    public function due(Request $request)
    {
        $due = UserHadithProgress::where('user_id', $request->user()->id)
            ->whereNotNull('next_review_at')
            ->where('next_review_at', '<=', now())
            ->whereIn('status', [
                MemorizationStatus::Memorizing->value,
                MemorizationStatus::Reviewing->value,
                MemorizationStatus::Memorized->value,
            ])
            ->with(['hadith' => fn ($q) => $q->with('book')])
            ->orderBy('next_review_at')
            ->paginate(20);

        return UserHadithProgressResource::collection($due);
    }
}
