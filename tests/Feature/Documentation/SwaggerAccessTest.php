<?php

namespace Tests\Feature\Documentation;

use Tests\TestCase;

class SwaggerAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('l5-swagger:generate');
    }

    public function test_swagger_ui_is_accessible_in_testing_environment(): void
    {
        $this->get('/api/documentation')->assertOk();
    }

    public function test_openapi_json_is_accessible_in_testing_environment(): void
    {
        $this->get('/openapi')
            ->assertOk()
            ->assertJsonStructure(['openapi', 'info', 'paths']);
    }
}
