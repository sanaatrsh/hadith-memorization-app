<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Book',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 2),
        new OA\Property(property: 'title', type: 'string', example: 'رياض الصالحين'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'كتاب في الأحاديث النبوية.'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'sort_order', type: 'integer', example: 1),
        new OA\Property(property: 'cover_url', type: 'string', nullable: true, example: 'http://localhost/storage/2/cover.jpg'),
        new OA\Property(property: 'hadiths_count', type: 'integer', nullable: true, example: 40),
        new OA\Property(property: 'is_started', description: 'Present when authenticated: the user has worked on at least one hadith of this book. There is no per-user book list — every active book is open to everyone.', type: 'boolean', nullable: true, example: true),
        new OA\Property(property: 'progress_count', description: 'Present when authenticated: hadiths of this book the user has a progress record for.', type: 'integer', nullable: true, example: 18),
        new OA\Property(property: 'memorized_count', description: 'Present when authenticated: hadiths of this book the user has memorized.', type: 'integer', nullable: true, example: 12),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'BookRequest',
    required: ['title'],
    properties: [
        new OA\Property(property: 'title', type: 'string', maxLength: 255, example: 'رياض الصالحين'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'كتاب في الأحاديث النبوية.'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'sort_order', type: 'integer', example: 1),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'NarratorRequest',
    required: ['name'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'عمر بن الخطاب'),
        new OA\Property(property: 'biography', type: 'string', nullable: true, example: 'أمير المؤمنين.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'HadithRequest',
    required: ['book_id', 'title', 'text'],
    properties: [
        new OA\Property(property: 'book_id', type: 'integer', example: 2),
        new OA\Property(property: 'narrator_id', type: 'integer', nullable: true, example: 3),
        new OA\Property(property: 'title', type: 'string', maxLength: 255, example: 'إنما الأعمال بالنيات'),
        new OA\Property(property: 'text', type: 'string', example: 'إنما الأعمال بالنيات وإنما لكل امرئ ما نوى'),
        new OA\Property(property: 'source', type: 'string', nullable: true, example: 'صحيح البخاري'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'sort_order', type: 'integer', example: 1),
    ],
    type: 'object',
)]
final class BookSchemas {}
