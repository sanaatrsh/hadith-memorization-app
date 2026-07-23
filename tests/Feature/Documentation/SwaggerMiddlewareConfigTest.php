<?php

namespace Tests\Feature\Documentation;

use Tests\TestCase;

class SwaggerMiddlewareConfigTest extends TestCase
{
    public function test_swagger_routes_are_public_by_default_so_the_ui_can_load_its_definition(): void
    {
        $middleware = config('l5-swagger.defaults.routes.middleware');

        $this->assertSame([], $middleware['api']);
        $this->assertSame([], $middleware['asset']);
        $this->assertSame([], $middleware['docs']);
        $this->assertSame([], $middleware['oauth2_callback']);
    }
}
