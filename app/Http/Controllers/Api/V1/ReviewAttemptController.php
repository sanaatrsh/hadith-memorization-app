<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Memorization\EvaluateMemorizationAttempt;
use App\Enums\AttemptType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReviewAttemptRequest;
use App\Http\Resources\MemorizationAttemptResource;
use App\Models\Hadith;
use App\Models\UserBook;
use App\Support\ApiResponse;
use OpenApi\Attributes as OA;

/**
 * Convenience endpoint for review sessions. It reuses the same evaluation
 * Action as memorization so the comparison logic is shared, only tagging the
 * attempt as a review.
 */
class ReviewAttemptController extends Controller
{
    #[OA\Post(
        path: '/reviews/{hadith}/attempts',
        operationId: 'submitReviewAttempt',
        summary: 'Submit a review transcript for a hadith',
        description: 'Same evaluation flow as memorization, tagged as a review. Gemini failures degrade gracefully to the deterministic report. Rate limited to 30 requests/minute.',
        tags: ['Reviews'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReviewAttemptRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Review evaluated.', content: new OA\JsonContent(ref: '#/components/schemas/MemorizationEvaluationResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 403, description: 'The related book is not selected by the user.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 422, description: 'Validation failed or hadith inactive.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(StoreReviewAttemptRequest $request, Hadith $hadith, EvaluateMemorizationAttempt $action)
    {
        $user = $request->user();

        if (! $hadith->is_active) {
            return ApiResponse::error('This hadith is not available.', 422);
        }

        $ownsBook = UserBook::where('user_id', $user->id)
            ->where('book_id', $hadith->book_id)
            ->exists();

        if (! $ownsBook) {
            return ApiResponse::error('You have not selected the book this hadith belongs to.', 403);
        }

        $hadith->loadMissing('narrator');

        $attempt = $action->execute(
            user: $user,
            hadith: $hadith,
            clientAttemptUuid: (string) $request->input('client_attempt_uuid'),
            recognizedText: (string) $request->input('recognized_text'),
            type: AttemptType::Review,
        );

        return ApiResponse::success(
            new MemorizationAttemptResource($attempt),
            'تم تقييم المراجعة بنجاح.'
        );
    }
}
