<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Athar API',
    description: 'Arabic-first API for hadith memorization, review scheduling, transcript evaluation, exams, and administration. '
        .'The MVP receives text extracted by Flutter. It evaluates missing, extra, replaced, and reordered words. '
        .'It does not evaluate pronunciation because the original audio is not submitted.',
)]
#[OA\Server(url: '/api/v1', description: 'Current API server')]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Bearer Token',
    description: 'Enter the Laravel Sanctum token returned by login/register.',
)]
#[OA\Tag(name: 'System', description: 'Health and service status.')]
#[OA\Tag(name: 'Authentication', description: 'Registration, login, logout, and profile.')]
#[OA\Tag(name: 'Books', description: 'Public book catalogue (active content).')]
#[OA\Tag(name: 'Hadiths', description: 'Public hadith content (active).')]
#[OA\Tag(name: 'My Books', description: 'Authenticated user learning selection.')]
#[OA\Tag(name: 'Memorization', description: 'Transcript evaluation and progress dashboard.')]
#[OA\Tag(name: 'Reviews', description: 'Spaced-repetition review sessions.')]
#[OA\Tag(name: 'Exams', description: 'Written and voice final exams.')]
#[OA\Tag(name: 'Admin - Books', description: 'Administrator book management.')]
#[OA\Tag(name: 'Admin - Narrators', description: 'Administrator narrator management.')]
#[OA\Tag(name: 'Admin - Hadiths', description: 'Administrator hadith, terms, aids, and audio management.')]
#[OA\Tag(name: 'Admin - Users', description: 'Administrator user and progress management.')]
#[OA\Tag(name: 'Admin - Imports', description: 'Spreadsheet import of hadith content.')]
#[OA\Tag(name: 'Admin - Statistics', description: 'Administrator reporting.')]
final class OpenApiDefinition {}
