<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewSessionResource;
use App\Models\ReviewSession;
use App\Services\ReviewSessionService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * A review sitting (جلسة المراجعة): start it, work through today's due
 * hadiths, then finish it and see the result.
 */
class ReviewSessionController extends Controller
{
    public function __construct(private readonly ReviewSessionService $sessions) {}

    #[OA\Post(
        path: '/user/review-sessions',
        operationId: 'startReviewSession',
        summary: "Start a review session over today's due hadiths",
        description: 'The session contains only the hadiths whose review falls due by the end of today — overdue ones included, hadiths scheduled for a later day excluded. The set is fixed when the session starts, so reciting a hadith (which reschedules it) does not remove it from the sitting. A session already in progress is returned instead of being replaced.',
        tags: ['Reviews'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'Session started.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/ReviewSession'),
            ], type: 'object')),
            new OA\Response(response: 200, description: 'A session was already in progress.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/ReviewSession'),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
        ],
    )]
    public function store(Request $request)
    {
        $existing = $this->sessions->current($request->user());

        $session = $this->sessions->start($request->user());
        $session->load(['items.hadith.currentUserProgress', 'items.attempt']);

        return ApiResponse::success(
            new ReviewSessionResource($session),
            $existing === null
                ? 'تم بدء جلسة المراجعة.'
                : 'لديك جلسة مراجعة قيد التقدم.',
            $existing === null ? 201 : 200,
        );
    }

    #[OA\Get(
        path: '/user/review-sessions/current',
        operationId: 'currentReviewSession',
        summary: 'Get the review session in progress',
        description: 'Returns the session the learner is in the middle of, or 404 when there is none.',
        tags: ['Reviews'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The session in progress.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/ReviewSession'),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 404, description: 'No session in progress.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function current(Request $request)
    {
        $session = $this->sessions->current($request->user());

        abort_if($session === null, 404, 'No review session is in progress.');

        $session->load(['items.hadith.currentUserProgress', 'items.attempt']);

        return ApiResponse::success(new ReviewSessionResource($session), 'Review session retrieved successfully.');
    }

    #[OA\Get(
        path: '/user/review-sessions/{session}',
        operationId: 'showReviewSession',
        summary: 'Get one review session',
        description: 'While the session is in progress each hadith shows only whether it has been recited; once completed the same endpoint returns the score, the per-hadith result, and the next review date for each.',
        tags: ['Reviews'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'session', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The session.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/ReviewSession'),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 403, description: 'The session belongs to another user.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
        ],
    )]
    public function show(Request $request, ReviewSession $session)
    {
        $this->authorizeSession($request, $session);

        $session->load(['items.hadith.currentUserProgress', 'items.attempt']);

        return ApiResponse::success(new ReviewSessionResource($session), 'Review session retrieved successfully.');
    }

    #[OA\Post(
        path: '/user/review-sessions/{session}/complete',
        operationId: 'completeReviewSession',
        summary: 'End the review session and release its result',
        description: 'Finishes the sitting and returns the overall score — the average over every hadith in the session, with anything left unrecited counting as zero — plus a summary and, for each hadith, its score and the date it comes up for review again.',
        tags: ['Reviews'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'session', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Session completed.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/ReviewSession'),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 403, description: 'The session belongs to another user.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 422, description: 'The session is already completed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function complete(Request $request, ReviewSession $session)
    {
        $this->authorizeSession($request, $session);

        abort_if($session->isCompleted(), 422, 'This review session is already completed.');

        $session = $this->sessions->complete($session);
        $session->load(['items.hadith.currentUserProgress', 'items.attempt']);

        return ApiResponse::success(new ReviewSessionResource($session), 'تم إنهاء جلسة المراجعة.');
    }

    private function authorizeSession(Request $request, ReviewSession $session): void
    {
        abort_unless($session->user_id === $request->user()->id, 403, 'This review session does not belong to you.');
    }
}
