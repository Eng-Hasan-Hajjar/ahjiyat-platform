<?php

namespace App\Filament\Pages;

use App\Models\AhjiyatPass;
use App\Models\AhjiyatPassMission;
use App\Models\AhjiyatPassTier;
use App\Models\AhjiyatPassTierReward;
use App\Models\PuzzleCategory;
use App\Models\QuestDefinition;
use App\Models\StoreItem;
use App\Services\AhjiyatPass\PassBuilderService;
use App\Services\AhjiyatPass\PassException;
use App\Services\AhjiyatPass\PassReadinessChecker;
use App\Services\AhjiyatPass\PassRules;
use App\Services\AhjiyatPass\PassTheme;
use App\Services\Engagement\QuestEvaluatorRegistry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;

/**
 * E40 - منشئ المسار (Pass Builder): الأساسيات، الشرائح، مكافآت المسارين، المهام، ومعاينة المسارين المجاني/المميز.
 * المسودة قابلة للتحرير كاملةً؛ المنشور (مقفل) للعرض فقط مع نافذة بيع المميز والمظهر والنص (ما تسمح به PassBuilderService). كل التحقق والصلاحيات داخل الخدمة.
 * المظهر قائمة بيضاء مغلقة (ألوان/خلفيات من الإعداد) وبانر الصورة يمرّ بفحص نوع المحتوى الفعلي والأبعاد؛ لا HTML ولا CSS حرّ.
 */
class AhjiyatPassBuilderPage extends Page
{
    use WithFileUploads;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'التقدُّم';

    protected static ?string $navigationLabel = 'منشئ المسار';

    protected static ?string $title = 'منشئ المسار';

    protected static ?string $slug = 'ahjiyat-pass-builder';

    protected static ?int $navigationSort = 2;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.ahjiyat-pass-builder';

    #[Url(as: 'pass')]
    public ?string $passId = null;

    /** @var array<string, mixed> */
    public array $basics = [];

    /** @var list<array<string, mixed>> */
    public array $tiers = [];

    /** @var list<array<string, mixed>> */
    public array $missions = [];

    /** @var array<string, mixed> */
    public array $gen = ['count' => 30, 'step' => 100, 'ramp' => 0, 'milestone_every' => 5];

    /** @var array<string, mixed> */
    public array $attach = ['quest' => '', 'difficulty' => 'easy', 'points' => '', 'max' => 1];

    /** @var array<string, mixed> */
    public array $nq = ['name' => '', 'description' => '', 'period_type' => 'daily', 'condition_type' => 'puzzles_solved', 'target_value' => 1, 'scope_id' => '', 'difficulty' => 'easy', 'points' => '', 'max' => 1];

    public $banner = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('passes.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        abort_if($this->passId === null || $this->model() === null, 404);
        $this->load();
    }

    public function model(): ?AhjiyatPass
    {
        return $this->passId === null ? null : AhjiyatPass::query()->where('public_id', $this->passId)->first();
    }

    protected function load(): void
    {
        $pass = $this->model();

        $this->basics = [
            'name' => $pass->name, 'subtitle' => (string) $pass->subtitle, 'description' => (string) $pass->description,
            'is_featured' => (bool) $pass->is_featured,
            'accent' => $pass->themeAccent(), 'background' => $pass->themeBackground(), 'tagline' => (string) $pass->tagline(),
            'starts_at' => $pass->starts_at->format('Y-m-d\TH:i'), 'ends_at' => $pass->ends_at->format('Y-m-d\TH:i'), 'claim_grace_days' => (int) $pass->claim_grace_days,
            'premium_enabled' => (bool) $pass->premium_enabled,
            'premium_price_minor' => $pass->premium_price_minor, 'currency' => (string) $pass->currency,
            'premium_sales_start_at' => $pass->premium_sales_start_at?->format('Y-m-d\TH:i') ?? '',
            'premium_sales_end_at' => $pass->premium_sales_end_at?->format('Y-m-d\TH:i') ?? '',
        ];

        $tiers = AhjiyatPassTier::query()->where('ahjiyat_pass_id', $pass->getKey())->orderBy('tier_number')->get();
        $rewards = AhjiyatPassTierReward::query()->where('ahjiyat_pass_id', $pass->getKey())->get()->groupBy('ahjiyat_pass_tier_id');

        $this->tiers = $tiers->map(function (AhjiyatPassTier $t) use ($rewards): array {
            $set = $rewards->get($t->getKey(), collect())->keyBy('track');

            return [
                'id' => $t->getKey(), 'points' => (string) $t->required_points, 'label' => (string) $t->label, 'milestone' => (bool) $t->is_milestone,
                'free_item' => (string) ($set->get('free')?->store_item_id ?? ''), 'free_qty' => (int) ($set->get('free')?->quantity ?? 1),
                'premium_item' => (string) ($set->get('premium')?->store_item_id ?? ''), 'premium_qty' => (int) ($set->get('premium')?->quantity ?? 1),
            ];
        })->all();

        $this->missions = AhjiyatPassMission::query()->where('ahjiyat_pass_id', $pass->getKey())->with('quest:id,name,period_type,condition_type,target_value')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (AhjiyatPassMission $m): array => [
                'id' => $m->getKey(), 'name' => (string) $m->quest?->name, 'period' => (string) $m->quest?->period_type, 'target' => (int) $m->quest?->target_value,
                'difficulty' => $m->difficulty, 'points' => (string) $m->pass_points, 'max' => (int) $m->max_completions,
                'week' => $m->week_number === null ? '' : (string) $m->week_number, 'featured' => (bool) $m->is_featured, 'sort' => (int) $m->sort_order,
            ])->all();
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $pass = $this->model();
        $editable = $pass->locked_at === null;
        $user = auth()->user();
        $items = StoreItem::query()
            ->where('is_active', true)
            ->whereIn('item_type', (array) config('ahjiyat_pass.rewards.allowed_item_types', []))
            ->whereIn('fulfillment_type', [StoreItem::FULFILLMENT_INVENTORY, StoreItem::FULFILLMENT_ENTITLEMENT])
            ->when(! (bool) config('ahjiyat_pass.rewards.allow_sellable_items', false), fn ($q) => $q->whereDoesntHave('activePrices'))
            ->orderBy('name')->limit(300)->get(['id', 'name', 'item_type']);

