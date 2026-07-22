<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Arabic Normalization
    |--------------------------------------------------------------------------
    | Rules used ONLY for comparison. The canonical hadith text is never
    | permanently altered. Some rules can hide real errors, so they are
    | configurable and default to the safer behaviour.
    */
    'normalization' => [
        'collapse_whitespace' => true,
        'remove_punctuation' => true,
        'remove_diacritics' => true,   // tashkeel
        'remove_tatweel' => true,      // ـ
        'normalize_alef' => true,      // أ إ آ -> ا
        // The two below can mask genuine mistakes; disabled by default.
        'normalize_alef_maksura' => false, // ى -> ي
        'normalize_ta_marbuta' => false,   // ة -> ه
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring Thresholds (0-100)
    |--------------------------------------------------------------------------
    */
    'scoring' => [
        'passing' => 90,        // >= passing  => advance / correct
        'acceptable_min' => 75, // acceptable_min..passing-1 => keep level
    ],

    /*
    |--------------------------------------------------------------------------
    | Simple Spaced Repetition (SRS v1)
    |--------------------------------------------------------------------------
    | srs_level => number of days until the next review.
    */
    'srs' => [
        'intervals' => [
            0 => 1,
            1 => 3,
            2 => 7,
            3 => 14,
            4 => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Attempt input limits
    |--------------------------------------------------------------------------
    */
    'attempts' => [
        'max_recognized_text_length' => 10000,

        // Transcript retention: number of days to keep raw/normalized transcript
        // text on attempts before it is pruned. Null disables pruning. Scores,
        // verdicts and reports are always kept.
        'transcript_retention_days' => (int) env('ATTEMPT_TRANSCRIPT_RETENTION_DAYS', 180),
    ],
];
