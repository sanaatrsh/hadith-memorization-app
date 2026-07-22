<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHadithAudioRequest;
use App\Models\Hadith;
use App\Support\ApiResponse;
use OpenApi\Attributes as OA;

class HadithAudioController extends Controller
{
    #[OA\Post(
        path: '/admin/hadiths/{hadith}/audio',
        operationId: 'uploadHadithAudio',
        summary: 'Upload official hadith audio (admin)',
        description: 'Single-file collection `hadith_audio`. Accepts MP3, WAV, M4A, or OGG. Maximum 20 MB.',
        tags: ['Admin - Hadiths'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['audio'],
                    properties: [
                        new OA\Property(property: 'audio', description: 'MP3, WAV, M4A, or OGG. Maximum 20 MB.', type: 'string', format: 'binary'),
                    ],
                    type: 'object',
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Audio uploaded.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', properties: [new OA\Property(property: 'audio_url', type: 'string')], type: 'object'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 422, description: 'Invalid file.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(StoreHadithAudioRequest $request, Hadith $hadith)
    {
        $hadith->addMediaFromRequest('audio')->toMediaCollection('hadith_audio');

        return ApiResponse::success(
            ['audio_url' => $hadith->refresh()->audioUrl()],
            'Hadith audio uploaded successfully.',
            201
        );
    }

    /**
     * Replace the existing official audio. The collection is single-file, so
     * adding a new file automatically clears the previous one.
     */
    #[OA\Post(
        path: '/admin/hadiths/{hadith}/audio/replace',
        operationId: 'replaceHadithAudio',
        summary: 'Replace official hadith audio (admin)',
        tags: ['Admin - Hadiths'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['audio'],
                    properties: [new OA\Property(property: 'audio', description: 'MP3, WAV, M4A, or OGG. Maximum 20 MB.', type: 'string', format: 'binary')],
                    type: 'object',
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Audio replaced.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', properties: [new OA\Property(property: 'audio_url', type: 'string')], type: 'object'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 422, description: 'Invalid file.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function replace(StoreHadithAudioRequest $request, Hadith $hadith)
    {
        $hadith->clearMediaCollection('hadith_audio');
        $hadith->addMediaFromRequest('audio')->toMediaCollection('hadith_audio');

        return ApiResponse::success(
            ['audio_url' => $hadith->refresh()->audioUrl()],
            'Hadith audio replaced successfully.'
        );
    }

    #[OA\Delete(
        path: '/admin/hadiths/{hadith}/audio',
        operationId: 'deleteHadithAudio',
        summary: 'Delete official hadith audio (admin)',
        tags: ['Admin - Hadiths'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Audio deleted.', content: new OA\JsonContent(ref: '#/components/schemas/SuccessMessage')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Hadith not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function destroy(Hadith $hadith)
    {
        $hadith->clearMediaCollection('hadith_audio');

        return ApiResponse::success(null, 'Hadith audio deleted successfully.');
    }
}
