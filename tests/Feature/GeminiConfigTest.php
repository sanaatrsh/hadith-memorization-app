<?php

namespace Tests\Feature;

use App\Contracts\Ai\ExamAnswerGrader;
use App\Contracts\Ai\HadithEvaluator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pins the Gemini HTTP contract.
 *
 * These used to assert only that a faked request could be sent, which is why a
 * payload the real API rejects went unnoticed: every AI failure degrades to the
 * deterministic comparison, so nothing broke visibly. The assertions below
 * check the request actually matches Gemini's `generateContent` contract.
 */
class GeminiConfigTest extends TestCase
{
    private function fakeSuccess(array $payload): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-test');

        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'responseId' => 'resp_1',
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]]],
                ]],
            ], 200),
        ]);
    }

    public function test_a_batch_is_graded_concurrently_in_one_pool(): void
    {
        // The reason this exists: grading a whole exam one call after another
        // made the request as slow as their sum, and the gateway answered the
        // app with a 504 before any score came back.
        $this->fakeSuccess(['is_correct' => true, 'score' => 100, 'feedback_ar' => 'صحيح']);

        $contexts = [];

        foreach (['أبي ذر', 'عمر', 'تميم الداري'] as $index => $answer) {
            $contexts['item-'.$index] = [
                'question_text' => 'من هو راوي هذا الحديث؟',
                'correct_answer' => 'أبو ذر الغفاري',
                'answer_text' => $answer,
                'question_kind' => 'factual',
            ];
        }

        $grades = app(ExamAnswerGrader::class)->gradeMany($contexts);

        // One call per answer, and every result kept its own key.
        Http::assertSentCount(3);
        $this->assertSame(['item-0', 'item-1', 'item-2'], array_keys($grades));

        foreach ($grades as $grade) {
            $this->assertTrue($grade->available);
            $this->assertSame(100, $grade->score);
        }
    }

    public function test_an_empty_batch_never_reaches_the_network(): void
    {
        $this->fakeSuccess(['is_correct' => true, 'score' => 100, 'feedback_ar' => 'صحيح']);

        $this->assertSame([], app(ExamAnswerGrader::class)->gradeMany([]));

        Http::assertNothingSent();
    }

    public function test_a_batch_without_a_key_reports_every_entry_unavailable(): void
    {
        config()->set('services.gemini.api_key', '');
        Http::preventStrayRequests();

        $grades = app(ExamAnswerGrader::class)->gradeMany([
            'a' => ['question_text' => 'س', 'correct_answer' => 'ج', 'answer_text' => 'ج', 'question_kind' => 'factual'],
            'b' => ['question_text' => 'س', 'correct_answer' => 'ج', 'answer_text' => 'ج', 'question_kind' => 'factual'],
        ]);

        $this->assertSame(['a', 'b'], array_keys($grades));

        foreach ($grades as $grade) {
            $this->assertFalse($grade->available);
            $this->assertSame('missing_api_key', $grade->failureCode);
        }

        Http::assertNothingSent();
    }

    public function test_gemini_credentials_are_read_from_server_config_only(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');

        $this->assertSame('test-key', config('services.gemini.api_key'));
        $this->assertNotEmpty(config('services.gemini.model'));
    }

    public function test_it_calls_the_generate_content_endpoint_for_the_configured_model(): void
    {
        $this->fakeSuccess(['is_correct' => true, 'score' => 100, 'feedback_ar' => 'صحيح']);

        app(ExamAnswerGrader::class)->grade([
            'question_text' => 'من هو راوي هذا الحديث؟',
            'correct_answer' => 'أبو ذر الغفاري',
            'answer_text' => 'أبي ذر',
            'question_kind' => 'factual',
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test:generateContent'
                && $request->method() === 'POST';
        });
    }

    public function test_the_request_uses_the_api_key_header_and_never_a_bearer_token(): void
    {
        $this->fakeSuccess(['is_correct' => true, 'score' => 100, 'feedback_ar' => 'صحيح']);

        app(ExamAnswerGrader::class)->grade([
            'question_text' => 'x',
            'correct_answer' => 'أبو ذر الغفاري',
            'answer_text' => 'أبي ذر',
            'question_kind' => 'factual',
        ]);

        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'test-key')
            && ! $request->hasHeader('Authorization'));
    }

    public function test_the_payload_matches_the_generate_content_schema(): void
    {
        $this->fakeSuccess(['is_correct' => true, 'score' => 100, 'feedback_ar' => 'صحيح']);

        app(ExamAnswerGrader::class)->grade([
            'question_text' => 'من هو راوي هذا الحديث؟',
            'correct_answer' => 'أبو ذر الغفاري',
            'answer_text' => 'أبي ذر',
            'question_kind' => 'factual',
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            // The reference answer and the learner's answer both have to reach
            // the model, or it cannot compare them.
            $userText = $body['contents'][0]['parts'][0]['text'] ?? '';

            return isset($body['systemInstruction']['parts'][0]['text'])
                && ($body['contents'][0]['role'] ?? null) === 'user'
                && str_contains($userText, 'أبو ذر الغفاري')
                && str_contains($userText, 'أبي ذر')
                && ($body['generationConfig']['responseMimeType'] ?? null) === 'application/json'
                && ($body['generationConfig']['responseSchema']['type'] ?? null) === 'OBJECT'
                // Fields the API rejects with HTTP 400 must not be sent.
                && ! array_key_exists('input', $body)
                && ! array_key_exists('response_format', $body)
                && ! array_key_exists('store', $body)
                && ! array_key_exists('model', $body);
        });
    }

    public function test_the_response_schema_stays_inside_the_subset_gemini_accepts(): void
    {
        $this->fakeSuccess(['is_correct' => true, 'score' => 100, 'feedback_ar' => 'صحيح']);

        app(ExamAnswerGrader::class)->grade([
            'question_text' => 'x',
            'correct_answer' => 'أبو ذر الغفاري',
            'answer_text' => 'أبي ذر',
            'question_kind' => 'factual',
        ]);

        Http::assertSent(function ($request) {
            $schema = $request->data()['generationConfig']['responseSchema'] ?? [];

            $types = [];
            $bounds = [];
            array_walk_recursive($schema, function ($value, $key) use (&$types, &$bounds) {
                if ($key === 'type') {
                    $types[] = $value;
                }
                if (in_array($key, ['minimum', 'maximum'], true)) {
                    $bounds[] = $key;
                }
            });

            // Type values are the upper-case Type enum, and numeric bounds are
            // not part of the accepted subset.
            return $bounds === []
                && $types !== []
                && $types === array_map('strtoupper', $types);
        });
    }

    public function test_the_recitation_evaluator_uses_the_same_contract(): void
    {
        $this->fakeSuccess([
            'is_textually_correct' => true,
            'score' => 95,
            'verdict' => 'correct',
            'missing_words' => [],
            'extra_words' => [],
            'incorrect_segments' => [],
            'feedback_ar' => 'تسميع جيد',
            'recommended_action' => 'continue',
        ]);

        $result = app(HadithEvaluator::class)->evaluate([
            'canonical_text' => 'انما الاعمال بالنيات',
            'transcript' => 'انما الاعمال بالنيات',
            'comparison' => ['score' => 100],
        ]);

        $this->assertTrue($result->available);
        $this->assertSame(95, $result->score);

        Http::assertSent(fn ($request) => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test:generateContent'
            && isset($request->data()['contents'][0]['parts'][0]['text']));
    }

    public function test_an_api_error_message_is_kept_so_a_misconfiguration_is_diagnosable(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-does-not-exist');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'models/gemini-does-not-exist is not found.'],
            ], 404),
        ]);

        $grade = app(ExamAnswerGrader::class)->grade([
            'question_text' => 'x',
            'correct_answer' => 'أبو ذر الغفاري',
            'answer_text' => 'أبي ذر',
            'question_kind' => 'factual',
        ]);

        $this->assertFalse($grade->available);
        $this->assertSame('gemini_http_error', $grade->failureCode);
        $this->assertStringContainsString('404', (string) $grade->failureMessage);
        $this->assertStringContainsString('is not found', (string) $grade->failureMessage);
    }

    public function test_gemini_grades_a_shorter_form_of_the_same_name_as_correct(): void
    {
        // The case from the app: the stored answer is "أبو ذر الغفاري" and the
        // learner typed "أبي ذر".
        $this->fakeSuccess([
            'is_correct' => true,
            'score' => 100,
            'feedback_ar' => 'إجابة صحيحة، نفس الراوي باسم مختصر.',
        ]);

        $grade = app(ExamAnswerGrader::class)->grade([
            'question_text' => 'من هو راوي هذا الحديث؟',
            'correct_answer' => 'أبو ذر الغفاري',
            'answer_text' => 'أبي ذر',
            'question_kind' => 'factual',
        ]);

        $this->assertTrue($grade->available);
        $this->assertTrue($grade->isCorrect);
        $this->assertSame(100, $grade->score);
        $this->assertSame('gemini-test', $grade->model);
    }
}
