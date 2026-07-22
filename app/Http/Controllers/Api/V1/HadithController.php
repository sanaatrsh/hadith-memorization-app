<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\HadithResource;
use App\Models\Hadith;
use App\Support\ApiResponse;
use OpenApi\Attributes as OA;

class HadithController extends Controller
{
    #[OA\Get(
        path: '/hadiths/{hadith}',
        operationId: 'showHadith',
        summary: 'Get an active hadith with content and (when authenticated) the user progress',
        description: 'Returns canonical text, narrator, source, official audio URL, vocabulary terms, memorization aids, and the current user progress when a bearer token is supplied.',
        tags: ['Hadiths'],
        parameters: [new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Hadith detail.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Hadith'),
            ], type: 'object')),
            new OA\Response(response: 404, description: 'Not found or inactive.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function show(Hadith $hadith)
    {
        abort_unless($hadith->is_active, 404);

        $hadith->load(['book', 'narrator', 'terms', 'aids']);

        // Attach the current user's progress when authenticated.
        if (auth('sanctum')->check()) {
            $hadith->load('currentUserProgress');
        }

        return ApiResponse::success(new HadithResource($hadith), 'Hadith retrieved successfully.');
    }
}