        $attached = array_column($this->missions, 'name');
        $quests = $editable ? QuestDefinition::query()->where('is_active', true)->whereDoesntHave('missionPools')
            ->whereNotIn('id', AhjiyatPassMission::query()->where('ahjiyat_pass_id', $pass->getKey())->pluck('quest_definition_id'))
            ->orderBy('name')->limit(200)->get(['id', 'name', 'period_type']) : collect();

        $registry = app(QuestEvaluatorRegistry::class);

        return [
            'pass' => $pass,
            'editable' => $editable,
            'items' => $items,
            'quests' => $quests,
            'attached' => $attached,
            'problems' => $editable ? app(PassReadinessChecker::class)->problems($pass) : [],
            'accents' => PassTheme::accentLabels(),
            'backgrounds' => PassTheme::backgroundLabels(),
            'currencies' => PassRules::currencies(),
            'conditions' => $registry->options(),
            'categories' => PuzzleCategory::query()->where('is_active', true)->orderBy('name')->limit(100)->get(['id', 'name']),
            'difficulties' => AhjiyatPassMission::DIFFICULTIES,
            'defaults' => (array) config('ahjiyat_pass.default_points'),
            'canManage' => $user->can('passes.manage'),
            'canRewards' => $user->can('passes.manage-rewards'),
            'canMissions' => $user->can('passes.manage-missions'),
            'maxQty' => (int) config('ahjiyat_pass.limits.max_reward_quantity', 5),
        ];
    }

    // ───────────── الأساسيات ─────────────

    public function saveBasics(): void
    {
        $this->run(function () {
            $b = $this->basics;
            $payload = [
                'name' => $b['name'] ?? '', 'subtitle' => $b['subtitle'] ?? null, 'description' => $b['description'] ?? null, 'is_featured' => (bool) ($b['is_featured'] ?? false),
                'theme' => ['accent' => $b['accent'] ?? null, 'background' => $b['background'] ?? null, 'tagline' => $b['tagline'] ?? null],
                'premium_sales_start_at' => $b['premium_sales_start_at'] ?? null, 'premium_sales_end_at' => $b['premium_sales_end_at'] ?? null,
            ];

            if ($this->model()->locked_at === null) {
                $payload += [
                    'starts_at' => $b['starts_at'] ?? null, 'ends_at' => $b['ends_at'] ?? null, 'claim_grace_days' => $b['claim_grace_days'] ?? null,
                    'premium_enabled' => (bool) ($b['premium_enabled'] ?? false), 'premium_price_minor' => $b['premium_price_minor'] ?? null, 'currency' => $b['currency'] ?? null,
                ];
            }

            app(PassBuilderService::class)->update(auth()->user(), $this->model(), $payload);
            $this->load();
        }, 'حُفظت الأساسيات.');
    }

    public function uploadBanner(): void
    {
        $this->run(function () {
            if ($this->banner === null) {
                throw new PassException('banner', 'اختر صورة أولًا.');
            }

            app(PassBuilderService::class)->setBanner(auth()->user(), $this->model(), $this->banner);
            $this->banner = null;
        }, 'حُدِّث البانر.');
    }

    // ───────────── الشرائح والمكافآت ─────────────

    public function generate(): void
    {
        $this->run(function () {
            app(PassBuilderService::class)->generateTiers(auth()->user(), $this->model(), (int) $this->gen['count'], (int) $this->gen['step'], (int) $this->gen['ramp'], (int) $this->gen['milestone_every']);
            $this->load();
        }, 'وُلّدت الشرائح (عتبات تراكمية).');
    }

    public function addTier(): void
    {
        $last = $this->tiers === [] ? 0 : (int) end($this->tiers)['points'];
        $this->tiers[] = ['id' => null, 'points' => (string) ($last + 100), 'label' => '', 'milestone' => false, 'free_item' => '', 'free_qty' => 1, 'premium_item' => '', 'premium_qty' => 1];
    }

    public function removeLastTier(): void
    {
        array_pop($this->tiers);
    }

    public function saveTiers(): void
    {
        $this->run(function () {
            $builder = app(PassBuilderService::class);
            $rows = array_map(fn (array $t): array => ['required_points' => $t['points'], 'label' => $t['label'] ?? null, 'is_milestone' => (bool) ($t['milestone'] ?? false)], $this->tiers);
            $builder->replaceTiers(auth()->user(), $this->model(), $rows);
            $this->load();
        }, 'حُفظت الشرائح.');
    }

    public function saveRewards(): void
    {
        $this->run(function () {
            $builder = app(PassBuilderService::class);
            $pass = $this->model();
            $stored = AhjiyatPassTier::query()->where('ahjiyat_pass_id', $pass->getKey())->orderBy('tier_number')->get()->values();

            if ($stored->count() !== count($this->tiers)) {
                throw new PassException('tiers_unsaved', 'احفظ الشرائح أولًا قبل تعيين المكافآت.');
            }

            foreach ($stored as $index => $tier) {
                $row = $this->tiers[$index];

                foreach (['free', 'premium'] as $lane) {
                    $item = $row[$lane.'_item'] ?? '';

                    if ($item === '' || $item === null) {
                        $builder->clearTierReward(auth()->user(), $tier, $lane);
                    } else {
                        $builder->setTierReward(auth()->user(), $tier, $lane, (int) $item, (int) ($row[$lane.'_qty'] ?? 1));
                    }
                }
            }

            $this->load();
        }, 'حُفظت المكافآت.');
    }

    // ───────────── المهام ─────────────

    public function attachQuest(): void
    {
        $this->run(function () {
            $a = $this->attach;
            app(PassBuilderService::class)->attachMission(auth()->user(), $this->model(), (int) ($a['quest'] ?? 0), (string) $a['difficulty'], $this->missionOptions($a));
            $this->attach = ['quest' => '', 'difficulty' => 'easy', 'points' => '', 'max' => 1];
            $this->load();
        }, 'رُبطت المهمة.');
    }

    public function createQuest(): void
    {
        $this->run(function () {
            $q = $this->nq;
            $registry = app(QuestEvaluatorRegistry::class);
            $needsScope = $registry->requiresScope((string) $q['condition_type']);

            app(PassBuilderService::class)->createQuestForPass(auth()->user(), $this->model(), [
                'name' => $q['name'], 'description' => $q['description'] ?? null, 'period_type' => $q['period_type'], 'condition_type' => $q['condition_type'],
                'target_value' => (int) $q['target_value'],
                'scope_type' => $needsScope ? 'puzzle_category' : null, 'scope_id' => $needsScope ? (int) $q['scope_id'] : null,
            ], (string) $q['difficulty'], $this->missionOptions($q));

            $this->nq = ['name' => '', 'description' => '', 'period_type' => 'daily', 'condition_type' => 'puzzles_solved', 'target_value' => 1, 'scope_id' => '', 'difficulty' => 'easy', 'points' => '', 'max' => 1];
            $this->load();
        }, 'أُنشئت المهمة وربطت بالمسار.');
    }

    public function saveMissions(): void
    {
        $this->run(function () {
            $builder = app(PassBuilderService::class);

            foreach ($this->missions as $m) {
                $mission = AhjiyatPassMission::query()->where('ahjiyat_pass_id', $this->model()->getKey())->findOrFail($m['id']);
                $builder->updateMission(auth()->user(), $mission, [
                    'difficulty' => $m['difficulty'], 'points' => (int) $m['points'], 'max_completions' => (int) $m['max'],
                    'week_number' => $m['week'] === '' || $m['week'] === null ? null : (int) $m['week'], 'is_featured' => (bool) $m['featured'], 'sort_order' => (int) $m['sort'],
                ]);
            }

            $this->load();
        }, 'حُفظت المهام.');
    }

    public function detach(int $missionId): void
    {
        $this->run(function () use ($missionId) {
            $mission = AhjiyatPassMission::query()->where('ahjiyat_pass_id', $this->model()->getKey())->findOrFail($missionId);
            app(PassBuilderService::class)->detachMission(auth()->user(), $mission);
            $this->load();
        }, 'فُصلت المهمة عن المسار.');
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    protected function missionOptions(array $row): array
    {
        $options = ['max_completions' => (int) ($row['max'] ?? 1)];

        if (($row['points'] ?? '') !== '' && $row['points'] !== null) {
            $options['points'] = (int) $row['points'];
        }

        return $options;
    }

    protected function run(\Closure $operation, string $success): void
    {
        try {
            $operation();
            Notification::make()->title($success)->success()->send();
        } catch (PassException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
