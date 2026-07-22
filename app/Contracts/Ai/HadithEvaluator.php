<?php

namespace App\Contracts\Ai;

use App\Data\Ai\HadithEvaluationData;

interface HadithEvaluator
{
    /**
     * Ask the AI provider for a structured review of a memorization attempt.
     *
     * Implementations MUST validate the provider response and return a
     * typed result. On any failure they should return a result whose
     * `available` flag is false rather than throwing, so the caller can
     * fall back to the deterministic report.
     *
     * @param  array<string,mixed>  $context  Minimal relevant data (hadith, transcript, deterministic report).
     */
    public function evaluate(array $context): HadithEvaluationData;
}
