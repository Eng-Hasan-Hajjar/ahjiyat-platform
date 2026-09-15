<?php

namespace Database\Seeders\Seasons;

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use App\Models\Season;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Data creation فقط - صفر Business Logic هون. لا كلاسات باسم Aseel داخل
 * Game/Campaign Engine - هذا الملف الوحيد الذي "يعرف" أصيل. Idempotent
 * بالكامل عبر updateOrCreate() بمفاتيح مستقرة (slug/sort_order/title) -
 * إعادة التشغيل لا تُنشئ نسخاً مكرَّرة ولا تعتمد على IDs ثابتة.
 *
 * 3 Stages / 6 Gates / 22 Steps (Full Prototype). كل محتوى غير نهائي
 * مُعلَّم بتعليقات المطوّر فقط (لا يظهر للاعب حرفياً) - راجع
 * docs/seasons/aseel-assumptions.md للتفصيل الكامل.
 */
class AseelSeasonSeeder extends Seeder
{
    public function run(): void
    {
        // FIX: puzzle_categories.slug عمود NOT NULL بقاعدة البيانات - كان
        // مفقوداً بالنسخة السابقة فتسبَّب بفشل كل الاختبارات فوراً. slug
        // ثابت وآمن (لا تحويل تلقائي Str::slug() من نص عربي، لتفادي أي
        // نتيجة فارغة/ملتبسة).
        $category = PuzzleCategory::updateOrCreate(
            ['slug' => 'official-seasons'],
            ['name' => 'المواسم الرسمية', 'is_active' => true, 'sort_order' => 999],
        );

        $campaign = Campaign::updateOrCreate(
            ['slug' => 'aseel-season-01'],
            [
                'title' => 'أصيل — الفتى الذي يسمع أكثر مما ينبغي',
                'description' => 'رحلة تحقيق رقمي عبر أدلة وأحجيات لكشف ما حدث لأصيل.',
                'is_active' => true,
                'starts_at' => null,
                'ends_at' => null,
            ],
        );

        $season = Season::updateOrCreate(
            ['campaign_id' => $campaign->id],
            [
                'code' => 'S01',
                'slug' => 'aseel',
                'grand_prize_description' => null,
                'theme_config' => [
                    'preset' => 'aseel',
                    'hero_tagline' => 'حين يسمع الفتى أكثر مما ينبغي، تبدأ الأسئلة التي لا تنتهي.',
                ],
                'is_published' => true,
                'is_featured' => true,
            ],
        );

        // ================= STAGE 1 — الأثر =================
        $stage1 = CampaignStage::updateOrCreate(
            ['campaign_id' => $campaign->id, 'sort_order' => 1],
            ['title' => 'المرحلة الأولى — الأثر', 'subtitle' => null],
        );

        $gate1 = CampaignGate::updateOrCreate(
            ['campaign_stage_id' => $stage1->id, 'sort_order' => 1],
            [
                'title' => 'البوابة الأولى — أول الأدلة',
                'subtitle' => null,
                'narrative_intro' => null,
                'qualification_rule' => 'first_n',
                'qualification_config' => ['limit' => 100],
            ],
        );

        $this->step($gate1, 1, CampaignStep::KIND_NARRATIVE, 'نداء', [
            'subtitle' => 'رسالة من والد أصيل',
            'body' => "عزيزي أصيل،\n\nإن كنت تقرأ هذه الرسالة، فهذا يعني أنني لم أستطع إخبارك بنفسي. ثمة أشياء بمكتبي لطالما حذّرتك من الاقتراب منها - ليس لأنها خطيرة، بل لأنك، يا بني، كنت دائماً تسمع أكثر مما ينبغي.\n\nابدأ من حيث توقفت. الأثر موجود، إن أحسنت النظر.\n\n— والدك",
            'status' => CampaignStep::CONTENT_STATUS_PLACEHOLDER,
        ]);

        $officeBefore = 'seasons/aseel/story/office-before-placeholder.png';
        $officeAfter = 'seasons/aseel/story/office-after-placeholder.png';
        $this->ensurePlaceholderImage($officeBefore, 'مكتب أصيل - قبل');
        $this->ensurePlaceholderImage($officeAfter, 'مكتب أصيل - بعد');

        $spotDiffPuzzle = $this->puzzle('أثر ١: مكتب أصيل', $category, [
            'type' => 'image',
            'prompt' => 'انظر جيداً بين الصورتين - ماذا تغيّر في مكتب أصيل؟',
            'difficulty' => 'medium',
            'game_type' => 'spot_difference',
            'validation_type' => 'spot_difference_match',
            'score_mode' => 'flat',
            'renderer' => 'games.spot-difference',
            'game_config' => ['image_before' => $officeBefore, 'image_after' => $officeAfter],
            'solution_data' => ['hotspots' => [
                ['x' => 0.25, 'y' => 0.35, 'radius' => 0.06],
                ['x' => 0.62, 'y' => 0.58, 'radius' => 0.06],
                ['x' => 0.80, 'y' => 0.20, 'radius' => 0.06],
            ]],
            'max_attempts' => 5, 'gem_reward' => 20, 'is_active' => true,
        ]);
        $this->step($gate1, 2, CampaignStep::KIND_PUZZLE, 'أثر في المكتب', [
            'subtitle' => 'ابحث عن الفروق', 'status' => CampaignStep::CONTENT_STATUS_PLACEHOLDER,
        ], $spotDiffPuzzle);

        $notebookPuzzle = $this->puzzle('رمز الدفتر', $category, [
            'type' => 'text',
            'prompt' => 'بآخر صفحة من الدفتر، ثلاثة أرقام تتكرر: 7 و12 و5. اجمعها لتحصل على الرمز.',
            'answer_raw' => '24', 'difficulty' => 'easy',
            'max_attempts' => 5, 'gem_reward' => 15, 'is_active' => true,
        ]);
        $this->step($gate1, 3, CampaignStep::KIND_PUZZLE, 'رمز الدفتر', [
            'status' => CampaignStep::CONTENT_STATUS_PLACEHOLDER,
        ], $notebookPuzzle);

        // ----- Gate 2: القبول -----
        $gate2 = CampaignGate::updateOrCreate(
            ['campaign_stage_id' => $stage1->id, 'sort_order' => 2],
            ['title' => 'البوابة الثانية — القبول', 'subtitle' => null, 'narrative_intro' => null, 'qualification_rule' => null],
        );

        $this->step($gate2, 1, CampaignStep::KIND_NARRATIVE, 'إلى من تأهّل', [
            'body' => "لقد وصلت إلى هنا لأنك من بين القلائل الذين لاحظوا التفاصيل. هذه ليست مصادفة.\n\nما سيأتي لاحقاً يحتاج تركيزاً أكبر.",
            'status' => CampaignStep::CONTENT_STATUS_PLACEHOLDER,
        ]);

        $agePuzzle = $this->puzzle('عمر أصيل', $category, [
            'type' => 'text',
            'prompt' => 'وُلد أصيل قبل أن يبدأ القرن بستة عشر عاماً بالضبط، ونحن الآن بأول أيامه. كم عمره اليوم؟',
            'answer_raw' => '16', 'difficulty' => 'easy',
            'max_attempts' => 5, 'gem_reward' => 15, 'is_active' => true,
        ]);
        $this->step($gate2, 2, CampaignStep::KIND_PUZZLE, 'كم عمر أصيل؟', [
            'status' => CampaignStep::CONTENT_STATUS_PLACEHOLDER,
        ], $agePuzzle);

        $roomImage = 'seasons/aseel/story/room-clue-placeholder.png';
        $this->ensurePlaceholderImage($roomImage, 'دليل من الغرفة');
        $roomPuzzle = $this->puzzle('دليل الغرفة', $category, [
            'type' => 'image', 'image_path' => $roomImage,
            'prompt' => 'بالصورة رقم مكتوب على غلاف كتاب - ما هو؟',
            'answer_raw' => '7', 'difficulty' => 'medium',
            'max_attempts' => 5, 'gem_reward' => 15, 'is_active' => true,
        ]);
        $this->step($gate2, 3, CampaignStep::KIND_PUZZLE, 'دليل من الغرفة', [
            'status' => CampaignStep::CONTENT_STATUS_CONTENT_PENDING,
        ], $roomPuzzle);

        $landmarkPuzzle = $this->puzzle('إحداثيات ناقصة', $category, [
            'type' => 'text',
            'prompt' => 'صورة قديمة تحمل جزءاً من إحداثيات مدينة، ومعلماً مألوفاً بالخلفية. أي مدينة يشير إليها الدليل؟',
            'answer_raw' => 'حلب', 'difficulty' => 'medium',
            'max_attempts' => 5, 'gem_reward' => 20, 'is_active' => true,
        ]);
        $this->step($gate2, 4, CampaignStep::KIND_PUZZLE, 'أي مدينة؟', [
            'status' => CampaignStep::CONTENT_STATUS_CONTENT_PENDING,
        ], $landmarkPuzzle);

        $this->step($gate2, 5, CampaignStep::KIND_NARRATIVE, 'إرهاق', [
            'body' => "لم يكن الأمر يتعلق بمكان واحد أو حادثة واحدة. كان تراكماً: إرهاق، وحدة، وضغط لم يشعر أحد أنه يكفي لسؤاله عنه.\n\nأصيل لم يهرب من أحد - هرب من صمت الجميع.",
            'status' => CampaignStep::CONTENT_STATUS_PLACEHOLDER,
        ]);

        // ================= STAGE 2 — خلف الحسابات =================
        $stage2 = CampaignStage::updateOrCreate(
            ['campaign_id' => $campaign->id, 'sort_order' => 2],
            ['title' => 'المرحلة الثانية — خلف الحسابات', 'subtitle' => null],
        );

        $gate3 = CampaignGate::updateOrCreate(
            ['campaign_stage_id' => $stage2->id, 'sort_order' => 1],
            ['title' => 'البوابة الثالثة — فك الحسابات', 'subtitle' => null, 'narrative_intro' => null, 'qualification_rule' => null],
        );

        $cipherPuzzle = $this->puzzle('فك اسم الحساب', $category, [
            'type' => 'text',
            'prompt' => 'كل حرف بالاسم استُبدل بالحرف الذي يليه بالأبجدية. الاسم المشفَّر: "ثسdmm". ما الاسم الأصلي؟',
            'answer_raw' => 'اصيل', 'difficulty' => 'hard',
            'max_attempts' => 5, 'gem_reward' => 25, 'is_active' => true,
        ]);
        $this->step($gate3, 1, CampaignStep::KIND_PUZZLE, 'فك اسم الحساب', [
            'status' => CampaignStep::CONTENT_STATUS_CONTENT_PENDING,
        ], $cipherPuzzle);

        $oldCommentPuzzle = $this->puzzle('تعليق قديم', $category, [
            'type' => 'text',
            'prompt' => 'تعليق قديم يحمل عبارة "س ٧ ص" - ما الرقم الذي يشير إليه هذا الرمز؟',
            'answer_raw' => '7', 'difficulty' => 'medium',
            'max_attempts' => 5, 'gem_reward' => 15, 'is_active' => true,
        ]);
        $this->step($gate3, 2, CampaignStep::KIND_PUZZLE, 'تعليق قديم', [
            'status' => CampaignStep::CONTENT_STATUS_CONTENT_PENDING,
        ], $oldCommentPuzzle);

        $this->step($gate3, 3, CampaignStep::KIND_NARRATIVE, 'لجين', [
            'body' => 'اسم "لجين" يظهر أكثر من مرة بين التعليقات القديمة. من تكون، ولماذا تتكرر بجانب اسم أصيل؟',
            'status' => CampaignStep::CONTENT_STATUS_CONTENT_PENDING,
        ]);

        $gate4 = CampaignGate::updateOrCreate(
            ['campaign_stage_id' => $stage2->id, 'sort_order' => 2],
            ['title' => 'البوابة الرابعة — أدلة صوتية وبصرية', 'subtitle' => null, 'narrative_intro' => null, 'qualification_rule' => null],
        );

        $audioClue1 = 'seasons/aseel/story/audio-clue-1-placeholder.mp3';
        $audioCipherPuzzle = $this->puzzle('مقطع صوتي مشفَّر', $category, [
            'type' => 'text',
            'prompt' => 'استمع للمقطع الصوتي - العدد المتكرر بالخلفية هو الرمز المطلوب.',
            'answer_raw' => '12', 'difficulty' => 'medium',
            'max_attempts' => 5, 'gem_reward' => 20, 'is_active' => true,
        ]);
        $this->step($gate4, 1, CampaignStep::KIND_PUZZLE, 'مقطع صوتي مشفَّر', [
            'media_type' => 'audio', 'media_path' => $audioClue1,
            'caption' => 'مقطع صوتي (Placeholder) - يحتاج الملف الفعلي من العميل',
            'status' => CampaignStep::CONTENT_STATUS_CONTENT_PENDING,
        ], $audioCipherPuzzle);

        $recallPuzzle = $this->puzzle('تذكّر', $category, [
            'type' => 'text',
            'prompt' => 'بحسب رسالة "إرهاق" السابقة، بماذا وصف النص شعور أصيل قبل رحيله؟ (كلمة واحدة)',
            'answer_raw' => 'وحدة', 'difficulty' => 'easy',
            'max_attempts' => 5, 'gem_reward' => 15, 'is_active' => true,
        ]);
        $this->step($gate4, 2, CampaignStep::KIND_PUZZLE, 'تذكّر ما سبق', [
            'status' => CampaignStep::CONTENT_STATUS_PLACEHOLDER,
        ], $recallPuzzle);

        $animalImage = 'seasons/aseel/story/animal-clue-placeholder.png';
        $this->ensurePlaceholderImage($animalImage, 'صور حيوانات - دليل');
        $animalPuzzle = $this->puzzle('صور الحيوانات', $category, [
            'type' => 'image', 'image_path' => $animalImage,
            'prompt' => 'كم عدد الحيوانات الظاهرة بالصورة؟',
            'answer_raw' => '3', 'difficulty' => 'easy',
            'max_attempts' => 5, 'gem_reward' => 15, 'is_active' => true,
        ]);
        $this->step($gate4, 3, CampaignStep::KIND_PUZZLE, 'صور تقود لحل', [
            'status' => CampaignStep::CONTENT_STATUS_CONTENT_PENDING,
        ], $animalPuzzle);

        $audioClue2 = 'seasons/aseel/story/audio-clue-2-placeholder.mp3';
        $audioSpeedPuzzle = $this->puzzle('صوت بسرعة مختلفة', $category, [
            'type' => 'text',
            'prompt' => 'المقطع الصوتي مُسرَّع - أعد الاستماع ببطء ذهني، واكتب الكلمة التي سمعتها بوضوح.',
            'answer_raw' => 'أصيل', 'difficulty' => 'medium',
            'max_attempts' => 5, 'gem_reward' => 20, 'is_active' => true,
        ]);
        $this->step($gate4, 4, CampaignStep::KIND_PUZZLE, 'استمع جيداً', [
            'media_type' => 'audio', 'media_path' => $audioClue2,
            'caption' => 'مقطع صوتي (Placeholder) - يحتاج الملف الفعلي من العميل',
            'status' => CampaignStep::CONTENT_STATUS_CONTENT_PENDING,
        ], $audioSpeedPuzzle);

        $gate5 = CampaignGate::updateOrCreate(
            ['campaign_stage_id' => $stage2->id, 'sort_order' => 3],
            ['title' => 'البوابة الخامسة — التحديات الكبرى', 'subtitle' => null, 'narrative_intro' => null, 'qualification_rule' => null],
        );

        $this->step($gate5, 1, CampaignStep::KIND_NARRATIVE, 'تحدي الفرق', [
            'body' => 'هذا التحدي يتطلب فرقاً من خمسة أعضاء. آلية تشكيل الفريق ومطابقة الأعضاء بانتظار قرار تقني نهائي مع صاحب المنصة.',
            'status' => CampaignStep::CONTENT_STATUS_TECHNICAL_PENDING,
        ]);

        $this->step($gate5, 2, CampaignStep::KIND_NARRATIVE, 'تحدي الشطرنج', [
            'body' => 'تحدٍ يتعلق بمباراة أو لغز شطرنج. الشكل النهائي (مباراة حقيقية أم لغز موضعي) بانتظار قرار تقني نهائي.',
            'status' => CampaignStep::CONTENT_STATUS_TECHNICAL_PENDING,
        ]);

        $memoryPuzzle = $this->puzzle('ذاكرة أصيل', $category, [
            'type' => 'text',
            'prompt' => 'طابق الأزواج لتكشف الرمز الأخير.',
            'answer_raw' => 'تم', 'difficulty' => 'medium',
            'game_type' => 'memory',
            'validation_type' => 'memory_match',
            'score_mode' => 'flat',
            'renderer' => 'games.memory',
            'game_config' => ['pairs' => ['🕯️', '📖', '🗝️', '🖋️']],
            'max_attempts' => 5, 'gem_reward' => 20, 'is_active' => true,
        ]);
        $this->step($gate5, 3, CampaignStep::KIND_PUZZLE, 'اختبار الذاكرة', [
            'status' => CampaignStep::CONTENT_STATUS_PLACEHOLDER,
        ], $memoryPuzzle);

        $storyAccountPuzzle = $this->puzzle('صورة أوضح', $category, [
            'type' => 'text',
            'prompt' => 'بتجميع كل الأدلة السابقة، ما الاسم الظاهري (Username) الذي يجمعها جميعاً؟',
            'answer_raw' => 'aseel_hears', 'difficulty' => 'hard',
            'max_attempts' => 5, 'gem_reward' => 25, 'is_active' => true,
        ]);
        $this->step($gate5, 4, CampaignStep::KIND_PUZZLE, 'حساب أو قصة؟', [
            'status' => CampaignStep::CONTENT_STATUS_CONTENT_PENDING,
        ], $storyAccountPuzzle);

        // ================= STAGE 3 — العودة =================
        $stage3 = CampaignStage::updateOrCreate(
            ['campaign_id' => $campaign->id, 'sort_order' => 3],
            ['title' => 'المرحلة الثالثة — العودة', 'subtitle' => null],
        );

        $gate6 = CampaignGate::updateOrCreate(
            ['campaign_stage_id' => $stage3->id, 'sort_order' => 1],
            ['title' => 'البوابة السادسة — الرجوع', 'subtitle' => null, 'narrative_intro' => null, 'qualification_rule' => null],
        );

        $this->step($gate6, 1, CampaignStep::KIND_REFLECTION, 'لماذا برأيك؟', [
            'prompt' => 'بعد كل ما رأيته من أدلة، لماذا تعتقد أن أصيل غادر؟',
            'min_chars' => 20, 'max_chars' => 1000,
            'status' => CampaignStep::CONTENT_STATUS_PLACEHOLDER,
        ]);

        $this->step($gate6, 2, CampaignStep::KIND_REFLECTION, 'رسالة دعم', [
            'prompt' => 'اكتب رسالة قصيرة داعمة لأصيل، لو كان بإمكانه قراءتها الآن.',
            'min_chars' => 10, 'max_chars' => 500,
            'status' => CampaignStep::CONTENT_STATUS_PLACEHOLDER,
        ]);

        $this->step($gate6, 3, CampaignStep::KIND_NARRATIVE, 'قرار', [
            'body' => "بعد كل الرسائل التي وصلته، قرر أصيل أن يكتب مرة أخرى:\n\n\"لم أكن أعرف أن أحداً سيهتم لهذه الدرجة. سأعود، ليس فجأة، لكن تدريجياً. شكراً لأنكم سمعتم.\"\n\n— نهاية الموسم الأول",
            'status' => CampaignStep::CONTENT_STATUS_PLACEHOLDER,
        ]);
    }

