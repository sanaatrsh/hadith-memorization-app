<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ImportRowError',
    properties: [
        new OA\Property(property: 'row', type: 'integer', example: 3),
        new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'string'), example: ['The hadith text field is required.']),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ImportResult',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Import processed.'),
        new OA\Property(
            property: 'data',
            properties: [
                new OA\Property(property: 'import_id', type: 'integer', example: 4),
                new OA\Property(property: 'status', type: 'string', enum: ['completed', 'completed_with_errors', 'failed'], example: 'completed_with_errors'),
                new OA\Property(property: 'total_rows', type: 'integer', example: 10),
                new OA\Property(property: 'imported_rows', type: 'integer', example: 9),
                new OA\Property(property: 'failed_rows', type: 'integer', example: 1),
                new OA\Property(property: 'errors', type: 'array', items: new OA\Items(ref: '#/components/schemas/ImportRowError')),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
final class ImportSchemas {}
