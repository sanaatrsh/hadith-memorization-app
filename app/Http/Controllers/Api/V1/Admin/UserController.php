<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    #[OA\Get(
        path: '/admin/users',
        operationId: 'adminListUsers',
        summary: 'List users (admin)',
        tags: ['Admin - Users'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Paginated users.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/User')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
        ],
    )]
    public function index()
    {
        $users = User::orderByDesc('id')->paginate(20);

        return UserResource::collection($users);
    }

    #[OA\Get(
        path: '/admin/users/{user}',
        operationId: 'adminShowUser',
        summary: 'Get a user (admin)',
        tags: ['Admin - Users'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'User.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/User'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function show(User $user)
    {
        return ApiResponse::success(new UserResource($user), 'User retrieved successfully.');
    }

    #[OA\Patch(
        path: '/admin/users/{user}/status',
        operationId: 'adminUpdateUserStatus',
        summary: 'Activate or deactivate a user (admin)',
        tags: ['Admin - Users'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['is_active'],
            properties: [new OA\Property(property: 'is_active', type: 'boolean', example: false)],
            type: 'object',
        )),
        responses: [
            new OA\Response(response: 200, description: 'Status updated.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/User'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
            new OA\Response(response: 422, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function updateStatus(UpdateUserStatusRequest $request, User $user)
    {
        $user->update(['is_active' => $request->boolean('is_active')]);

        return ApiResponse::success(new UserResource($user), 'User status updated successfully.');
    }
}