    protected function step(CampaignGate $gate, int $sortOrder, string $kind, string $title, array $content, ?Puzzle $puzzle = null): CampaignStep
    {
        return CampaignStep::updateOrCreate(
            ['campaign_gate_id' => $gate->id, 'sort_order' => $sortOrder],
            [
                'kind' => $kind,
                'title' => $title,
                'subtitle' => $content['subtitle'] ?? null,
                'content' => collect($content)->except('subtitle')->all() ?: null,
                'puzzle_id' => $puzzle?->id,
                'reward_mode' => CampaignStep::REWARD_MODE_INHERIT,
            ],
        );
    }

    protected function puzzle(string $title, PuzzleCategory $category, array $attributes): Puzzle
    {
        return Puzzle::updateOrCreate(
            ['title' => $title],
            array_merge(['puzzle_category_id' => $category->id], $attributes),
        );
    }

    /**
     * ضمانة "لا صورة معطَّلة": تُنشئ صورة Placeholder بسيطة فعلياً إن لم
     * توجد مسبقاً. تتطلب امتداد GD (متوفر افتراضياً بمعظم بيئات PHP، بما
     * فيها XAMPP).
     */
    protected function ensurePlaceholderImage(string $path, string $label, int $width = 900, int $height = 600): void
    {
        if (Storage::disk('public')->exists($path)) {
            return;
        }

        if (! function_exists('imagecreatetruecolor')) {
            return; // GD غير متاحة - <x-media-or-placeholder> ستعرض لوحة CSS بديلة بدلاً منها
        }

        $image = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($image, 15, 18, 38);
        imagefilledrectangle($image, 0, 0, $width, $height, $bg);

        $border = imagecolorallocate($image, 139, 92, 246);
        imagerectangle($image, 4, 4, $width - 5, $height - 5, $border);

        $text = imagecolorallocate($image, 226, 232, 240);
        $fontWidth = imagefontwidth(5);
        $textX = (int) (($width - strlen($label) * $fontWidth) / 2);
        imagestring($image, 5, max(10, $textX), (int) ($height / 2) - 10, $label, $text);

        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        Storage::disk('public')->put($path, $contents);
    }
}