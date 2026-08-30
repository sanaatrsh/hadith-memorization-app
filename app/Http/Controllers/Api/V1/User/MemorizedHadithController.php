<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Enums\MemorizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserHadithProgressResource;
use App\Models\Hadith;
use App\Models\UserHadithProgress;
use App\Services\MemorizationStackService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

/**
 * The "تم الحفظ" button on a hadith: the learner declaring they know it,
 * without going through a recitation.
 */
class MemorizedHadithController extends Controller
{
    public function __construct(private readonly MemorizationStackService $stack) {}

    #[OA\Post(
        path: '/user/hadiths/{hadith}/memorized',
        operationId: 'markHadithMemorized',
        summary: 'Mark a hadith as memorized',
        description: 'Idempotent: pressing it again on a hadith already memorized changes nothing and answers with already_memorized=true, so the button can simply show the state instead of erroring or resetting the date. Marking it memorized also clears the review schedule and takes the hadith off the memorization stack.',
        tags: ['Memorization'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Marked, or already memorized.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'تم تسجيل حفظ الحديث.'),
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'already_memorized', type: 'boolean', example: false),
                    new OA\Property(property: 'memorized_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'progress', ref: '#/components/schemas/UserProgress'),
                ], type: 'object'),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 422, description: 'The hadith is not available.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(Request $request, Hadith $hadith)
    {
        if (! $hadith->is_active) {
            return ApiResponse::error('This hadith is not available.', 422);
        }

        $user = $request->user();

        $progress = UserHadithProgress::firstOrNew([
            'user_id' => $user->id,
            'hadith_id' => $hadith->id,
        ]);

        $alreadyMemorized = $progress->exists
            && $progress->status === MemorizationStatus::Memorized;

        if (! $alreadyMemorized) {
            $maxLevel = max(array_keys(config('athar.srs.intervals')));

            $progress->fill([
                'status' => MemorizationStatus::Memorized,
                'srs_level' => $maxLevel,
                'next_review_at' => null,
                'memorized_at' => $progress->memorized_at ?? Carbon::now(),
            ]);
            $progress->save();

            $this->stack->resolve($user, $hadith);
        }

        return ApiResponse::success([
            'already_memorized' => $alreadyMemorized,
            'memorized_at' => $progress->memorized_at,
            'progress' => new UserHadithProgressResource($progress),
        ], $alreadyMemorized ? 'هذا الحديث محفوظ مسبقاً.' : 'تم تسجيل حفظ الحديث.');
    }
}
