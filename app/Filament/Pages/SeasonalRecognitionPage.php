<?php

namespace App\Filament\Pages;

use App\Models\CreatorProfile;
use App\Models\CreatorSeries;
use App\Models\Season;
use App\Models\SeasonSupporterPackage;
use App\Models\SeasonSupporterStat;
use App\Models\SeasonWriterAward;
use App\Models\SeasonWriterEntry;
use App\Models\SponsorshipProgram;
use App\Models\StorePurchase;
use App\Services\SeasonalRecognition\SeasonalRecognitionException;
use App\Services\SeasonalRecognition\SeasonRecognitionSettingsService;
use App\Services\SeasonalRecognition\SeasonSupporterFinalizer;
use App\Services\SeasonalRecognition\SeasonSupporterLeaderboardService;
use App\Services\SeasonalRecognition\SeasonSupporterPackageService;
use App\Services\SeasonalRecognition\SeasonSupporterReconciler;
use App\Services\SeasonalRecognition\SeasonSupporterSocialQueueService;
use App\Services\SeasonalRecognition\SeasonWriterAwardService;
use App\Services\SeasonalRecognition\SeasonWriterFinalizer;
use App\Services\SeasonalRecognition\SeasonWriterLeaderboardService;
use App\Services\SeasonalRecognition\SeasonWriterReconciler;
use App\Services\SeasonalRecognition\SeasonWriterRosterService;
use App\Services\Sponsorship\SponsorshipException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

/**
 * E44 - صفحة Filament **واحدة** مركّزة للتقدير الموسمي (بلا علاقات داخل SeasonResource): الكتّاب، التصويت، الترتيب النهائي، جوائز الكتّاب،
 * باقات الداعمين، ترتيب الداعمين، طابور التنويه، المطابقة.
 *
 * طبقة عرض واستدعاء فقط: **كل قاعدة وصلاحية داخل الخدمات** (seasonal-recognition.view|manage|vote|audit|finalize|award) ولا قرار هنا غير إظهار/إخفاء الأزرار.
 * لا إصلاح صامت: المطابقة قراءة فقط، وإعادة البناء إجراءات صريحة بصلاحية audit/manage وسجل تدقيق. الإنهاء بتأكيد صريح لا رجعة بعده.
 * نقاط الدعم للتقدير فقط (ليست مالًا). الجوائز المالية التزام قابل للتدقيق يُسوَّى يدويًا خارج النظام؛ لا دفع آلي هنا.
 */
class SeasonalRecognitionPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationGroup = 'الحملات';

    protected static ?string $navigationLabel = 'التقدير الموسمي';

    protected static ?string $title = 'التقدير الموسمي: كتّاب الموسم والداعمون';

    protected static ?string $slug = 'seasonal-recognition';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.seasonal-recognition';

    public const TABS = [
        'writers' => 'الكتّاب',
        'voting' => 'التصويت',
        'standings' => 'الترتيب النهائي',
        'awards' => 'جوائز الكتّاب',
        'packages' => 'باقات الداعمين',
        'leaderboard' => 'ترتيب الداعمين',
        'queue' => 'طابور التنويه',
        'reconcile' => 'المطابقة',
    ];

    public ?int $seasonId = null;

    public string $tab = 'writers';

    // إعدادات الدوري
    public bool $writersEnabled = false;

    public string $capacity = '20';

    public string $opensAt = '';

    public string $closesAt = '';

    public string $eliminatedCount = '5';

    public string $previousSeasonId = '';

    /** @var array<string, array{kind: string, label: string, store_item_id: string, amount_minor: string}> */
    public array $plan = [];

    // الكتّاب
    public string $creatorSlug = '';

    /** @var array<string, string> entryPublicId => series public id */
    public array $seriesInput = [];

    // جوائز
    public ?string $awardOpen = null;

    public string $settleMethod = 'manual';

    public string $settleReference = '';

    public string $awardNote = '';

    // الإنهاء
    public bool $confirmWriters = false;

    public bool $confirmSupporters = false;

    // الباقات
    public string $programId = '';

    /** @var array<int, array{name: string, support_points: string, is_active: bool, social_mention_eligible: bool}> */
    public array $packageInput = [];

    // المطابقة: منح شراء متجر فائت إلى هذا الموسم تحديدًا (إجراء إداري صريح؛ E44 لا يخمّن الموسم)
    public string $backfillPurchaseId = '';

    // الطابور
    public string $queueFilter = '';

    /** @var array<int, string> */
    public array $queueNote = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('seasonal-recognition.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->seasonId = Season::query()->orderByDesc('id')->value('id');
        $this->loadSeason();
    }

    public function updatedSeasonId(): void
    {
        $this->loadSeason();
        $this->confirmWriters = $this->confirmSupporters = false;
        $this->awardOpen = null;
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'writers';
        $this->confirmWriters = $this->confirmSupporters = false;
    }

    protected function season(): ?Season
    {
        return $this->seasonId ? Season::query()->with('campaign:id,title')->find($this->seasonId) : null;
    }

    protected function loadSeason(): void
    {
        $season = $this->season();
        $setting = $season !== null ? app(SeasonRecognitionSettingsService::class)->settingFor($season) : null;

        $this->writersEnabled = (bool) ($setting?->writers_enabled ?? false);
        $this->capacity = (string) ($setting?->writers_capacity ?? config('seasonal_recognition.writers.default_capacity', 20));
        $this->opensAt = $setting?->writers_voting_opens_at?->format('Y-m-d\TH:i') ?? '';
        $this->closesAt = $setting?->writers_voting_closes_at?->format('Y-m-d\TH:i') ?? '';
        $this->eliminatedCount = (string) ($setting?->writers_eliminated_count ?? config('seasonal_recognition.writers.eliminated_count', 5));
        $this->previousSeasonId = (string) ($setting?->previous_season_id ?? '');

        $this->plan = [];

        foreach (SeasonWriterAward::PLACEMENTS as $placement) {
            foreach (SeasonWriterAward::KINDS as $kind) {
                $this->plan[$placement.'__'.$kind] = ['kind' => $kind, 'label' => '', 'store_item_id' => '', 'amount_minor' => ''];
            }
        }

        foreach ((array) ($setting?->writers_reward_plan ?? []) as $item) {
            $key = ($item['placement'] ?? '').'__'.($item['kind'] ?? '');

            if (isset($this->plan[$key])) {
                $this->plan[$key] = [
                    'kind' => (string) $item['kind'],
                    'label' => (string) ($item['label'] ?? ''),
                    'store_item_id' => (string) ($item['store_item_id'] ?? ''),
                    'amount_minor' => (string) ($item['amount_minor'] ?? ''),
                ];
            }
        }

        $this->packageInput = [];

        if ($season !== null) {
            foreach (SeasonSupporterPackage::query()->where('season_id', $season->id)->get() as $p) {
                $this->packageInput[(int) $p->id] = ['name' => (string) $p->name, 'support_points' => (string) $p->support_points, 'is_active' => (bool) $p->is_active, 'social_mention_eligible' => (bool) $p->social_mention_eligible];
            }
        }
    }

    // ───────────────────────────── البيانات (تُحمَّل للتبويب الحالي فقط) ─────────────────────────────

    /** @return array<string, mixed> */
    public function data(): array
    {
        $user = auth()->user();
        $season = $this->season();
        $settings = app(SeasonRecognitionSettingsService::class);
        $setting = $season !== null ? $settings->settingFor($season) : null;

        $d = [
            'seasons' => Season::query()->with('campaign:id,title')->orderByDesc('id')->limit(60)->get(),
            'season' => $season,
            'setting' => $setting,
            'can' => [
                'manage' => $user->can('seasonal-recognition.manage'),
                'finalize' => $user->can('seasonal-recognition.finalize'),
                'award' => $user->can('seasonal-recognition.award'),
                'audit' => $user->can('seasonal-recognition.audit'),
            ],
            'flags' => ['writers' => $settings->writersGloballyEnabled(), 'supporters' => $settings->supportersGloballyEnabled()],
        ];

        if ($season === null) {
            return $d;
        }

        $d['state'] = $settings->votingState($season, $setting);

        return $d + match ($this->tab) {
            'writers' => $this->writersData($season, $settings),
            'voting' => $this->votingData($season, $settings),
            'standings' => $this->standingsData($season),
            'awards' => $this->awardsData($season),
            'packages' => $this->packagesData($season),
            'leaderboard' => $this->leaderboardData($season),
            'queue' => $this->queueData($season),
            'reconcile' => ['writerFindings' => $user->can('seasonal-recognition.audit') ? app(SeasonWriterReconciler::class)->inspect($season, 200) : null, 'supporterFindings' => $user->can('seasonal-recognition.audit') ? app(SeasonSupporterReconciler::class)->inspect($season, 200) : null],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    protected function writersData(Season $season, SeasonRecognitionSettingsService $settings): array
    {
        $entries = SeasonWriterEntry::query()
            ->select(['season_writer_entries.*', 'users.name as writer_name', 'creator_profiles.slug as writer_slug'])
            ->join('users', 'users.id', '=', 'season_writer_entries.user_id')
            ->join('creator_profiles', 'creator_profiles.id', '=', 'season_writer_entries.creator_profile_id')
            ->where('season_writer_entries.season_id', $season->id)
            ->with(['seriesLinks:id,writer_entry_id,creator_series_id'])
            ->orderBy('users.name')->orderBy('season_writer_entries.id')->get();

        $titles = $entries->isEmpty() ? collect() : CreatorSeries::query()->whereIn('id', $entries->flatMap(fn ($e) => $e->seriesLinks->pluck('creator_series_id'))->unique()->all())->pluck('published_title', 'id');

        return [
            'readiness' => $settings->readiness($season),
            'entries' => $entries,
            'titles' => $titles,
            'previousOptions' => Season::query()->with('campaign:id,title')->where('id', '<>', $season->id)->orderByDesc('id')->limit(40)->get(),
            'rotation' => app(\App\Services\SeasonalRecognition\SeasonWriterRotationService::class)->excludedCreatorProfileIds($season),
        ];
    }

    /** @return array<string, mixed> */
    protected function votingData(Season $season, SeasonRecognitionSettingsService $settings): array
    {
        $setting = $settings->settingFor($season);
        $board = app(SeasonWriterLeaderboardService::class);

        return [
            'close' => $setting !== null ? $settings->effectiveClose($season, $setting) : null,
            'totals' => $board->totals($season),
            'board' => $board->adminBoard($season),
        ];
    }

    /** @return array<string, mixed> */
    protected function standingsData(Season $season): array
    {
        $finalizer = app(SeasonWriterFinalizer::class);
        $preview = $finalizer->preview($season);

        return ['preview' => $preview, 'final' => $preview['already'] ? app(SeasonWriterLeaderboardService::class)->adminBoard($season) : null];
    }

    /** @return array<string, mixed> */
    protected function awardsData(Season $season): array
    {
        return [
            'awards' => SeasonWriterAward::query()->where('season_id', $season->id)->with(['user:id,name'])->orderBy('placement')->orderBy('plan_index')->orderBy('id')->limit(200)->get(),
        ];
    }

    /** @return array<string, mixed> */
    protected function packagesData(Season $season): array
    {
        return [
            'packages' => app(SeasonSupporterPackageService::class)->packagesFor($season),
            'programs' => SponsorshipProgram::query()->where('target_type', SponsorshipProgram::TARGET_SEASON)->where('target_id', $season->id)
                ->whereNotIn('status', [SponsorshipProgram::STATUS_CLOSED, SponsorshipProgram::STATUS_CANCELLED])->orderBy('id')->limit(30)->get(['id', 'title', 'status']),
        ];
    }

    /** @return array<string, mixed> */
    protected function leaderboardData(Season $season): array
    {
        $board = app(SeasonSupporterLeaderboardService::class);
        $limit = max(1, (int) config('seasonal_recognition.supporters.leaderboard_limit', 100));

        $rows = SeasonSupporterStat::query()
            ->join('users', 'users.id', '=', 'season_supporter_stats.user_id')
            ->where('season_supporter_stats.season_id', $season->id)
            ->where('season_supporter_stats.points_total', '<>', 0)
            ->orderByDesc('season_supporter_stats.points_total')->orderBy('season_supporter_stats.first_point_at')->orderBy('season_supporter_stats.id')
            ->limit($limit)
            ->get(['users.name as supporter_name', 'users.is_frozen as frozen', 'season_supporter_stats.*']);

        return [
            'totals' => $board->totals($season),
            'rows' => $rows,
            'preview' => app(SeasonSupporterFinalizer::class)->preview($season),
        ];
    }

    /** @return array<string, mixed> */
    protected function queueData(Season $season): array
    {
        return ['queue' => app(SeasonSupporterSocialQueueService::class)->list($season, $this->queueFilter !== '' ? $this->queueFilter : null, 100)];
    }

    // ───────────────────────────── الإجراءات ─────────────────────────────

    public function saveSettings(): void
    {
        $this->run(function (Season $season) {
            $plan = [];

            foreach ($this->plan as $key => $row) {
                [$placement, $kind] = explode('__', $key);

                if (trim($row['label']) === '' && $row['store_item_id'] === '' && $row['amount_minor'] === '') {
                    continue;
                }

                $plan[] = [
                    'placement' => $placement,
                    'kind' => $kind,
                    'label' => $row['label'],
                    'store_item_id' => $row['store_item_id'] !== '' ? (int) $row['store_item_id'] : null,
                    'amount_minor' => $row['amount_minor'] !== '' ? $row['amount_minor'] : null,
                    'currency' => $kind === 'monetary' ? 'USD' : null,
                ];
            }

            app(SeasonRecognitionSettingsService::class)->saveWriterSettings($season, [
                'enabled' => $this->writersEnabled,
                'capacity' => $this->capacity,
                'opens_at' => $this->opensAt,
                'closes_at' => $this->closesAt,
                'eliminated_count' => $this->eliminatedCount,
                'previous_season_id' => $this->previousSeasonId,
                'reward_plan' => $plan,
            ], auth()->user());
        }, 'حُفظت إعدادات الدوري.');

        $this->loadSeason();
    }

    public function addWriter(): void
    {
        $this->run(function (Season $season) {
            $profile = CreatorProfile::query()->where('slug', trim($this->creatorSlug))->first();

            if ($profile === null) {
                throw new SeasonalRecognitionException('creator_not_found', 'لا يوجد مبدع بهذا المعرّف.');
            }

            app(SeasonWriterRosterService::class)->addWriter($season, $profile, auth()->user());
            $this->creatorSlug = '';
        }, 'أُضيف الكاتب إلى قائمة الموسم.');
    }

    public function removeWriter(string $publicId): void
    {
        $this->run(function (Season $season) use ($publicId) {
            $entry = SeasonWriterEntry::query()->where('season_id', $season->id)->where('public_id', $publicId)->firstOrFail();
            $result = app(SeasonWriterRosterService::class)->removeWriter($entry, auth()->user());
            $this->notify($result === 'removed' ? 'حُذف الكاتب من القائمة (قبل فتح التصويت).' : 'سُحب الكاتب من القائمة وبقيت نجومه محفوظة.');
        }, null);
    }

    public function linkSeries(string $entryPublicId): void
    {
        $this->run(function (Season $season) use ($entryPublicId) {
            $entry = SeasonWriterEntry::query()->where('season_id', $season->id)->where('public_id', $entryPublicId)->firstOrFail();
            $series = CreatorSeries::query()->where('public_id', trim($this->seriesInput[$entryPublicId] ?? ''))->first();

            if ($series === null) {
                throw new SeasonalRecognitionException('series_not_found', 'لا توجد سلسلة بهذا المعرّف.');
            }

            app(SeasonWriterRosterService::class)->linkSeries($entry, $series, auth()->user());
            $this->seriesInput[$entryPublicId] = '';
        }, 'رُبطت السلسلة بالكاتب لهذا الموسم.');
    }

    public function unlinkSeries(string $entryPublicId, int $seriesId): void
    {
        $this->run(function (Season $season) use ($entryPublicId, $seriesId) {
            $entry = SeasonWriterEntry::query()->where('season_id', $season->id)->where('public_id', $entryPublicId)->firstOrFail();
            app(SeasonWriterRosterService::class)->unlinkSeries($entry, CreatorSeries::query()->findOrFail($seriesId), auth()->user());
        }, 'فُكّ ربط السلسلة.');
    }

    public function finalizeWriters(): void
    {
        if (! $this->confirmWriters) {
            $this->notify('أكّد الإنهاء أولًا: لا رجعة بعده.', false);

            return;
        }

        $this->run(function (Season $season) {
            $result = app(SeasonWriterFinalizer::class)->finalize($season, auth()->user());
            $this->confirmWriters = false;
            $this->notify($result['already'] ? 'سبق إنهاء الترتيب.' : "أُنهي الترتيب: {$result['writers']} كاتبًا، {$result['podium']} على المنصة، {$result['awards']} جائزة مُسجَّلة.");
        }, null);
    }

    public function redeliverWriters(): void
    {
        $this->run(function (Season $season) {
            app(SeasonWriterFinalizer::class)->redeliver($season, auth()->user());
        }, 'أُعيد تسليم الإشعارات والجوائز التجميلية المعلّقة.');
    }

    public function showAward(string $publicId): void
    {
        $this->awardOpen = $this->awardOpen === $publicId ? null : $publicId;
        $this->settleReference = $this->awardNote = '';
        $this->settleMethod = 'manual';
    }

    public function grantCosmetics(): void
    {
        $this->run(function (Season $season) {
            $r = app(SeasonWriterAwardService::class)->grantPendingCosmetics($season, auth()->user());
            $this->notify("مُنحت {$r['granted']} جائزة تجميلية، وتعذّرت {$r['failed']}.", $r['failed'] === 0);
        }, null);
    }

    public function settleAward(): void
    {
        $this->run(function (Season $season) {
            $award = SeasonWriterAward::query()->where('season_id', $season->id)->where('public_id', (string) $this->awardOpen)->firstOrFail();
            app(SeasonWriterAwardService::class)->settleMonetary($award, auth()->user(), $this->settleMethod, $this->settleReference, $this->awardNote !== '' ? $this->awardNote : null);
            $this->awardOpen = null;
        }, 'سُجّلت تسوية الجائزة المالية (يدويًا خارج النظام).');
    }

    public function cancelAward(): void
    {
        $this->run(function (Season $season) {
            $award = SeasonWriterAward::query()->where('season_id', $season->id)->where('public_id', (string) $this->awardOpen)->firstOrFail();
            app(SeasonWriterAwardService::class)->cancel($award, auth()->user(), $this->awardNote);
            $this->awardOpen = null;
        }, 'أُلغيت الجائزة.');
    }

    public function toggleSupporters(): void
    {
        $this->run(function (Season $season) {
            $setting = app(SeasonRecognitionSettingsService::class)->settingFor($season);
            app(SeasonRecognitionSettingsService::class)->setSupportersEnabled($season, ! ($setting?->supporters_enabled ?? false), auth()->user());
        }, 'حُدّثت حالة تقدير الداعمين لهذا الموسم.');
    }

    public function seedPackages(): void
    {
        $this->run(function (Season $season) {
            $program = SponsorshipProgram::query()->findOrFail((int) $this->programId);
            $created = app(SeasonSupporterPackageService::class)->seedDefaults($season, $program, auth()->user());
            $this->notify($created > 0 ? "أُنشئت {$created} باقة افتراضية (فضي/ذهبي/ماسي)." : 'الباقات موجودة مسبقًا.');
        }, null);

        $this->loadSeason();
    }

    public function savePackage(int $packageId): void
    {
        $this->run(function (Season $season) use ($packageId) {
            $package = SeasonSupporterPackage::query()->where('season_id', $season->id)->findOrFail($packageId);
            $in = $this->packageInput[$packageId] ?? [];

            app(SeasonSupporterPackageService::class)->update($package, [
                'name' => $in['name'] ?? '',
                'support_points' => $in['support_points'] ?? '',
                'is_active' => (bool) ($in['is_active'] ?? false),
                'social_mention_eligible' => (bool) ($in['social_mention_eligible'] ?? false),
            ], auth()->user());
        }, 'حُفظت الباقة (تسري على الطلبات الجديدة فقط).');

        $this->loadSeason();
    }

    public function finalizeSupporters(): void
    {
        if (! $this->confirmSupporters) {
            $this->notify('أكّد الإنهاء أولًا: لا رجعة بعده.', false);

            return;
        }

        $this->run(function (Season $season) {
            $r = app(SeasonSupporterFinalizer::class)->finalize($season, auth()->user());
            $this->confirmSupporters = false;
            $this->notify($r['already'] ? 'سبق إنهاء ترتيب الداعمين.' : "أُنهي ترتيب الداعمين: {$r['ranked']} مرتَّبًا.");
        }, null);
    }

    public function transition(int $statId, string $to): void
    {
        $this->run(function (Season $season) use ($statId, $to) {
            $stat = SeasonSupporterStat::query()->where('season_id', $season->id)->findOrFail($statId);
            app(SeasonSupporterSocialQueueService::class)->transition($stat, $to, auth()->user(), ($this->queueNote[$statId] ?? '') !== '' ? $this->queueNote[$statId] : null);
            unset($this->queueNote[$statId]);
        }, 'حُدّثت حالة التنويه.');
    }

    public function rebuildWriters(): void
    {
        $this->run(function (Season $season) {
            app(SeasonWriterReconciler::class)->rebuildAggregates($season, auth()->user());
        }, 'أُعيد بناء مجاميع الدوري من دفتر التوزيعات.');
    }

    public function rebuildSupporters(): void
    {
        $this->run(function (Season $season) {
            $n = app(SeasonSupporterReconciler::class)->rebuildStats($season, auth()->user());
            $this->notify("أُعيد بناء {$n} صف إحصاء من الدفتر.");
        }, null);
    }

    public function activatePending(): void
    {
        $this->run(function (Season $season) {
            $n = app(SeasonSupporterReconciler::class)->activatePendingCards($season, auth()->user());
            $this->notify("فُعّلت {$n} بطاقة كان دفعها محقَّقًا.");
        }, null);
    }

    public function backfillPurchase(): void
    {
        $this->run(function (Season $season) {
            $purchase = StorePurchase::query()->find((int) $this->backfillPurchaseId);

            if ($purchase === null) {
                throw new SeasonalRecognitionException('purchase_not_found', 'لا يوجد شراء بهذا الرقم.');
            }

            $granted = app(SeasonSupporterReconciler::class)->backfillStorePurchase($season, $purchase, auth()->user());
            $this->backfillPurchaseId = '';
            $this->notify($granted ? 'مُنحت نقاط الشراء إلى هذا الموسم.' : 'سبق منح هذا الشراء أو أُنهي ترتيب الداعمين؛ لا تغيير.', $granted);
        }, null);
    }

    /** يشغّل عملية على الموسم المختار ويحوّل استثناءات الدومين إلى إشعار؛ أي استثناء آخر يُبلَّغ ولا يُسرّب تفاصيله. */
    protected function run(\Closure $operation, ?string $success): void
    {
        $season = $this->season();

        if ($season === null) {
            $this->notify('اختر موسمًا أولًا.', false);

            return;
        }

        try {
            $operation($season);

            if ($success !== null) {
                $this->notify($success);
            }
        } catch (SeasonalRecognitionException|SponsorshipException $e) {
            $this->notify($e->getMessage(), false);
        } catch (Throwable $e) {
            report($e);
            $this->notify('تعذّر تنفيذ العملية. راجع السجل.', false);
        }
    }

    protected function notify(string $title, bool $ok = true): void
    {
        $n = Notification::make()->title($title);
        $ok ? $n->success()->send() : $n->danger()->send();
    }
}
