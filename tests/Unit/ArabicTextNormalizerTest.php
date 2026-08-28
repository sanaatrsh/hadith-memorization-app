<?php

namespace Tests\Unit;

use App\Services\ArabicTextNormalizer;
use Tests\TestCase;

class ArabicTextNormalizerTest extends TestCase
{
    private ArabicTextNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ArabicTextNormalizer;
    }

    public function test_it_removes_diacritics_and_tatweel(): void
    {
        // "الأعمَـــال" with fatha and tatweel.
        $input = 'الأعْمَـــال';
        $result = $this->normalizer->normalize($input);

        $this->assertStringNotContainsString("\u{0640}", $result); // no tatweel
        $this->assertStringNotContainsString("\u{064E}", $result); // no fatha
    }

    public function test_it_normalizes_alef_forms(): void
    {
        $this->assertSame('اعمال', $this->normalizer->normalize('أعمال'));
        $this->assertSame('اسلام', $this->normalizer->normalize('إسلام'));
    }

    public function test_it_collapses_whitespace_and_trims(): void
    {
        $this->assertSame('حديث حديث', $this->normalizer->normalize('  حديث   حديث  '));
    }

    public function test_alef_maksura_is_preserved_by_default(): void
    {
        // ى -> ي disabled by default, so it stays.
        $result = $this->normalizer->normalize('على');
        $this->assertStringContainsString("\u{0649}", $result);
    }

    public function test_ta_marbuta_is_folded_to_ha_because_speech_to_text_cannot_tell_them_apart(): void
    {
        // The recognizer transcribes "امراة" as "امراه", so both sides have to
        // normalize to the same thing or the learner is charged for the
        // recognizer's limitation.
        $this->assertSame(
            $this->normalizer->normalize('امرأة'),
            $this->normalizer->normalize('امراه'),
        );

        $this->assertSame(
            $this->normalizer->normalize('الأمة'),
            $this->normalizer->normalize('الامه'),
        );

        $this->assertStringNotContainsString("\u{0629}", $this->normalizer->normalize('كلمة'));
    }

    public function test_tokenize_splits_words(): void
    {
        $this->assertSame(['a', 'b', 'c'], $this->normalizer->tokenize('a b c'));
        $this->assertSame([], $this->normalizer->tokenize(''));
    }
}
