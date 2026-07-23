<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreNarratorRequest;
use App\Http\Requests\Admin\UpdateNarratorRequest;
use App\Http\Resources\NarratorResource;
use App\Models\Narrator;
use App\Support\ApiResponse;
use OpenApi\Attributes as OA;

class NarratorController extends Controller
{
    #[OA\Get(
        path: '/admin/narrators',
        operationId: 'adminListNarrators',
        summary: 'List narrators (admin)',
        tags: ['Admin - Narrators'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Paginated narrators.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Narrator')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
        ],
    )]
    public function index()
    {
        $narrators = Narrator::orderBy('name')->paginate(20);

        return NarratorResource::collection($narrators);
    }

    #[OA\Post(
        path: '/admin/narrators',
        operationId: 'adminCreateNarrator',
        summary: 'Create a narrator (admin)',
        tags: ['Admin - Narrators'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/NarratorRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Created.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Narrator'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 422, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(StoreNarratorRequest $request)
    {
        $narrator = Narrator::create($request->validated());

        return ApiResponse::success(new NarratorResource($narrator), 'Narrator created successfully.', 201);
    }

    public function show(Narrator $narrator)
    {
        return ApiResponse::success(new NarratorResource($narrator), 'Narrator retrieved successfully.');
    }

    #[OA\Put(
        path: '/admin/narrators/{narrator}',
        operationId: 'adminUpdateNarrator',
        summary: 'Update a narrator (admin)',
        tags: ['Admin - Narrators'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'narrator', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/NarratorRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Updated.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Narrator'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
            new OA\Response(response: 422, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    #[OA\Patch(
        path: '/admin/narrators/{narrator}',
        operationId: 'adminPatchNarrator',
        summary: 'Partially update a narrator (admin)',
        tags: ['Admin - Narrators'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'narrator', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/NarratorRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Updated.', content: new OA\JsonContent(ref: '#/components/schemas/SuccessMessage')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
            new OA\Response(response: 422, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function update(UpdateNarratorRequest $request, Narrator $narrator)
    {
        $narrator->update($request->validated());

        return ApiResponse::success(new NarratorResource($narrator), 'Narrator updated successfully.');
    }

    #[OA\Delete(
        path: '/admin/narrators/{narrator}',
        operationId: 'adminDeleteNarrator',
        summary: 'Delete a narrator (admin)',
        tags: ['Admin - Narrators'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'narrator', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted.', content: new OA\JsonContent(ref: '#/components/schemas/SuccessMessage')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function destroy(Narrator $narrator)
    {
        $narrator->delete();

        return ApiResponse::success(null, 'Narrator deleted successfully.');
    }
}
