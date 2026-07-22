<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\MemorizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserProgressRequest;
use App\Http\Resources\UserHadithProgressResource;
use App\Models\Hadith;
use App\Models\ProgressAudit;
use App\Models\User;
use App\Models\UserHadithProgress;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class UserProgressController extends Controller
{
    #[OA\Patch(
        path: '/admin/users/{user}/hadiths/{hadith}/progress',
        operationId: 'adminUpdateUserProgress',
        summary: 'Manually adjust a user\'s hadith progress (admin, auditable)',
        description: 'Every change is recorded in an audit log with the acting administrator and an optional reason.',
        tags: ['Admin - Users'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['not_started', 'memorizing', 'reviewing', 'memorized'], example: 'memorized'),
                new OA\Property(property: 'srs_level', type: 'integer', minimum: 0, maximum: 10, example: 4),
                new OA\Property(property: 'next_review_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'reason', type: 'string', nullable: true, example: 'Verified in person'),
            ],
            type: 'object',
        )),
        responses: [
            new OA\Response(response: 200, description: 'Progress updated.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/UserProgress'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
            new OA\Response(response: 422, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    /**
     * Manually adjust a user's progress on a hadith. Every change is recorded
     * in an audit log with the acting administrator and an optional reason.
     */
    public function update(UpdateUserProgressRequest $request, User $user, Hadith $hadith)
    {
        $progress = UserHadithProgress::firstOrNew([
            'user_id' => $user->id,
            'hadith_id' => $hadith->id,
        ]);

        $original = $progress->only(['status', 'srs_level', 'next_review_at']);

        if ($request->has('status')) {
            $progress->status = $request->enum('status', MemorizationStatus::class);
        }
        if ($request->has('srs_level')) {
            $progress->srs_level = $request->integer('srs_level');
        }
        if ($request->has('next_review_at')) {
            $progress->next_review_at = $request->input('next_review_at');
        }

        return DB::transaction(function () use ($request, $user, $hadith, $progress, $original) {
            $progress->save();

            $newValues = $progress->only(['status', 'srs_level', 'next_review_at']);

            ProgressAudit::create([
                'admin_id' => $request->user()->id,
                'user_id' => $user->id,
                'hadith_id' => $hadith->id,
                'changes' => [
                    'old' => $this->normalizeForAudit($original),
                    'new' => $this->normalizeForAudit($newValues),
                ],
                'reason' => $request->input('reason'),
            ]);

            return ApiResponse::success(
                new UserHadithProgressResource($progress),
                'User progress updated successfully.'
            );
        });
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function normalizeForAudit(array $values): array
    {
        return [
            'status' => $values['status'] instanceof \BackedEnum ? $values['status']->value : $values['status'],
            'srs_level' => $values['srs_level'] ?? null,
            'next_review_at' => $values['next_review_at'] instanceof \DateTimeInterface
                ? $values['next_review_at']->toIso8601String()
                : $values['next_review_at'],
        ];
    }
}
