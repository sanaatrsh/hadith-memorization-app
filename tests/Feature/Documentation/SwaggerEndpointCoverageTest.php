<?php

namespace Tests\Feature\Documentation;

use Tests\TestCase;

class SwaggerEndpointCoverageTest extends TestCase
{
    public function test_generated_paths_cover_the_core_endpoints(): void
    {
        $this->artisan('l5-swagger:generate')->assertExitCode(0);

        $document = json_decode(
            file_get_contents(storage_path('api-docs/api-docs.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $paths = array_keys($document['paths']);

        $expected = [
            '/auth/register',
            '/auth/login',
            '/auth/logout',
            '/books',
            '/hadiths/{hadith}',
            '/memorization/attempts',
            '/reviews/{hadith}/attempts',
            '/user/reviews/due',
            '/exams',
            '/admin/books',
            '/admin/hadiths',
            '/admin/hadiths/{hadith}/audio',
            '/admin/imports/hadiths',
            '/admin/statistics/overview',
        ];

        foreach ($expected as $path) {
            $this->assertContains($path, $paths, "Missing documented path: {$path}");
        }
    }

    public function test_sensitive_terms_are_not_present_in_the_document(): void
    {
        $this->artisan('l5-swagger:generate')->assertExitCode(0);

        $raw = file_get_contents(storage_path('api-docs/api-docs.json'));

        $this->assertStringNotContainsString('x-goog-api-key', $raw);
        $this->assertStringNotContainsString('GEMINI_API_KEY', $raw);
    }
}
