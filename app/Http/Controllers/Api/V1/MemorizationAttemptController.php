<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Memorization\EvaluateMemorizationAttempt;
use App\Enums\AttemptType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemorizationAttemptRequest;
use App\Http\Resources\MemorizationAttemptResource;
use App\Models\Hadith;
use App\Support\ApiResponse;
use OpenApi\Attributes as OA;

class MemorizationAttemptController extends Controller
{
    #[OA\Post(
        path: '/memorization/attempts',
        operationId: 'submitMemorizationAttempt',
        summary: 'Submit a memorization transcript for evaluation',
        description: 'Compares the Flutter speech-to-text transcript with the canonical hadith. The deterministic word comparison is the authoritative score; Gemini adds structured Arabic feedback when available. '
            .'The MVP evaluates missing, extra, replaced, and reordered words only — not pronunciation, because the original audio is not submitted. '
            .'If Gemini is temporarily unavailable the request still succeeds (HTTP 200) with ai_feedback_available=false and the deterministic report. '
            .'Duplicate client_attempt_uuid values are idempotent and return the existing attempt. Rate limited to 30 requests/minute.',
        tags: ['Memorization'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MemorizationAttemptRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Evaluation completed (or existing attempt for a duplicate UUID).', content: new OA\JsonContent(ref: '#/components/schemas/MemorizationEvaluationResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 422, description: 'Validation failed or hadith inactive.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
            new OA\Response(response: 429, description: 'Too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitError')),
        ],
    )]
    public function store(StoreMemorizationAttemptRequest $request, EvaluateMemorizationAttempt $action)
    {
        $user = $request->user();
        $hadith = Hadith::with('narrator')->findOrFail($request->integer('hadith_id'));

        // Hadith must be active.
        if (! $hadith->is_active) {
            return ApiResponse::error('This hadith is not available.', 422);
        }

        $attempt = $action->execute(
            user: $user,
            hadith: $hadith,
            clientAttemptUuid: (string) $request->input('client_attempt_uuid'),
            recognizedText: (string) $request->input('recognized_text'),
            type: AttemptType::Memorization,
        );

        return ApiResponse::success(
            new MemorizationAttemptResource($attempt),
            'تم تقييم التسميع بنجاح.'
        );
    }
}
