<?php

namespace Tests\Unit;

use App\Services\ArabicTextNormalizer;
use App\Services\HadithTextComparisonService;
use Tests\TestCase;

class HadithTextComparisonServiceTest extends TestCase
{
    private HadithTextComparisonService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HadithTextComparisonService(new ArabicTextNormalizer);
    }

    public function test_identical_text_scores_100(): void
    {
        $result = $this->service->compare('انما الاعمال بالنيات', 'انما الاعمال بالنيات');

        $this->assertSame(100, $result['score']);
        $this->assertSame(3, $result['matched_words']);
        $this->assertSame([], $result['missing_words']);
        $this->assertSame([], $result['extra_words']);
    }

    public function test_missing_word_is_detected(): void
    {
        $result = $this->service->compare('انما الاعمال بالنيات', 'الاعمال بالنيات');

        $this->assertContains('انما', $result['missing_words']);
        $this->assertSame(2, $result['matched_words']);
        $this->assertSame(67, $result['score']); // round(2/3*100)
    }

    public function test_extra_word_is_detected(): void
    {
        $result = $this->service->compare('الاعمال بالنيات', 'الاعمال بالنيات جدا');

        $this->assertContains('جدا', $result['extra_words']);
        $this->assertSame(100, $result['score']); // all reference words matched
    }

    public function test_substitution_is_detected(): void
    {
        $result = $this->service->compare('انما الاعمال بالنيات', 'انما العمل بالنيات');

        $this->assertNotEmpty($result['substitutions']);
        $this->assertSame('الاعمال', $result['substitutions'][0]['expected']);
        $this->assertSame('العمل', $result['substitutions'][0]['received']);
    }

    public function test_empty_transcript_scores_zero(): void
    {
        $result = $this->service->compare('انما الاعمال بالنيات', '');

        $this->assertSame(0, $result['score']);
    }
}
