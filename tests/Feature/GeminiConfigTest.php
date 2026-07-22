<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Proves the Gemini HTTP contract can be exercised with a fake, without
 * ever calling the real API. This satisfies the Phase 1 acceptance
 * criterion "A fake Gemini HTTP test succeeds without calling the real API."
 */
class GeminiConfigTest extends TestCase
{
    public function test_gemini_credentials_are_read_from_server_config_only(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');

        $this->assertSame('test-key', config('services.gemini.api_key'));
        $this->assertNotEmpty(config('services.gemini.model'));
    }

    public function test_fake_gemini_request_uses_x_goog_api_key_header(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'ok' => true,
            ], 200),
        ]);

        $response = Http::baseUrl(config('services.gemini.base_url'))
            ->acceptJson()
            ->withHeaders(['x-goog-api-key' => 'test-key'])
            ->post('/interactions', ['model' => config('services.gemini.model')]);

        $this->assertTrue($response->successful());
        $this->assertTrue($response->json('ok'));

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-goog-api-key', 'test-key')
                && ! $request->hasHeader('Authorization');
        });
    }
}
