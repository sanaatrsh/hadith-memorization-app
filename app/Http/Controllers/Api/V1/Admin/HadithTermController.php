<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHadithTermRequest;
use App\Http\Requests\Admin\UpdateHadithTermRequest;
use App\Http\Resources\HadithTermResource;
use App\Models\Hadith;
use App\Models\HadithTerm;
use App\Support\ApiResponse;
use OpenApi\Attributes as OA;

class HadithTermController extends Controller
{
    #[OA\Get(
        path: '/admin/hadiths/{hadith}/terms',
        operationId: 'adminListHadithTerms',
        summary: 'List vocabulary terms for a hadith (admin)',
        tags: ['Admin - Hadiths'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Terms.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HadithTerm')),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
        ],
    )]
    public function index(Hadith $hadith)
    {
        return HadithTermResource::collection($hadith->terms()->get());
    }

    #[OA\Post(
        path: '/admin/hadiths/{hadith}/terms',
        operationId: 'adminCreateHadithTerm',
        summary: 'Add a vocabulary term to a hadith (admin)',
        tags: ['Admin - Hadiths'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['term', 'explanation'],
            properties: [
                new OA\Property(property: 'term', type: 'string', example: 'النية'),
                new OA\Property(property: 'explanation', type: 'string', example: 'القصد.'),
            ],
            type: 'object',
        )),
        responses: [
            new OA\Response(response: 201, description: 'Created.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/HadithTerm'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 422, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(StoreHadithTermRequest $request, Hadith $hadith)
    {
        $term = $hadith->terms()->create($request->validated());

        return ApiResponse::success(new HadithTermResource($term), 'Term created successfully.', 201);
    }

    #[OA\Put(
        path: '/admin/hadiths/{hadith}/terms/{term}',
        operationId: 'adminUpdateHadithTerm',
        summary: 'Update a vocabulary term (admin)',
        tags: ['Admin - Hadiths'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'term', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'term', type: 'string', example: 'النية'),
                new OA\Property(property: 'explanation', type: 'string', example: 'القصد.'),
            ],
            type: 'object',
        )),
        responses: [
            new OA\Response(response: 200, description: 'Updated.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/HadithTerm'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
            new OA\Response(response: 422, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function update(UpdateHadithTermRequest $request, Hadith $hadith, HadithTerm $term)
    {
        abort_unless($term->hadith_id === $hadith->id, 404);

        $term->update($request->validated());

        return ApiResponse::success(new HadithTermResource($term), 'Term updated successfully.');
    }

    #[OA\Delete(
        path: '/admin/hadiths/{hadith}/terms/{term}',
        operationId: 'adminDeleteHadithTerm',
        summary: 'Delete a vocabulary term (admin)',
        tags: ['Admin - Hadiths'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'term', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted.', content: new OA\JsonContent(ref: '#/components/schemas/SuccessMessage')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function destroy(Hadith $hadith, HadithTerm $term)
    {
        abort_unless($term->hadith_id === $hadith->id, 404);

        $term->delete();

        return ApiResponse::success(null, 'Term deleted successfully.');
    }
}
