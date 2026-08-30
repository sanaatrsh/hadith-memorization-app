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

        // ة -> ه. Enabled because speech-to-text transcribes a ta marbuta as a
        // ha ("امراة" comes back as "امراه"), and the two are indistinguishable
        // in speech anyway. Leaving it off charged the learner for an error the
        // recognizer made, not one they made.
        'normalize_ta_marbuta' => true,

        // ى -> ي. Still off: unlike the ta marbuta above, this one collapses
        // pairs that are genuinely different words ("على" vs "علي"), and the
        // recognizer distinguishes them well enough to be worth checking.
        'normalize_alef_maksura' => false,
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
    | Written Answer Matching
    |--------------------------------------------------------------------------
    | Exam answers are typed by the learner, never chosen from a list, so short
    | factual answers (narrator / takhrij) are compared token by token: an
    | answer carrying the whole stored answer — or wholly contained in it — is
    | correct. «تميم بن أوس الداري» answers «تميم الداري».
    |
    | Recall answers (complete / recite the matn) are never matched this way;
    | they keep the strict word-sequence comparison.
    */
    'answers' => [
        // What still counts as a short factual answer rather than recall.
        'factual_max_words' => 6,
        'factual_max_chars' => 80,

        // Words that carry no identifying weight in a name or a takhrij. They
        // are dropped from both sides before comparing, and may be written with
        // or without hamza — they are normalized first.
        'ignored_words' => [
            'بن', 'ابن', 'بنت', 'أبو', 'أبي',
            'رضي', 'عنه', 'عنها', 'عنهما', 'تعالى', 'صلى', 'عليه', 'وسلم',
            'رواه', 'أخرجه', 'رواية', 'حديث', 'عن', 'في', 'من',
        ],

        // A shorter token matches a longer one when it is contained in it and
        // at least this long: «مسلم» matches «ومسلم» and «داري» matches
        // «الداري», while «عمر» still does not match «عمرو».
        'containment_min_length' => 4,

        // How much of the stored answer a shorter — but entirely correct —
        // answer must still cover to pass. Keeps a generic word such as «الله»
        // from answering «عبد الله بن عمر».
        'partial_min_coverage' => 0.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Review sessions
    |--------------------------------------------------------------------------
    | A sitting holds what is genuinely due, plus what the learner started
    | working on in the last `recent_days` days (2 = today and yesterday) and
    | has not recited yet. It deliberately does not hold the whole backlog of
    | hadiths a learner has ever touched.
    */
    'reviews' => [
        'recent_days' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Exams
    |--------------------------------------------------------------------------
    | Without an explicit question_count, an exam covers the hadiths added to
    | the book today (a "test what you added today" exam) when there are any,
    | otherwise this many hadiths of the book — never the whole book.
    */
    'exams' => [
        'default_question_count' => 6,
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

    /*
    |--------------------------------------------------------------------------
    | Seeded Administrator
    |--------------------------------------------------------------------------
    | Used by AdminSeeder so a real deployment can seed its own admin account
    | from the environment instead of shipping a hardcoded password. The
    | defaults match the demo admin ArabicDemoSeeder creates for local/testing.
    */
    'admin' => [
        'name' => env('ADMIN_NAME', 'مدير أثر'),
        'email' => env('ADMIN_EMAIL', 'admin@athar.test'),
        'password' => env('ADMIN_PASSWORD', 'password'),
    ],
];
