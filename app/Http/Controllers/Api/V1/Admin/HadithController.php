<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHadithRequest;
use App\Http\Requests\Admin\UpdateHadithRequest;
use App\Http\Resources\HadithResource;
use App\Models\Hadith;
use App\Support\ApiResponse;
use OpenApi\Attributes as OA;

class HadithController extends Controller
{
    #[OA\Get(
        path: '/admin/hadiths',
        operationId: 'adminListHadiths',
        summary: 'List hadiths (admin)',
        tags: ['Admin - Hadiths'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Paginated hadiths.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Hadith')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
        ],
    )]
    public function index()
    {
        $hadiths = Hadith::with(['book', 'narrator'])
            ->orderBy('book_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(20);

        return HadithResource::collection($hadiths);
    }

    #[OA\Post(
        path: '/admin/hadiths',
        operationId: 'adminCreateHadith',
        summary: 'Create a hadith (admin)',
        description: 'Every hadith belongs to exactly one book.',
        tags: ['Admin - Hadiths'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HadithRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Created.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Hadith'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 422, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(StoreHadithRequest $request)
    {
        $hadith = Hadith::create($request->validated());
        $hadith->load(['book', 'narrator', 'terms', 'aids']);

        return ApiResponse::success(new HadithResource($hadith), 'Hadith created successfully.', 201);
    }

    #[OA\Get(
        path: '/admin/hadiths/{hadith}',
        operationId: 'adminShowHadith',
        summary: 'Get a hadith (admin)',
        tags: ['Admin - Hadiths'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Hadith.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Hadith'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function show(Hadith $hadith)
    {
        $hadith->load(['book', 'narrator', 'terms', 'aids']);

        return ApiResponse::success(new HadithResource($hadith), 'Hadith retrieved successfully.');
    }

    #[OA\Put(
        path: '/admin/hadiths/{hadith}',
        operationId: 'adminUpdateHadith',
        summary: 'Update a hadith (admin)',
        tags: ['Admin - Hadiths'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HadithRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Updated.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Hadith'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
            new OA\Response(response: 422, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    #[OA\Patch(
        path: '/admin/hadiths/{hadith}',
        operationId: 'adminPatchHadith',
        summary: 'Partially update a hadith (admin)',
        tags: ['Admin - Hadiths'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HadithRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Updated.', content: new OA\JsonContent(ref: '#/components/schemas/SuccessMessage')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
            new OA\Response(response: 422, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function update(UpdateHadithRequest $request, Hadith $hadith)
    {
        $hadith->update($request->validated());
        $hadith->load(['book', 'narrator', 'terms', 'aids']);

        return ApiResponse::success(new HadithResource($hadith), 'Hadith updated successfully.');
    }

    #[OA\Delete(
        path: '/admin/hadiths/{hadith}',
        operationId: 'adminDeleteHadith',
        summary: 'Delete a hadith (admin)',
        tags: ['Admin - Hadiths'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted.', content: new OA\JsonContent(ref: '#/components/schemas/SuccessMessage')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function destroy(Hadith $hadith)
    {
        $hadith->delete();

        return ApiResponse::success(null, 'Hadith deleted successfully.');
    }
}
