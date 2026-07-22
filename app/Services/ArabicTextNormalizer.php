<?php

namespace App\Services;

/**
 * Normalizes Arabic text for comparison only. The canonical hadith text is
 * never permanently altered — the caller keeps the original for display and
 * audit, and uses the normalized form solely to compare recall.
 *
 * Rules are driven by config('athar.normalization') so domain experts can
 * enable/disable the rules that may hide genuine errors.
 */
class ArabicTextNormalizer
{
    /** Arabic diacritics (tashkeel), including superscript alef. */
    private const DIACRITICS = "/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06DC}\x{06DF}-\x{06E8}\x{06EA}-\x{06ED}]/u";

    private const TATWEEL = "/\x{0640}/u";

    /**
     * @param  array<string,bool>|null  $overrides
     */
    public function normalize(string $text, ?array $overrides = null): string
    {
        $rules = array_merge(config('athar.normalization', []), $overrides ?? []);

        $result = trim($text);

        if ($rules['remove_diacritics'] ?? true) {
            $result = preg_replace(self::DIACRITICS, '', $result);
        }

        if ($rules['remove_tatweel'] ?? true) {
            $result = preg_replace(self::TATWEEL, '', $result);
        }

        if ($rules['normalize_alef'] ?? true) {
            // أ إ آ ٱ -> ا
            $result = str_replace(["\u{0623}", "\u{0625}", "\u{0622}", "\u{0671}"], "\u{0627}", $result);
        }

        if ($rules['normalize_alef_maksura'] ?? false) {
            // ى -> ي
            $result = str_replace("\u{0649}", "\u{064A}", $result);
        }

        if ($rules['normalize_ta_marbuta'] ?? false) {
            // ة -> ه
            $result = str_replace("\u{0629}", "\u{0647}", $result);
        }

        if ($rules['remove_punctuation'] ?? true) {
            // Arabic + Latin punctuation.
            $result = preg_replace("/[\x{060C}\x{061B}\x{061F}\x{066A}-\x{066D}\x{06D4}!-\/:-@\[-`{-~]/u", ' ', $result);
        }

        if ($rules['collapse_whitespace'] ?? true) {
            $result = preg_replace('/\s+/u', ' ', $result);
        }

        return trim($result);
    }

    /**
     * Split normalized text into comparable word tokens.
     *
     * @return array<int,string>
     */
    public function tokenize(string $normalized): array
    {
        if ($normalized === '') {
            return [];
        }

        return preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
