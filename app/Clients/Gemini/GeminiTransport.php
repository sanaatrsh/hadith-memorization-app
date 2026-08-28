<?php

namespace App\Clients\Gemini;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place that speaks to the Gemini REST API.
 *
 * Both AI use cases (recitation feedback and exam grading) send the same kind
 * of request — a system instruction, one user turn, and a required JSON schema
 * — so the wire contract lives here instead of being duplicated per client.
 *
 * The contract is Gemini's `generateContent`:
 *
 *   POST {base_url}/models/{model}:generateContent
 *   x-goog-api-key: <key>
 *   {
 *     "systemInstruction": {"parts": [{"text": "..."}]},
 *     "contents": [{"role": "user", "parts": [{"text": "..."}]}],
 *     "generationConfig": {
 *       "temperature": 0.1,
 *       "responseMimeType": "application/json",
 *       "responseSchema": {...}
 *     }
 *   }
 *
 * Two things the API is strict about, and the reason a hand-rolled payload
 * fails against it: unknown top-level fields are rejected with HTTP 400, and
 * `responseSchema` accepts only a subset of JSON Schema whose `type` values are
 * the upper-case Type enum (OBJECT, STRING, INTEGER, BOOLEAN, ARRAY). Numeric
 * bounds such as `minimum` are not part of that subset, so ranges are stated in
 * the prompt and clamped when the response is parsed.
 */
class GeminiTransport
{
    /**
     * Send one structured-output request.
     *
     * Never throws: a failure comes back as a result whose `body` is null and
     * whose failure code the caller maps onto its own "unavailable" DTO, so an
     * AI outage degrades instead of breaking the request.
     *
     * @param  array<string,mixed>  $responseSchema
     * @return array{body:?array<string,mixed>, model:string, failure_code:?string, failure_message:?string}
     */
    public function send(string $systemInstruction, string $input, array $responseSchema): array
    {
        $model = (string) config('services.gemini.model');

        if (empty(config('services.gemini.api_key'))) {
            return $this->failure($model, 'missing_api_key', 'Gemini API key is not configured.');
        }

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $input]],
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
                'responseSchema' => $responseSchema,
            ],
        ];

        try {
            $response = Http::baseUrl(config('services.gemini.base_url'))
                ->acceptJson()
                ->withHeaders(['x-goog-api-key' => config('services.gemini.api_key')])
                ->connectTimeout((int) config('services.gemini.connect_timeout'))
                ->timeout((int) config('services.gemini.timeout'))
                ->retry((int) config('services.gemini.retry_times'), 500, throw: false)
                ->post("/models/{$model}:generateContent", $payload);
        } catch (ConnectionException $e) {
            return $this->failure($model, 'gemini_timeout', 'Gemini request timed out.');
        } catch (Throwable $e) {
            Log::warning('gemini.request.failed', ['message' => $e->getMessage()]);

            return $this->failure($model, 'gemini_error', 'Gemini request failed.');
        }

        if ($response->status() === 429) {
            return $this->failure($model, 'gemini_rate_limited', 'Gemini rate limit reached.');
        }

        if ($response->failed()) {
            // The API explains a rejected request in `error.message`; keeping it
            // is the difference between "the AI did not answer" and knowing the
            // model name or a payload field was wrong.
            $reason = (string) ($response->json('error.message') ?? '');

            Log::warning('gemini.request.http_error', [
                'status' => $response->status(),
                'model' => $model,
                'reason' => $reason,
            ]);

            return $this->failure(
                $model,
                'gemini_http_error',
                trim('Gemini returned HTTP '.$response->status().($reason !== '' ? ': '.$reason : '')),
            );
        }

        return [
            'body' => $response->json() ?? [],
            'model' => $model,
            'failure_code' => null,
            'failure_message' => null,
        ];
    }

    /**
     * @return array{body:null, model:string, failure_code:string, failure_message:string}
     */
    private function failure(string $model, string $code, string $message): array
    {
        return [
            'body' => null,
            'model' => $model,
            'failure_code' => $code,
            'failure_message' => $message,
        ];
    }
}
