<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PaginationMeta',
    properties: [
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'last_page', type: 'integer', example: 5),
        new OA\Property(property: 'per_page', type: 'integer', example: 20),
        new OA\Property(property: 'total', type: 'integer', example: 92),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SuccessMessage',
    required: ['success', 'message'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'تمت العملية بنجاح.'),
        new OA\Property(property: 'data', type: 'object', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ValidationError',
    required: ['message', 'errors'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'The submitted data is invalid.'),
        new OA\Property(
            property: 'errors',
            type: 'object',
            example: ['recognized_text' => ['The recognized text field is required.']],
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'UnauthenticatedError',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ForbiddenError',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'message', type: 'string', example: 'This action is unauthorized.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'NotFoundError',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Resource not found.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ConflictError',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'message', type: 'string', example: 'Duplicate request.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'RateLimitError',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Too Many Attempts.'),
    ],
    type: 'object',
)]
final class CommonSchemas {}
