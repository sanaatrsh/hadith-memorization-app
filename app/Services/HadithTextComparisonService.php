<?php

namespace App\Services;

/**
 * Deterministic Arabic word-sequence comparison. This produces the
 * AUTHORITATIVE score. Gemini must not overwrite it.
 *
 * It aligns the normalized reference and transcript with a word-level
 * edit-distance (Levenshtein) alignment and classifies each operation as a
 * match, substitution, missing word, or extra word.
 */
class HadithTextComparisonService
{
    public function __construct(private readonly ArabicTextNormalizer $normalizer) {}

    /**
     * @return array{
     *   score:int, matched_words:int, total_reference_words:int,
     *   missing_words:array<int,string>, extra_words:array<int,string>,
     *   substitutions:array<int,array{expected:string,received:string,position:int}>,
     *   order_issues:array<int,string>
     * }
     */
    public function compare(string $normalizedReference, string $normalizedTranscript): array
    {
        $ref = $this->normalizer->tokenize($normalizedReference);
        $hyp = $this->normalizer->tokenize($normalizedTranscript);

        $m = count($ref);
        $n = count($hyp);

        if ($m === 0) {
            return $this->emptyReferenceResult($hyp);
        }

        [$matched, $missing, $extra, $substitutions] = $this->align($ref, $hyp);

        // Words classified as both missing and extra are likely reordered.
        $orderIssues = array_values(array_intersect($missing, $extra));
        if ($orderIssues !== []) {
            $missing = array_values(array_diff($missing, $orderIssues));
            $extra = array_values(array_diff($extra, $orderIssues));
        }

        $score = (int) round(($matched / $m) * 100);
        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'matched_words' => $matched,
            'total_reference_words' => $m,
            'missing_words' => $missing,
            'extra_words' => $extra,
            'substitutions' => $substitutions,
            'order_issues' => $orderIssues,
        ];
    }

    /**
     * @param  array<int,string>  $ref
     * @param  array<int,string>  $hyp
     * @return array{0:int,1:array<int,string>,2:array<int,string>,3:array<int,array{expected:string,received:string,position:int}>}
     */
    private function align(array $ref, array $hyp): array
    {
        $m = count($ref);
        $n = count($hyp);

        // dp[i][j] = edit distance between ref[0..i) and hyp[0..j).
        $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
        for ($i = 0; $i <= $m; $i++) {
            $dp[$i][0] = $i;
        }
        for ($j = 0; $j <= $n; $j++) {
            $dp[0][$j] = $j;
        }

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                $cost = $ref[$i - 1] === $hyp[$j - 1] ? 0 : 1;
                $dp[$i][$j] = min(
                    $dp[$i - 1][$j] + 1,       // deletion  -> missing reference word
                    $dp[$i][$j - 1] + 1,       // insertion -> extra transcript word
                    $dp[$i - 1][$j - 1] + $cost // match or substitution
                );
            }
        }

        // Backtrace.
        $matched = 0;
        $missing = [];
        $extra = [];
        $substitutions = [];

        $i = $m;
        $j = $n;
        while ($i > 0 || $j > 0) {
            $cost = ($i > 0 && $j > 0 && $ref[$i - 1] === $hyp[$j - 1]) ? 0 : 1;

            if ($i > 0 && $j > 0 && $dp[$i][$j] === $dp[$i - 1][$j - 1] + $cost) {
                if ($cost === 0) {
                    $matched++;
                } else {
                    $substitutions[] = [
                        'expected' => $ref[$i - 1],
                        'received' => $hyp[$j - 1],
                        'position' => $i - 1,
                    ];
                }
                $i--;
                $j--;
            } elseif ($i > 0 && $dp[$i][$j] === $dp[$i - 1][$j] + 1) {
                $missing[] = $ref[$i - 1];
                $i--;
            } else {
                $extra[] = $hyp[$j - 1];
                $j--;
            }
        }

        return [
            $matched,
            array_reverse($missing),
            array_reverse($extra),
            array_reverse($substitutions),
        ];
    }

    /**
     * @param  array<int,string>  $hyp
     */
    private function emptyReferenceResult(array $hyp): array
    {
        return [
            'score' => 0,
            'matched_words' => 0,
            'total_reference_words' => 0,
            'missing_words' => [],
            'extra_words' => $hyp,
            'substitutions' => [],
            'order_issues' => [],
        ];
    }
}
