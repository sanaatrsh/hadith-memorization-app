<?php

declare(strict_types=1);

/**
 * The Arabic question bank and two worked exams over "الأربعون النووية".
 *
 * The bank holds wordings only. A question asks about "هذا الحديث" and never
 * names one, because a question row is shared by every hadith it is asked
 * about — which hadith an item is about is stored on the item, alongside that
 * hadith's own reference answer. That is the one-to-many between questions and
 * answers, and it is why each entry below lists several `answers` under a
 * single `template`.
 *
 * The wording still decides the answer the runtime derives: a prompt
 * containing «راوي» is answered by the narrator, one containing «مصدر» by the
 * takhrij, one containing «مقدمة» by the intro line, anything else by the matn.
 *
 * `submitted` is what the learner typed or recited — deliberately imperfect in
 * places, and null for an item they have not reached yet. The seeder never
 * invents a score: it runs those answers through the real ExamAnswerEvaluator,
 * so every score and report matches what the API would have produced.
 *
 * Each book has one exam per learner, so the two exams below belong to two
 * different learners rather than piling up on the same book.
 *
 * @return array{
 *     book:string,
 *     templates: array<int, array{type:string, prompt:string}>,
 *     exams: array<int, array{
 *         learner:string, status:string, started_days_ago:int, completed_days_ago:int|null,
 *         questions: array<int, array{
 *             template:string,
 *             answers: array<int, array{hadith:string, submitted:string|null}>
 *         }>
 *     }>
 * }
 */
return [
    'book' => 'الأربعون النووية',

    'templates' => [
        // Factual — the narrator. The word «راوي» must appear so the derived
        // answer is the companion's name and not the matn.
        ['type' => 'written', 'prompt' => 'من هو راوي هذا الحديث؟'],
        ['type' => 'written', 'prompt' => 'اذكر اسم راوي هذا الحديث.'],

        // Factual — the takhrij. Keyed on the word «مصدر».
        ['type' => 'written', 'prompt' => 'ما مصدر تخريج هذا الحديث؟'],
        ['type' => 'written', 'prompt' => 'ما مصدر هذا الحديث؟'],

        // Factual — the isnad/context line. Keyed on the word «مقدمة».
        ['type' => 'written', 'prompt' => 'اذكر مقدمة هذا الحديث.'],

        // Recall — answered by the full matn and scored word by word.
        ['type' => 'written', 'prompt' => 'أكمل نص هذا الحديث.'],
        ['type' => 'written', 'prompt' => 'اكتب نص هذا الحديث كاملا.'],

        // Recitation.
        ['type' => 'voice', 'prompt' => 'اتل هذا الحديث من حفظك.'],
        ['type' => 'voice', 'prompt' => 'سمّع هذا الحديث كاملا من حفظك.'],
    ],

    'exams' => [
        // A finished exam: three flawless factual answers and two recall
        // answers, one of which falls under the passing mark.
        [
            'learner' => 'primary',
            'status' => 'completed',
            'started_days_ago' => 3,
            'completed_days_ago' => 3,
            'questions' => [
                [
                    // One wording, two hadiths, two different correct answers.
                    'template' => 'من هو راوي هذا الحديث؟',
                    'answers' => [
                        [
                            'hadith' => 'إنما الأعمال بالنيات',
                            'submitted' => 'عمر بن الخطاب',
                        ],
                        [
                            // The fuller lineage of «تميم الداري».
                            'hadith' => 'الدين النصيحة',
                            'submitted' => 'تميم بن أوس الداري',
                        ],
                    ],
                ],
                [
                    'template' => 'ما مصدر تخريج هذا الحديث؟',
                    'answers' => [
                        [
                            'hadith' => 'لا تغضب',
                            'submitted' => 'رواه البخاري',
                        ],
                    ],
                ],
                [
                    'template' => 'أكمل نص هذا الحديث.',
                    'answers' => [
                        [
                            // «ليصمت» recalled as «يسكت» — one substitution.
                            'hadith' => 'من كان يؤمن بالله واليوم الآخر',
                            'submitted' => 'من كان يؤمن بالله واليوم الآخر فليقل خيرا أو يسكت، ومن كان يؤمن بالله واليوم الآخر فليكرم جاره، ومن كان يؤمن بالله واليوم الآخر فليكرم ضيفه',
                        ],
                        [
                            // «رضي الله عنهما» and «وخذ من صحتك لمرضك» both missing.
                            'hadith' => 'كن في الدنيا كأنك غريب',
                            'submitted' => 'أخذ رسول الله صلى الله عليه وسلم بمنكبي فقال: كن في الدنيا كأنك غريب أو عابر سبيل. وكان ابن عمر يقول: إذا أمسيت فلا تنتظر الصباح، وإذا أصبحت فلا تنتظر المساء، ومن حياتك لموتك',
                        ],
                    ],
                ],
            ],
        ],

        // An exam still under way: one item of each question is answered, the
        // other is still open — which is what an unfinished exam looks like
        // once items, not questions, are what get answered.
        [
            'learner' => 'secondary',
            'status' => 'in_progress',
            'started_days_ago' => 0,
            'completed_days_ago' => null,
            'questions' => [
                [
                    'template' => 'اذكر اسم راوي هذا الحديث.',
                    'answers' => [
                        [
                            'hadith' => 'لا ضرر ولا ضرار',
                            'submitted' => 'أبو سعيد الخدري',
                        ],
                        [
                            'hadith' => 'اتق الله حيثما كنت',
                            'submitted' => null,
                        ],
                    ],
                ],
                [
                    'template' => 'اتل هذا الحديث من حفظك.',
                    'answers' => [
                        [
                            // «ولرسوله» missing from the chain of five.
                            'hadith' => 'الدين النصيحة',
                            'submitted' => 'الدين النصيحة. قلنا: لمن؟ قال: لله ولكتابه ولأئمة المسلمين وعامتهم',
                        ],
                        [
                            'hadith' => 'احفظ الله يحفظك',
                            'submitted' => null,
                        ],
                    ],
                ],
            ],
        ],
    ],
];
