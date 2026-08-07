<?php

declare(strict_types=1);

/**
 * The Arabic question bank and two worked exams over "الأربعون النووية".
 *
 * Templates use the placeholders GenerateExam understands: {title}, {source},
 * {narrator} and {opening}. Their wording also decides the answer the runtime
 * derives (a prompt containing «راوي» is answered by the narrator, one containing
 * «مصدر» by the takhrij, anything else by the matn), so `answer_from` below is
 * kept in step with that rule.
 *
 * `submitted` is what the learner typed or recited — deliberately imperfect in
 * places. The seeder never invents a score: it runs those answers through the
 * real ExamAnswerEvaluator, so every score and report matches what the API
 * would have produced.
 *
 * @return array{
 *     book:string,
 *     templates: array<int, array{type:string, prompt:string}>,
 *     exams: array<int, array{
 *         status:string, started_days_ago:int, completed_days_ago:int|null,
 *         questions: array<int, array{
 *             template:string, hadith:string,
 *             answer_from:'narrator'|'source'|'text', submitted:string|null
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
        ['type' => 'written', 'prompt' => 'اذكر اسم راوي الحديث الذي عنوانه: {title}'],
        ['type' => 'written', 'prompt' => 'من راوي الحديث الذي مطلعه: {opening}'],

        // Factual — the takhrij. Keyed on the word «مصدر».
        ['type' => 'written', 'prompt' => 'ما مصدر هذا الحديث؟'],
        ['type' => 'written', 'prompt' => 'ما مصدر تخريج الحديث الذي عنوانه: {title}؟'],

        // Recall — answered by the full matn and scored word by word.
        ['type' => 'written', 'prompt' => 'أكمل الحديث الذي يبدأ بـ: {opening}'],
        ['type' => 'written', 'prompt' => 'اكتب نص الحديث الذي عنوانه: {title} كاملا'],
        ['type' => 'written', 'prompt' => 'اكتب الحديث الذي رواه {narrator} وعنوانه: {title}'],

        // Recitation.
        ['type' => 'voice', 'prompt' => 'اذكر الحديث كاملا من عنوانه: {title}'],
        ['type' => 'voice', 'prompt' => 'سمع من حفظك الحديث الذي عنوانه: {title}'],
        ['type' => 'voice', 'prompt' => 'اتل الحديث الذي يبدأ بـ: {opening} تلاوة كاملة'],
    ],

    'exams' => [
        // A finished exam: two flawless factual answers, two near-perfect
        // recitations, and one recall that falls under the passing mark.
        [
            'status' => 'completed',
            'started_days_ago' => 3,
            'completed_days_ago' => 3,
            'questions' => [
                [
                    'template' => 'اذكر اسم راوي الحديث الذي عنوانه: {title}',
                    'hadith' => 'إنما الأعمال بالنيات',
                    'answer_from' => 'narrator',
                    'submitted' => 'عمر بن الخطاب',
                ],
                [
                    'template' => 'ما مصدر تخريج الحديث الذي عنوانه: {title}؟',
                    'hadith' => 'الدين النصيحة',
                    'answer_from' => 'source',
                    'submitted' => 'رواه مسلم',
                ],
                [
                    // «ليصمت» recalled as «يسكت» — one substitution.
                    'template' => 'أكمل الحديث الذي يبدأ بـ: {opening}',
                    'hadith' => 'من كان يؤمن بالله واليوم الآخر',
                    'answer_from' => 'text',
                    'submitted' => 'من كان يؤمن بالله واليوم الآخر فليقل خيرا أو يسكت، ومن كان يؤمن بالله واليوم الآخر فليكرم جاره، ومن كان يؤمن بالله واليوم الآخر فليكرم ضيفه',
                ],
                [
                    // «شهادة» dropped from the recitation.
                    'template' => 'اذكر الحديث كاملا من عنوانه: {title}',
                    'hadith' => 'بني الإسلام على خمس',
                    'answer_from' => 'text',
                    'submitted' => 'بني الإسلام على خمس: أن لا إله إلا الله وأن محمدا رسول الله، وإقام الصلاة، وإيتاء الزكاة، وحج البيت، وصوم رمضان',
                ],
                [
                    // «رضي الله عنهما» and «وخذ من صحتك لمرضك» both missing.
                    'template' => 'اكتب نص الحديث الذي عنوانه: {title} كاملا',
                    'hadith' => 'كن في الدنيا كأنك غريب',
                    'answer_from' => 'text',
                    'submitted' => 'أخذ رسول الله صلى الله عليه وسلم بمنكبي فقال: كن في الدنيا كأنك غريب أو عابر سبيل. وكان ابن عمر يقول: إذا أمسيت فلا تنتظر الصباح، وإذا أصبحت فلا تنتظر المساء، ومن حياتك لموتك',
                ],
            ],
        ],

        // An exam still under way: the first two questions are answered, the
        // recitation and the last factual question are still open.
        [
            'status' => 'in_progress',
            'started_days_ago' => 0,
            'completed_days_ago' => null,
            'questions' => [
                [
                    'template' => 'من راوي الحديث الذي مطلعه: {opening}',
                    'hadith' => 'لا ضرر ولا ضرار',
                    'answer_from' => 'narrator',
                    'submitted' => 'أبو سعيد الخدري',
                ],
                [
                    // «ولرسوله» missing from the chain of five.
                    'template' => 'اكتب الحديث الذي رواه {narrator} وعنوانه: {title}',
                    'hadith' => 'الدين النصيحة',
                    'answer_from' => 'text',
                    'submitted' => 'الدين النصيحة. قلنا: لمن؟ قال: لله ولكتابه ولأئمة المسلمين وعامتهم',
                ],
                [
                    'template' => 'سمع من حفظك الحديث الذي عنوانه: {title}',
                    'hadith' => 'احفظ الله يحفظك',
                    'answer_from' => 'text',
                    'submitted' => null,
                ],
                [
                    'template' => 'ما مصدر هذا الحديث؟',
                    'hadith' => 'اتق الله حيثما كنت',
                    'answer_from' => 'source',
                    'submitted' => null,
                ],
            ],
        ],
    ],
];
