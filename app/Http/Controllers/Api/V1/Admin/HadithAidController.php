<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHadithAidRequest;
use App\Http\Requests\Admin\UpdateHadithAidRequest;
use App\Http\Resources\HadithAidResource;
use App\Models\Hadith;
use App\Models\HadithAid;
use App\Support\ApiResponse;
use OpenApi\Attributes as OA;

class HadithAidController extends Controller
{
    #[OA\Get(
        path: '/admin/hadiths/{hadith}/aids',
        operationId: 'adminListHadithAids',
        summary: 'List memorization aids for a hadith (admin)',
        tags: ['Admin - Hadiths'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Aids.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HadithAid')),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
        ],
    )]
    public function index(Hadith $hadith)
    {
        return HadithAidResource::collection($hadith->aids()->get());
    }

    #[OA\Post(
        path: '/admin/hadiths/{hadith}/aids',
        operationId: 'adminCreateHadithAid',
        summary: 'Add a memorization aid to a hadith (admin)',
        tags: ['Admin - Hadiths'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['title', 'content'],
            properties: [
                new OA\Property(property: 'title', type: 'string', example: 'تلميح'),
                new OA\Property(property: 'content', type: 'string', example: 'ركّز على الكلمة الأولى.'),
                new OA\Property(property: 'sort_order', type: 'integer', example: 0),
            ],
            type: 'object',
        )),
        responses: [
            new OA\Response(response: 201, description: 'Created.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/HadithAid'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 422, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(StoreHadithAidRequest $request, Hadith $hadith)
    {
        $aid = $hadith->aids()->create($request->validated());

        return ApiResponse::success(new HadithAidResource($aid), 'Aid created successfully.', 201);
    }

    #[OA\Put(
        path: '/admin/hadiths/{hadith}/aids/{aid}',
        operationId: 'adminUpdateHadithAid',
        summary: 'Update a memorization aid (admin)',
        tags: ['Admin - Hadiths'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'aid', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'title', type: 'string', example: 'تلميح'),
                new OA\Property(property: 'content', type: 'string', example: 'ركّز على الكلمة الأولى.'),
                new OA\Property(property: 'sort_order', type: 'integer', example: 0),
            ],
            type: 'object',
        )),
        responses: [
            new OA\Response(response: 200, description: 'Updated.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/HadithAid'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
            new OA\Response(response: 422, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function update(UpdateHadithAidRequest $request, Hadith $hadith, HadithAid $aid)
    {
        abort_unless($aid->hadith_id === $hadith->id, 404);

        $aid->update($request->validated());

        return ApiResponse::success(new HadithAidResource($aid), 'Aid updated successfully.');
    }

    #[OA\Delete(
        path: '/admin/hadiths/{hadith}/aids/{aid}',
        operationId: 'adminDeleteHadithAid',
        summary: 'Delete a memorization aid (admin)',
        tags: ['Admin - Hadiths'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'aid', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted.', content: new OA\JsonContent(ref: '#/components/schemas/SuccessMessage')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function destroy(Hadith $hadith, HadithAid $aid)
    {
        abort_unless($aid->hadith_id === $hadith->id, 404);

        $aid->delete();

        return ApiResponse::success(null, 'Aid deleted successfully.');
    }
}
