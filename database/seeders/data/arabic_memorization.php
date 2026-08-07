<?php

declare(strict_types=1);

/**
 * Recitation histories for the learners already in the database.
 *
 * Each profile is a slice of الأربعون النووية with the attempts a learner made
 * on it, oldest first. `text` is what was recognized from the recitation: null
 * means it was recited exactly, anything else carries a deliberate slip — a
 * substituted word, a dropped clause, half a matn — so the stored comparison
 * report has something real to show.
 *
 * No score, verdict or SRS level is written here. The seeder replays these
 * attempts through HadithTextComparisonService and SpacedRepetitionService, so
 * the attempt rows and the progress they add up to are exactly what the app
 * would have produced.
 *
 * @return array{
 *     book:string,
 *     profiles: array<int, array{
 *         label:string, summary:string,
 *         hadiths: array<int, array{
 *             hadith:string,
 *             attempts: array<int, array{days_ago:int, type?:string, text:string|null}>
 *         }>
 *     }>,
 *     audits: array<int, array{
 *         profile:int, hadith:string, status:string, srs_level:int,
 *         next_review_days:int|null, reason:string
 *     }>
 * }
 */
return [
    'book' => 'الأربعون النووية',

    'profiles' => [
        // Two matns fully memorized, one deep in review, and three still being
        // worked on — the shape of a learner who has been at it for a while.
        [
            'label' => 'متقدم',
            'summary' => 'حافظ متمكن أتم حديثين ويراجع البقية بانتظام.',
            'hadiths' => [
                [
                    'hadith' => 'إنما الأعمال بالنيات',
                    'attempts' => [
                        // «نوى» recited as «نواه».
                        ['days_ago' => 46, 'type' => 'memorization', 'text' => 'إنما الأعمال بالنيات، وإنما لكل امرئ ما نواه، فمن كانت هجرته إلى الله ورسوله فهجرته إلى الله ورسوله، ومن كانت هجرته لدنيا يصيبها أو امرأة ينكحها فهجرته إلى ما هاجر إليه'],
                        ['days_ago' => 44, 'type' => 'review', 'text' => null],
                        ['days_ago' => 40, 'type' => 'review', 'text' => null],
                        ['days_ago' => 32, 'type' => 'review', 'text' => null],
                        ['days_ago' => 17, 'type' => 'review', 'text' => null],
                    ],
                ],
                [
                    'hadith' => 'لا يؤمن أحدكم حتى يحب لأخيه',
                    'attempts' => [
                        // Stopped one word short of the end.
                        ['days_ago' => 45, 'type' => 'memorization', 'text' => 'لا يؤمن أحدكم حتى يحب لأخيه ما يحب'],
                        ['days_ago' => 43, 'type' => 'review', 'text' => null],
                        ['days_ago' => 39, 'type' => 'review', 'text' => null],
                        ['days_ago' => 30, 'type' => 'review', 'text' => null],
                        ['days_ago' => 14, 'type' => 'review', 'text' => null],
                    ],
                ],
                [
                    'hadith' => 'دع ما يريبك إلى ما لا يريبك',
                    'attempts' => [
                        ['days_ago' => 41, 'type' => 'memorization', 'text' => null],
                        ['days_ago' => 38, 'type' => 'review', 'text' => null],
                        ['days_ago' => 33, 'type' => 'review', 'text' => null],
                        ['days_ago' => 22, 'type' => 'review', 'text' => null],
                        ['days_ago' => 6, 'type' => 'exam_voice', 'text' => null],
                    ],
                ],
                [
                    'hadith' => 'من كان يؤمن بالله واليوم الآخر',
                    'attempts' => [
                        ['days_ago' => 21, 'type' => 'memorization', 'text' => null],
                        // «فليكرم جاره» recited as «فليحسن إلى جاره».
                        ['days_ago' => 18, 'type' => 'review', 'text' => 'من كان يؤمن بالله واليوم الآخر فليقل خيرا أو ليصمت، ومن كان يؤمن بالله واليوم الآخر فليحسن إلى جاره، ومن كان يؤمن بالله واليوم الآخر فليكرم ضيفه'],
                        ['days_ago' => 9, 'type' => 'review', 'text' => null],
                    ],
                ],
                [
                    'hadith' => 'الطهور شطر الإيمان',
                    'attempts' => [
                        // «أو تملأ» skipped and the closing sentence never reached.
                        ['days_ago' => 12, 'type' => 'memorization', 'text' => 'الطهور شطر الإيمان، والحمد لله تملأ الميزان، وسبحان الله والحمد لله تملآن ما بين السماوات والأرض، والصلاة نور، والصدقة برهان، والصبر ضياء، والقرآن حجة لك أو عليك'],
                        ['days_ago' => 4, 'type' => 'review', 'text' => null],
                    ],
                ],
                [
                    'hadith' => 'لا تغضب',
                    'attempts' => [
                        // Stopped at the first answer, missing the repetition.
                        ['days_ago' => 8, 'type' => 'memorization', 'text' => 'أن رجلا قال للنبي صلى الله عليه وسلم: أوصني. قال: لا تغضب'],
                        ['days_ago' => 2, 'type' => 'review', 'text' => null],
                    ],
                ],
            ],
        ],

        // Steady progress, nothing memorized yet, and one matn clearly not ready.
        [
            'label' => 'متوسط',
            'summary' => 'يحفظ منذ أسابيع، وصل بعض الأحاديث إلى المراجعة ولم يتم أيها بعد.',
            'hadiths' => [
                [
                    'hadith' => 'بني الإسلام على خمس',
                    'attempts' => [
                        // «شهادة» dropped from the opening.
                        ['days_ago' => 26, 'type' => 'memorization', 'text' => 'بني الإسلام على خمس: أن لا إله إلا الله وأن محمدا رسول الله، وإقام الصلاة، وإيتاء الزكاة، وحج البيت، وصوم رمضان'],
                        ['days_ago' => 23, 'type' => 'review', 'text' => null],
                        ['days_ago' => 15, 'type' => 'review', 'text' => null],
                    ],
                ],
                [
                    'hadith' => 'الدين النصيحة',
                    'attempts' => [
                        // «ولرسوله» missing from the chain of five.
                        ['days_ago' => 19, 'type' => 'memorization', 'text' => 'الدين النصيحة. قلنا: لمن؟ قال: لله ولكتابه ولأئمة المسلمين وعامتهم'],
                        ['days_ago' => 11, 'type' => 'review', 'text' => null],
                    ],
                ],
                [
                    'hadith' => 'من حسن إسلام المرء تركه ما لا يعنيه',
                    'attempts' => [
                        ['days_ago' => 13, 'type' => 'memorization', 'text' => 'من حسن إسلام المرء تركه ما لا يعني'],
                        ['days_ago' => 5, 'type' => 'review', 'text' => null],
                    ],
                ],
                [
                    'hadith' => 'اتق الله حيثما كنت',
                    'attempts' => [
                        // Only the first two of the three commands.
                        ['days_ago' => 3, 'type' => 'memorization', 'text' => 'اتق الله حيثما كنت، وأتبع السيئة الحسنة'],
                    ],
                ],
            ],
        ],

        // Just started: short matns, low scores, everything still in memorizing.
        [
            'label' => 'مبتدئ',
            'summary' => 'بدأ الحفظ حديثا، ما زال في أول الطريق ودرجاته منخفضة.',
            'hadiths' => [
                [
                    'hadith' => 'لا ضرر ولا ضرار',
                    'attempts' => [
                        ['days_ago' => 9, 'type' => 'memorization', 'text' => 'لا ضرر ولا إضرار'],
                        ['days_ago' => 1, 'type' => 'review', 'text' => null],
                    ],
                ],
                [
                    'hadith' => 'من رأى منكم منكرا فليغيره',
                    'attempts' => [
                        // The three degrees recited without the closing judgement.
                        ['days_ago' => 6, 'type' => 'memorization', 'text' => 'من رأى منكم منكرا فليغيره بيده، فإن لم يستطع فبلسانه، فإن لم يستطع فبقلبه'],
                    ],
                ],
                [
                    'hadith' => 'إن الله كتب الإحسان على كل شيء',
                    'attempts' => [
                        // Remembered the rule and the slaughtering, lost the rest.
                        ['days_ago' => 2, 'type' => 'memorization', 'text' => 'إن الله كتب الإحسان على كل شيء، فإذا ذبحتم فأحسنوا الذبحة'],
                    ],
                ],
            ],
        ],
    ],

    // A supervisor moving a learner forward by hand after hearing them in
    // person — the flow Admin\UserProgressController records.
    'audits' => [
        [
            'profile' => 2,
            'hadith' => 'من رأى منكم منكرا فليغيره',
            'status' => 'reviewing',
            'srs_level' => 1,
            'next_review_days' => 3,
            'reason' => 'سمعت الطالب يتلو الحديث كاملا في الحلقة، فرفعت مستواه إلى المراجعة.',
        ],
    ],
];
