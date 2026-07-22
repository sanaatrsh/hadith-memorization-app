<?php

namespace Tests\Feature\Documentation;

use Tests\TestCase;

class SwaggerGenerationTest extends TestCase
{
    public function test_it_generates_swagger_documentation(): void
    {
        $this->artisan('l5-swagger:generate')->assertExitCode(0);
    }

    public function test_it_generates_the_athar_openapi_document(): void
    {
        $this->artisan('l5-swagger:generate')->assertExitCode(0);

        $path = storage_path('api-docs/api-docs.json');
        $this->assertFileExists($path);

        $document = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('openapi', $document);
        $this->assertArrayHasKey('info', $document);
        $this->assertArrayHasKey('paths', $document);
        $this->assertArrayHasKey('sanctum', $document['components']['securitySchemes']);
        $this->assertSame('http', $document['components']['securitySchemes']['sanctum']['type']);
        $this->assertSame('bearer', $document['components']['securitySchemes']['sanctum']['scheme']);
        $this->assertSame('Athar API', $document['info']['title']);
    }
}
