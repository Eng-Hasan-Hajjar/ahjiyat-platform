# E32 — DELIVERY PACK (A–F)

**NO GIT COMMANDS.** لا commit ولا push ولا pull ولا أي أمر git يُطلب منك هنا؛ أنت تنسخ الملفات وتشغّل الأوامر المحلية فقط. أساس المقارنة: v31 = `151824b` (`151824be6d9dedf6325f6fffa99208739f7d2347`). هذا الملف والأرشيف خارج المستودع عمدًا (لا تنسخهما إليه). لا حزم جديدة (0 Composer / 0 npm)، و`composer.json` و`composer.lock` و`package*.json` لم تُمسّ، ولا أصول واجهة تغيّرت فلا `npm run build`.

الملفات: **57 جديدًا** + **1 ترحيلًا جديدًا** + **13 معدَّلًا** (كاملة). التحقق بعد النسخ: `manifest-sha256.txt` داخل `e32-files.zip` (السكربت في README.txt داخله وفي القسم E).

> **تنبيه أول (بوابة E31):** في CI لـ v31 نجحت خطوة `validate` (SQLite) لكن خطوة **«E31 on MySQL»** ضمن job `mysql-parity` **فشلت**، وتعذّرت قراءة سجلها من هنا. لذلك E31 تبقى **USER-LOCAL MYSQL VALIDATION STILL REQUIRED**. أرسل لي مخرجات فشل تلك الخطوة (من صفحة التشغيل في GitHub Actions) أو أول إخفاقات `php vendor/bin/pest --configuration=phpunit.mysql.xml.dist tests/Feature/DatabaseE31` محليًا (القسم F.2). تابعتُ E32 لأن فشل E31 لم يكن في مجموعة SQLite، لكن لا أدّعي أن أي شيء على MySQL قد تحقق.
>
> **حالة الإغلاق:** E32 **غير مغلقة** حتى تُنفَّذ بنود **USER-LOCAL MYSQL VALIDATION REQUIRED** (القسم F). لم يُشغَّل أي اختبار على MySQL هنا ولا يُدّعى ذلك.

## الفهرس

- 0) خريطة الملفات والبصمات
- A) NEW FILES (57 ملفًا، محتوى كامل، بلا الترحيل)
- B) MODIFIED FILES (13 ملفًا كاملًا + خريطة مواضع التعديل)
- C) MIGRATIONS (1، محتوى كامل)
- D) ENV / CONFIG
- E) LOCAL COMMANDS (PowerShell)
- F) MYSQL VALIDATION COMMANDS

---

## 0) خريطة الملفات والبصمات

| الحالة | المسار | بايت | SHA-256 |
|---|---|---:|---|
| جديد | `app/Console/Commands/ExpirePaymentRequests.php` | 935 | `660d967ba0d9de87…` |
| جديد | `app/Console/Commands/PrunePaymentProofs.php` | 1156 | `424edad7beb84cc1…` |
| جديد | `app/Console/Commands/ReconcileSponsorshipPayments.php` | 1721 | `72eacdcda1ff7b32…` |
| جديد | `app/Filament/Pages/PaymentSettingsPage.php` | 5647 | `b6293cedb77cf6bd…` |
| جديد | `app/Filament/Resources/SponsorshipPaymentResource.php` | 10950 | `8fbfe1465c0ae3e3…` |
| جديد | `app/Filament/Resources/SponsorshipPaymentResource/Pages/ListSponsorshipPayments.php` | 453 | `98763d8dd4bbb610…` |
| جديد | `app/Filament/Widgets/PaymentStatsWidget.php` | 2177 | `a709936cb9cc8e1f…` |
| جديد | `app/Http/Controllers/Support/CheckoutController.php` | 3971 | `02ca0dc913754bc4…` |
| جديد | `app/Http/Controllers/Support/PaymentController.php` | 4568 | `388663f89b1ced72…` |
| جديد | `app/Http/Controllers/Support/PaymentWebhookController.php` | 2220 | `f551278f1fede34c…` |
| جديد | `app/Http/Controllers/Support/StaffProofController.php` | 1076 | `fe53a95b1b25654d…` |
| جديد | `app/Http/Controllers/Support/SupportController.php` | 1812 | `ed8d40c344c3851c…` |
| جديد | `app/Http/Requests/Support/CheckoutRequest.php` | 1560 | `9562220ae3f2f393…` |
| جديد | `app/Http/Requests/Support/ProofUploadRequest.php` | 719 | `b9eb168d45c8ba3f…` |
| جديد | `app/Http/Requests/Support/TransferredRequest.php` | 1000 | `6bb066abfb22ae1e…` |
| جديد | `app/Models/SponsorshipPayment.php` | 8894 | `fd6290c072faad0b…` |
| جديد | `app/Policies/SponsorshipPaymentPolicy.php` | 1345 | `d3b06a121ad349b1…` |
| جديد | `app/Services/Payments/ManualPaymentProvider.php` | 2691 | `7caf17f559bf75e9…` |
| جديد | `app/Services/Payments/PaymentAnalytics.php` | 3605 | `aedc5b071dcb5f92…` |
| جديد | `app/Services/Payments/PaymentException.php` | 400 | `4158aad342e729d7…` |
| جديد | `app/Services/Payments/PaymentInit.php` | 422 | `bd900b6b1dd79154…` |
| جديد | `app/Services/Payments/PaymentInstructions.php` | 2131 | `61fa4770abea92ba…` |
| جديد | `app/Services/Payments/PaymentProofService.php` | 6041 | `4efa54a6de596a4d…` |
| جديد | `app/Services/Payments/PaymentProvider.php` | 3593 | `7476852eb00d8174…` |
| جديد | `app/Services/Payments/PaymentProviderRegistry.php` | 3074 | `b05b202572d0262a…` |
| جديد | `app/Services/Payments/PaymentReconciliationService.php` | 5820 | `823d092fc85d9af6…` |
| جديد | `app/Services/Payments/PaymentReferenceGenerator.php` | 1367 | `64450a249a1b80a9…` |
| جديد | `app/Services/Payments/PaymentRetentionService.php` | 3456 | `44b027b1dc3d15b7…` |
| جديد | `app/Services/Payments/PaymentRules.php` | 4409 | `e43494c8f947fb2b…` |
| جديد | `app/Services/Payments/PaymentSettingsGuard.php` | 2924 | `49008335cf42f25f…` |
| جديد | `app/Services/Payments/ProviderCallback.php` | 731 | `d5db7ef809d7e84c…` |
| جديد | `app/Services/Payments/SponsorshipPaymentService.php` | 35605 | `dca479e330d7975a…` |
| جديد | `app/Services/Payments/SupportCatalog.php` | 4175 | `4843a0cb4d89b41a…` |
| جديد | `config/payments.php` | 5564 | `50727e042323a30f…` |
| جديد | `database/seeders/DemoE32Seeder.php` | 5561 | `9c35bcd7746bb455…` |
| جديد | `docs/payment-operations-runbook.md` | 12038 | `d8cc08d9db96c589…` |
| جديد | `docs/sponsorship-payments-e32.md` | 22407 | `ae1bb06c078f16b5…` |
| جديد | `resources/views/filament/pages/payment-settings-page.blade.php` | 335 | `ff83c6cb32b41139…` |
| جديد | `resources/views/support/_progress.blade.php` | 1201 | `75c1ad6a8506d98c…` |
| جديد | `resources/views/support/_status.blade.php` | 570 | `42d2878d8f396e20…` |
| جديد | `resources/views/support/checkout.blade.php` | 9972 | `a5b6c2f375573b88…` |
| جديد | `resources/views/support/history.blade.php` | 1878 | `a91c2b336d65ccf8…` |
| جديد | `resources/views/support/index.blade.php` | 4493 | `8462bcbb86f545c6…` |
| جديد | `resources/views/support/payment.blade.php` | 9854 | `0bdb8b5173198820…` |
| جديد | `resources/views/support/program.blade.php` | 2466 | `c67a96d82697b7b9…` |
| جديد | `routes/payments.php` | 2937 | `9fccab618e2c901e…` |
| جديد | `routes/payments_webhooks.php` | 855 | `78821a026e6a07e4…` |
| جديد | `tests/Feature/PaymentsE32/E32AdminOpsTest.php` | 16024 | `f36a0f014230b8df…` |
| جديد | `tests/Feature/PaymentsE32/E32CheckoutTest.php` | 10797 | `ae5cfe427a15583d…` |
| جديد | `tests/Feature/PaymentsE32/E32ConcurrencyRewardTest.php` | 18333 | `49586e3896708f26…` |
| جديد | `tests/Feature/PaymentsE32/E32OwnershipManualTest.php` | 13337 | `a8871aabaf0f1065…` |
| جديد | `tests/Feature/PaymentsE32/E32PrivacyPerformanceTest.php` | 12172 | `26645ecbd81c1d09…` |
| جديد | `tests/Feature/PaymentsE32/E32ProviderReconcileExpiryTest.php` | 18356 | `653b13d22ee180d1…` |
| جديد | `tests/Feature/PaymentsE32/E32RejectRefundAnonymousTest.php` | 14452 | `2ef13667c95a4f56…` |
| جديد | `tests/Feature/PaymentsE32/E32StaticSecurityTest.php` | 10656 | `d9e27c638ba02bc7…` |
| جديد | `tests/Feature/PaymentsE32/E32VerificationTest.php` | 17952 | `a2e37322d56213f6…` |
| جديد | `tests/Feature/PaymentsE32/_support.php` | 5293 | `28fd602ed227bef9…` |
| ترحيل جديد | `database/migrations/2026_10_24_000001_create_sponsorship_payments_table.php` | 6802 | `c4b10013e056fe0d…` |
| معدَّل | `.env.example` | 20342 | `4bd7701a3d4e01ae…` |
| معدَّل | `app/Providers/AppServiceProvider.php` | 22122 | `97395cab43dd5e39…` |
| معدَّل | `app/Services/Database/FinancialHistoryGuard.php` | 3040 | `e658325d50d5fd34…` |
| معدَّل | `app/Services/Notifications/NotificationType.php` | 31840 | `c42b35e820b998c2…` |
| معدَّل | `app/Services/Sponsorship/ContributionService.php` | 19934 | `aaff75f050f080a8…` |
| معدَّل | `app/Services/Sponsorship/SponsorshipNotifier.php` | 4887 | `125caaa852faacf7…` |
| معدَّل | `app/Services/Sponsorship/SupporterService.php` | 10553 | `4f2da2cf52ac9975…` |
| معدَّل | `bootstrap/app.php` | 2978 | `6d7f5df6781fd345…` |
| معدَّل | `config/db_transfer.php` | 4851 | `190fbd76de620737…` |
| معدَّل | `config/platform.php` | 8378 | `faa14a2f32e5616a…` |
| معدَّل | `docs/environment-variables.md` | 13706 | `4ba00bbf58457fff…` |
| معدَّل | `routes/console.php` | 5665 | `52d85bb841058cce…` |
| معدَّل | `routes/web.php` | 26026 | `410324f68417ee6e…` |

البصمات الكاملة بجانب كل ملف أدناه وفي `manifest-sha256.txt`.

---

## A) NEW FILES — المسار + المحتوى الكامل (57 ملفًا)

### `app/Console/Commands/ExpirePaymentRequests.php`

`sha256: 660d967ba0d9de87ef51f7ac94c80434de221d099c76737c2d087b72134ce38d`

`````php
<?php

namespace App\Console\Commands;

use App\Services\Payments\SponsorshipPaymentService;
use Illuminate\Console\Command;

class ExpirePaymentRequests extends Command
{
    protected $signature = 'payments:expire-stale';

    protected $description = 'E32: يُنهي طلبات الدعم «بانتظار الدفع» المتأخرة عن مهلتها (حالة فقط، لا حذف). المرسَل/المحقَّق/المسترد لا ينتهي أبدًا. Idempotent؛ يُجدوَل كل 15 دقيقة.';

    public function handle(SponsorshipPaymentService $payments): int
    {
        try {
            $count = $payments->expireStale();
        } catch (\Throwable $e) {
            report($e);
            $this->error('فشل إنهاء الطلبات المتأخرة: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("expired={$count}");

        return self::SUCCESS;
    }
}
`````

### `app/Console/Commands/PrunePaymentProofs.php`

`sha256: 424edad7beb84cc15c4b639ec2d639b58a607e1b27a3e3036702a88b54ddc9b0`

`````php
<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentRetentionService;
use Illuminate\Console\Command;

class PrunePaymentProofs extends Command
{
    protected $signature = 'payments:prune-proofs {--dry-run : يعدّ المرشَّحين فقط بلا حذف}';

    protected $description = 'E32: يقلّم ملفات إثبات التحويل بعد مدة الاحتفاظ من الحالة النهائية (لا يحذف سجل الطلب، ولا يطال طلبًا مفتوحًا أو داخل نافذة النزاع). ابدأ بـ--dry-run.';

    public function handle(PaymentRetentionService $retention): int
    {
        if ($this->option('dry-run')) {
            $this->line('candidates='.$retention->count().' (dry-run: nothing was deleted)');

            return self::SUCCESS;
        }

        try {
            $count = $retention->prune();
        } catch (\Throwable $e) {
            report($e);
            $this->error('فشل تقليم الإثباتات: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("pruned={$count}");

        return self::SUCCESS;
    }
}
`````

### `app/Console/Commands/ReconcileSponsorshipPayments.php`

`sha256: 72eacdcda1ff7b32b1e0bda0b49005fe7ae4eaa6b18fd7cea1de50f411338d02`

`````php
<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentReconciliationService;
use Illuminate\Console\Command;

class ReconcileSponsorshipPayments extends Command
{
    protected $signature = 'sponsorship:reconcile-payments {--dry-run : للتوضيح فقط - الأمر للقراءة فقط دائمًا} {--json : مخرجات JSON}';

    protected $description = 'E32: مطابقة مالية للقراءة فقط بين طلبات الدعم والمساهمات (بلا أي إصلاح تلقائي). يخرج بـ1 عند وجود اختلافات. لا يطبع أسماء ولا بريدًا ولا مسارات إثبات ولا أسرارًا.';

    public function handle(PaymentReconciliationService $reconciliation): int
    {
        $findings = $reconciliation->run();
        $counts = $reconciliation->summarize($findings);

        if ($this->option('json')) {
            $this->line((string) json_encode(['mismatches' => count($findings), 'counts' => $counts, 'findings' => $findings], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $findings === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->line('E32 reconciliation (read-only; nothing was changed)');

        foreach ($counts as $code => $count) {
            $this->line(sprintf('%-36s %d', $code, $count));
        }

        foreach ($findings as $finding) {
            $this->line(sprintf('! %s payment=%s ref=%s contribution=%s', $finding['code'], $finding['payment'] ?? '-', $finding['reference'] ?? '-', $finding['contribution'] ?? '-'));
        }

        $this->line('mismatches='.count($findings));

        return $findings === [] ? self::SUCCESS : self::FAILURE;
    }
}
`````

### `app/Filament/Pages/PaymentSettingsPage.php`

`sha256: b6293cedb77cf6bde384b65000fff6d8e3700953e14c626c35e402fd269650d4`

`````php
<?php

namespace App\Filament\Pages;

use App\Services\OperationalAuditService;
use App\Services\Payments\PaymentRules;
use App\Services\Payments\PaymentSettingsGuard;
use App\Services\PlatformSettingsService;
use App\Services\Sponsorship\SponsorshipMoney;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * E32 - إعدادات الدفع اليدوي (تعليمات التحويل العلنية). صلاحية sponsorships.confirm (المالية) حصرًا: تغيير الحساب المستفيد يوجّه أموال الداعمين، فلا يكفي أن يكون المدير محرّر محتوى.
 * القراءة/الكتابة عبر PlatformSettingsService (مجموعة payments). لا أسرار: الحارس PaymentSettingsGuard يرفض كلمات المرور/PIN/CVV/المفاتيح وأرقام البطاقات.
 * التدقيق يسجّل المفاتيح المتغيّرة وبصمة قصيرة للقيمة الجديدة (لا القيمة نفسها).
 */
class PaymentSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'الرعاية والدعم';

    protected static ?string $navigationLabel = 'إعدادات الدفع';

    protected static ?string $title = 'إعدادات الدفع اليدوي';

    protected static ?string $slug = 'payment-settings';

    protected static ?int $navigationSort = 93;

    protected static string $view = 'filament.pages.payment-settings-page';

    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('sponsorships.confirm') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $values = app(PlatformSettingsService::class)->getGroup('payments');
        $raw = trim((string) ($values['allowed_currencies'] ?? ''));
        $values['allowed_currencies'] = $raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $raw))));

        $this->form->fill($values);
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('تفعيل')->schema([
                Forms\Components\Toggle::make('manual_enabled')->label('تفعيل التحويل اليدوي')->helperText('يعمل مع PAYMENTS_MANUAL_ENABLED بالبيئة (كلاهما شرط).'),
                Forms\Components\Toggle::make('proof_required')->label('إثبات التحويل إلزامي قبل الإرسال'),
                Forms\Components\CheckboxList::make('allowed_currencies')->label('العملات المسموحة لهذه الطريقة')->options(SponsorshipMoney::options())->columns(2)
                    ->helperText('فارغ = كل العملات المسموحة عامة ('.implode('، ', PaymentRules::currencies()).').'),
            ])->columns(1),
            Forms\Components\Section::make('تعليمات التحويل (تُعرض لصاحب الطلب فقط)')
                ->description('معلومات تحويل علنية مقصودة فقط. لا تُدخل كلمات مرور بنكية أو أرقام بطاقات أو مفاتيح.')
                ->schema([
                    Forms\Components\TextInput::make('manual_title')->label('عنوان الطريقة')->maxLength(80)->required(),
                    Forms\Components\TextInput::make('manual_beneficiary')->label('اسم المستفيد')->maxLength(120),
                    Forms\Components\TextInput::make('manual_bank_name')->label('اسم البنك')->maxLength(120),
                    Forms\Components\Textarea::make('manual_account')->label('الحساب / الآيبان')->rows(2)->maxLength(300),
                    Forms\Components\Textarea::make('manual_reference_note')->label('تعليمات رمز المرجع')->rows(2)->maxLength(300),
                    Forms\Components\Textarea::make('manual_extra_note')->label('ملاحظة إضافية')->rows(2)->maxLength(500),
                ])->columns(1),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        $state['allowed_currencies'] = implode(',', array_map('strval', (array) ($state['allowed_currencies'] ?? [])));
        $bad = PaymentSettingsGuard::violations($state);

        if ($bad !== []) {
            Notification::make()->danger()->title('لم يُحفظ: بعض الحقول تحوي بيانات اعتماد أو أرقام بطاقات (لا يُسمح بها).')->send();

            return;
        }

        $settings = app(PlatformSettingsService::class);
        $before = $settings->getGroup('payments');
        $settings->setMany('payments', $state, auth()->user());
        $after = $settings->getGroup('payments');
        $changed = [];

        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changed[$key] = is_bool($value) ? $value : substr(hash('sha256', (string) $value), 0, 12);
            }
        }

        if ($changed !== []) {
            app(OperationalAuditService::class)->log('payment_settings_updated', null, ['changed' => $changed], auth()->user());
        }

        Notification::make()->success()->title('تم حفظ إعدادات الدفع.')->send();
    }
}
`````

### `app/Filament/Resources/SponsorshipPaymentResource.php`

`sha256: 8fbfe1465c0ae3e39dfeb336be7095575d0cfc35bc75798d6e441fbf98ea1c92`

`````php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SponsorshipPaymentResource\Pages;
use App\Models\SponsorshipPayment;
use App\Models\SponsorshipProgram;
use App\Services\Payments\PaymentAnalytics;
use App\Services\Payments\SponsorshipPaymentService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipMoney;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E32 - طابور مراجعة طلبات الدعم/الدفع (مالي). العرض sponsorships.view؛ كل إجراء (تحقق/رفض/توضيح/إلغاء/استرداد) sponsorships.confirm عبر
 * SponsorshipPaymentService حصرًا (قفل صف + معاملة ذرّية + تدقيق). لا إنشاء/تعديل خام ولا حذف من الجدول: التاريخ المالي يبقى.
 * الإثبات يُفتح عبر مسار مخوَّل (مرفق خامل + تدقيق التنزيل) لا برابط تخزين. لا بريد ولا بيانات بنكية للمحوِّل هنا. ملاحظات المراجع الداخلية تظهر لمن يملك confirm فقط.
 */
class SponsorshipPaymentResource extends Resource
{
    protected static ?string $model = SponsorshipPayment::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'الرعاية والدعم';

    protected static ?string $navigationLabel = 'طلبات الدعم والدفع';

    protected static ?string $modelLabel = 'طلب دعم';

    protected static ?string $pluralModelLabel = 'طلبات الدعم والدفع';

    protected static ?int $navigationSort = 91;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = (int) (app(PaymentAnalytics::class)->summary()['awaiting_verification'] ?? 0);

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        $canConfirm = fn (): bool => auth()->user()?->can('sponsorships.confirm') ?? false;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('sponsorship_payments.*')
                ->addSelect(['dup_proof' => SponsorshipPayment::query()->from('sponsorship_payments as d')->selectRaw('COUNT(*)')
                    ->whereColumn('d.proof_sha256', 'sponsorship_payments.proof_sha256')->whereColumn('d.id', '!=', 'sponsorship_payments.id')])
                ->with(['program:id,title', 'user:id,name']))
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([10, 20, 50])
            ->defaultPaginationPageOption(20)
            ->columns([
                Tables\Columns\TextColumn::make('reference')->label('المرجع')->searchable()->copyable()->fontFamily('mono'),
                Tables\Columns\TextColumn::make('user.name')->label('الداعم')->limit(24),
                Tables\Columns\TextColumn::make('program.title')->label('البرنامج')->limit(26),
                Tables\Columns\TextColumn::make('amount_minor')->label('المبلغ')->state(fn (SponsorshipPayment $r) => $r->amountLabel()),
                Tables\Columns\TextColumn::make('provider')->label('الطريقة')->badge()->color('gray'),
                Tables\Columns\IconColumn::make('is_anonymous')->label('مجهول')->boolean(),
                Tables\Columns\IconColumn::make('proof_path')->label('إثبات')->boolean()->state(fn (SponsorshipPayment $r) => $r->hasProof()),
                Tables\Columns\TextColumn::make('dup_proof')->label('إثبات مكرر')->badge()->color('danger')->formatStateUsing(fn ($state) => (int) $state > 0 ? 'مكرر ×'.(int) $state : null)->toggleable(),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => SponsorshipPayment::statusLabels()[$state] ?? $state)
                    ->color(fn ($state) => ['awaiting_payment' => 'warning', 'submitted' => 'primary', 'verified' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray', 'expired' => 'gray', 'refunded' => 'gray'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('submitted_at')->label('أُرسل')->dateTime('Y-m-d H:i')->sortable(),
                Tables\Columns\TextColumn::make('payer_note')->label('ملاحظة المحوِّل')->limit(30)->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('review_note')->label('ملاحظة داخلية')->limit(30)->visible($canConfirm)->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('أُنشئ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(SponsorshipPayment::statusLabels()),
                Tables\Filters\SelectFilter::make('provider')->label('الطريقة')->options(fn () => collect((array) config('payments.providers'))->keys()->mapWithKeys(fn ($k) => [(string) $k => (string) $k])->all()),
                Tables\Filters\SelectFilter::make('sponsorship_program_id')->label('البرنامج')
                    ->options(fn () => SponsorshipProgram::query()->orderByDesc('id')->limit(100)->pluck('title', 'id')->all()),
                Tables\Filters\TernaryFilter::make('is_anonymous')->label('مجهول'),
                Tables\Filters\TernaryFilter::make('has_proof')->label('إثبات مرفوع')->queries(
                    true: fn (Builder $q) => $q->whereNotNull('proof_path'),
                    false: fn (Builder $q) => $q->whereNull('proof_path'),
                ),
            ])
            ->actions([
                Tables\Actions\Action::make('proof')->label('الإثبات')->icon('heroicon-o-paper-clip')->color('gray')
                    ->visible(fn (SponsorshipPayment $r) => $r->hasProof() && $canConfirm())
                    ->url(fn (SponsorshipPayment $r) => route('payments.staff.proof', ['payment' => $r->public_id]))->openUrlInNewTab(),

                Tables\Actions\Action::make('verify')->label('تحقق')->color('success')->icon('heroicon-o-check-circle')
                    ->visible(fn (SponsorshipPayment $r) => $r->isOpen() && $canConfirm())
                    ->modalDescription('يُنشأ دعم مؤكَّد ويُحتسب فورًا في التمويل والمستوى والصدارة والتعزيز. تأكد من وصول المبلغ فعلًا.')
                    ->form(fn (SponsorshipPayment $r) => $r->currency === SponsorshipMoney::accountingCurrency() ? [] : [
                        Forms\Components\TextInput::make('accounting_amount')->label('المعادل بعملة المحاسبة ('.SponsorshipMoney::accountingCurrency().') — إلزامي لأن العملة مختلفة')->required()->maxLength(20),
                    ])
                    ->requiresConfirmation()
                    ->action(fn (SponsorshipPayment $record, array $data) => self::guard(fn () => app(SponsorshipPaymentService::class)->verify(auth()->user(), $record, $data['accounting_amount'] ?? null), 'تم التحقق وتأكيد الدعم.')),

                Tables\Actions\Action::make('reject')->label('رفض')->color('danger')->icon('heroicon-o-x-circle')
                    ->visible(fn (SponsorshipPayment $r) => $r->isOpen() && $canConfirm())
                    ->form([
                        Forms\Components\Select::make('code')->label('السبب')->required()->options(SponsorshipPayment::failureLabels()),
                        Forms\Components\Textarea::make('message')->label('رسالة لصاحب الطلب (تظهر له)')->rows(2)->maxLength(255),
                        Forms\Components\Textarea::make('note')->label('ملاحظة داخلية (لا تظهر له)')->rows(2)->maxLength(500),
                    ])
                    ->action(fn (SponsorshipPayment $record, array $data) => self::guard(fn () => app(SponsorshipPaymentService::class)->reject(auth()->user(), $record, (string) $data['code'], $data['message'] ?? null, $data['note'] ?? null), 'رُفض الطلب.')),

                self::reasonAction('clarify', 'طلب توضيح', 'warning', fn (SponsorshipPayment $r) => $r->status === SponsorshipPayment::STATUS_SUBMITTED,
                    fn (SponsorshipPaymentService $s, SponsorshipPayment $r, string $reason) => $s->requestClarification(auth()->user(), $r, $reason), 'أُرسل طلب التوضيح.', 'الرسالة لصاحب الطلب (إلزامي)'),
                self::reasonAction('cancel', 'إلغاء', 'gray', fn (SponsorshipPayment $r) => $r->isOpen(),
                    fn (SponsorshipPaymentService $s, SponsorshipPayment $r, string $reason) => $s->cancel(auth()->user(), $r, $reason), 'أُلغي الطلب.', 'سبب الإلغاء (يظهر لصاحب الطلب)'),
                self::reasonAction('refund', 'استرداد', 'warning', fn (SponsorshipPayment $r) => $r->status === SponsorshipPayment::STATUS_VERIFIED,
                    fn (SponsorshipPaymentService $s, SponsorshipPayment $r, string $reason) => $s->refund(auth()->user(), $r, $reason), 'سُجّل الاسترداد (لا تُسحب مكافآت سبق منحها).', 'سبب الاسترداد (إلزامي)'),
            ]);
    }

    protected static function reasonAction(string $name, string $label, string $color, \Closure $when, \Closure $call, string $ok, string $field): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)
            ->visible(fn (SponsorshipPayment $r) => $when($r) && (auth()->user()?->can('sponsorships.confirm') ?? false))
            ->form([Forms\Components\Textarea::make('reason')->label($field)->required()->minLength(3)->maxLength(255)])
            ->action(fn (SponsorshipPayment $record, array $data) => self::guard(fn () => $call(app(SponsorshipPaymentService::class), $record, (string) $data['reason']), $ok));
    }

    protected static function guard(\Closure $call, string $ok): void
    {
        try {
            $call();
            app(PaymentAnalytics::class)->forget();
            Notification::make()->success()->title($ok)->send();
        } catch (SponsorshipException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSponsorshipPayments::route('/')];
    }
}
`````

### `app/Filament/Resources/SponsorshipPaymentResource/Pages/ListSponsorshipPayments.php`

`sha256: 98763d8dd4bbb610cfe60afd6206f80fc3525511f0d4d60b4ca7751730dc60b7`

`````php
<?php

namespace App\Filament\Resources\SponsorshipPaymentResource\Pages;

use App\Filament\Resources\SponsorshipPaymentResource;
use App\Filament\Widgets\PaymentStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListSponsorshipPayments extends ListRecords
{
    protected static string $resource = SponsorshipPaymentResource::class;

    protected function getHeaderWidgets(): array
    {
        return [PaymentStatsWidget::class];
    }
}
`````

### `app/Filament/Widgets/PaymentStatsWidget.php`

`sha256: a709936cb9cc8e1fcd22cfd466e3e885da6ad2f4937115d34158d563727276f7`

`````php
<?php

namespace App\Filament\Widgets;

use App\Services\Payments\PaymentAnalytics;
use App\Services\Sponsorship\SponsorshipMoney;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * E32 - ملخص العمليات المالية لرأس طابور طلبات الدعم. تجميعات قليلة مخزَّنة مدة قصيرة؛ لا هويات ولا تفاصيل دفع. يراه sponsorships.view فقط. غير مكتشَف تلقائيًا بالرئيسية.
 */
class PaymentStatsWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('sponsorships.view') ?? false;
    }

    protected function getStats(): array
    {
        $s = app(PaymentAnalytics::class)->summary();

        return [
            Stat::make('بانتظار التحقق', number_format($s['awaiting_verification']))->description($s['pending'].' بانتظار الدفع')->icon('heroicon-o-clock')->color($s['awaiting_verification'] > 0 ? 'warning' : 'success'),
            Stat::make('حُقّق اليوم', number_format($s['verified_today']))->icon('heroicon-o-check-circle')->color('success'),
            Stat::make('مرفوض/ملغى/منتهٍ', number_format($s['failed']))->description('آخر '.(int) config('payments.analytics.window_days', 30).' يومًا')->icon('heroicon-o-x-circle')->color('gray'),
            Stat::make('مُسترد', number_format($s['refunded']))->icon('heroicon-o-arrow-uturn-left')->color('gray'),
            Stat::make('إجمالي المؤكَّد', SponsorshipMoney::format($s['confirmed_total_minor'], $s['currency']))->description($s['confirmed_count'].' مساهمة · المتوسط '.SponsorshipMoney::format($s['average_minor'], $s['currency']))->icon('heroicon-o-banknotes')->color('success'),
            Stat::make('حصة التحويل اليدوي', $s['manual_share'].'٪')->description('من الطلبات المحقَّقة')->icon('heroicon-o-building-library')->color('primary'),
        ];
    }
}
`````

### `app/Http/Controllers/Support/CheckoutController.php`

`sha256: 02ca0dc913754bc42ff9e2d8ab30d6ecdddd85f43b34c09425a56adbec765530`

`````php
<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\CheckoutRequest;
use App\Models\SponsorshipProgram;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Payments\PaymentRules;
use App\Services\Payments\SponsorshipPaymentService;
use App\Services\Sponsorship\SponsorshipMoney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/**
 * E32 - صفحة الدفع (3 خطوات) وإنشاء طلب الدعم. للمستخدم المصادَق والموثّق فقط (الضيف: متابعة P1). كل شيء يُعاد التحقق منه في الخادم:
 * البرنامج من المسار، المبلغ من النص المُرسَل بعد التحليل، العملة من القائمة البيضاء، الطريقة من السجل. حقول مثل status/accounting/user_id/tier لا تُقرأ أبدًا.
 */
class CheckoutController extends Controller
{
    public function __construct(protected SponsorshipPaymentService $payments, protected PaymentProviderRegistry $providers) {}

    public function create(SponsorshipProgram $program)
    {
        abort_unless((bool) config('sponsorship.enabled', true) && $program->isActiveNow(), 404);

        $keys = $this->providers->enabledKeys();
        $methods = [];

        foreach ($keys as $key) {
            $provider = $this->providers->provider($key);
            $methods[$key] = ['label' => $provider->label(), 'currencies' => PaymentRules::currencies($provider)];
        }

        $lists = array_map(fn (array $m) => $m['currencies'], array_values($methods));
        $currencies = $lists === [] ? [] : array_values(array_unique(array_merge(...$lists)));
        $default = in_array(SponsorshipMoney::accountingCurrency(), $currencies, true) ? SponsorshipMoney::accountingCurrency() : ($currencies[0] ?? null);

        return view('support.checkout', [
            'program' => $program,
            'methods' => $methods,
            'currencies' => $currencies,
            'currencyOptions' => SponsorshipMoney::options(),
            'defaultCurrency' => old('currency', $default),
            'suggestions' => $default === null ? [] : PaymentRules::suggestions($default),
            'allowCustom' => (bool) config('payments.checkout.allow_custom_amount', true),
            'minLabel' => $default === null ? null : SponsorshipMoney::format(PaymentRules::minMinor(), $default),
            'requestKey' => old('request_key', (string) Str::ulid()),
            'defaultName' => (string) auth()->user()?->name,
            'enabled' => (bool) config('payments.enabled', true) && $methods !== [],
        ]);
    }

    public function store(CheckoutRequest $request, SponsorshipProgram $program): RedirectResponse
    {
        abort_unless((bool) config('sponsorship.enabled', true), 404);

        $input = $request->validated();

        try {
            $payment = $this->payments->createRequest(
                $request->user(),
                $program,
                (string) $input['amount'],
                (string) $input['currency'],
                $input['visibility'] === 'anonymous',
                isset($input['display_name']) ? (string) $input['display_name'] : null,
                isset($input['provider']) && $input['provider'] !== '' ? (string) $input['provider'] : null,
                isset($input['request_key']) ? (string) $input['request_key'] : null,
            );
        } catch (PaymentException $e) {
            return back()->withInput($request->except(['request_key']))->with('error', $e->getMessage());
        }

        return redirect()->route('support.payments.show', ['payment' => $payment->public_id])
            ->with('success', 'تم إنشاء طلب الدعم. أتمم التحويل ثم أرسل الإثبات؛ لن يُحتسب الدعم قبل تحقق الفريق.');
    }
}
`````

### `app/Http/Controllers/Support/PaymentController.php`

`sha256: 388663f89b1ced723ccc82116dcbe98f1f2ebb870e831b2c4acc864d90aa8b72`

`````php
<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\ProofUploadRequest;
use App\Http\Requests\Support\TransferredRequest;
use App\Models\SponsorshipPayment;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentInstructions;
use App\Services\Payments\PaymentProofService;
use App\Services\Payments\SponsorshipPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E32 - صفحات صاحب الطلب: تفاصيل الطلب والتعليمات، «حوّلت المبلغ»، رفع/استبدال الإثبات، الإلغاء قبل الإرسال، السجل، وتنزيل الإثبات.
 * كل وصول مقيَّد بالملكية **داخل الاستعلام نفسه** (ownedOrFail ⇒ 404 لغير المالك حتى لو كان مديرًا؛ المديرون يستعملون لوحة الإدارة): لا تسريب وجود الطلب، ولا ثغرة IDOR.
 * المعرّف العلني ULID فقط (المعرّف التسلسلي لا يُوجَّه). لا يُعرض للمالك: ملاحظات المراجع/رمز الرفض/مسار الإثبات/بصمته/مرجع المزوّد.
 */
class PaymentController extends Controller
{
    public function __construct(
        protected SponsorshipPaymentService $payments,
        protected PaymentInstructions $instructions,
        protected PaymentProofService $proofs,
    ) {}

    public function history(Request $request)
    {
        $payments = SponsorshipPayment::query()->where('user_id', $request->user()->id)
            ->with('program:id,slug,title')
            ->orderByDesc('id')
            ->select(['id', 'public_id', 'reference', 'sponsorship_program_id', 'amount_minor', 'currency', 'status', 'is_anonymous', 'created_at', 'user_id'])
            ->paginate(15);

        return view('support.history', ['payments' => $payments]);
    }

    public function show(Request $request, string $payment)
    {
        $model = $this->ownedOrFail($request, $payment, ['program:id,slug,title']);

        return view('support.payment', [
            'payment' => $model,
            'instructions' => $model->isOpen() ? $this->instructions->forPayment($model) : null,
            'proofAvailable' => $this->proofs->exists($model),
        ]);
    }

    public function transferred(TransferredRequest $request, string $payment): RedirectResponse
    {
        $model = $this->ownedOrFail($request, $payment);

        try {
            $this->payments->markTransferred($request->user(), $model, $request->input('payer_note'), $request->file('proof'));
        } catch (PaymentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('support.payments.show', ['payment' => $model->public_id])
            ->with('success', 'طلب الدعم بانتظار التحقق. سنُعلمك فور مراجعته.');
    }

    public function uploadProof(ProofUploadRequest $request, string $payment): RedirectResponse
    {
        $model = $this->ownedOrFail($request, $payment);

        try {
            $this->payments->attachProof($request->user(), $model, $request->file('proof'));
        } catch (PaymentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'تم حفظ الإثبات.');
    }

    public function cancel(Request $request, string $payment): RedirectResponse
    {
        $model = $this->ownedOrFail($request, $payment);

        try {
            $this->payments->cancelByOwner($request->user(), $model);
        } catch (PaymentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('support.history')->with('success', 'تم إلغاء طلب الدعم.');
    }

    /** تنزيل المالك لإثباته هو فقط (مرفق خامل). */
    public function proof(Request $request, string $payment)
    {
        return $this->proofs->download($this->ownedOrFail($request, $payment));
    }

    /**
     * @param  list<string>  $with
     */
    protected function ownedOrFail(Request $request, string $publicId, array $with = []): SponsorshipPayment
    {
        abort_unless(preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $publicId) === 1, 404);

        return SponsorshipPayment::query()->where('user_id', $request->user()->id)->where('public_id', $publicId)->with($with)->firstOrFail();
    }
}
`````

### `app/Http/Controllers/Support/PaymentWebhookController.php`

`sha256: f551278f1fede34c6d25abc7d722fa3be7799463178bb95d8c3eccb9ad397d25`

`````php
<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Payments\SponsorshipPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * E32 - مدخل Webhook عام لمزوّدين إلكترونيين **مستقبليين** (POST /api/payments/webhooks/{provider}). لا مزوّد إلكتروني منفَّذ الآن، والمسار مغلق افتراضيًا (PAYMENTS_WEBHOOKS_ENABLED=false):
 *  - مزوّد غير معروف / معطَّل / لا يدعم webhook (مثل manual) ⇒ 404 بلا أي أثر.
 *  - لا يُعتمد أي حدث دون أن يتحقق المزوّد نفسه من **توقيعه** (verifyCallback)؛ فشل التحقق ⇒ 400 بلا أثر.
 *  - الجسم لا يُسجَّل ولا يُخزَّن (سقف حجم فقط)؛ لا أسرار في الاستجابة. تكرار الحدث (replay) آمن: الأثر مرتبط بحالة الطلب وقيود قاعدة البيانات الفريدة.
 * الاستجابة دائمًا JSON صغير بلا تفاصيل داخلية.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(protected PaymentProviderRegistry $providers, protected SponsorshipPaymentService $payments) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        abort_unless((bool) config('payments.webhooks.enabled', false), 404);

        try {
            $adapter = $this->providers->get($provider);
        } catch (PaymentException) {
            abort(404);
        }

        abort_unless($adapter->supportsWebhook(), 404);
        abort_if(strlen((string) $request->getContent()) > (int) config('payments.webhooks.max_body_bytes', 65536), 413);

        try {
            $callback = $adapter->verifyCallback($request);
        } catch (PaymentException) {
            return response()->json(['ok' => false], 400);
        }

        $result = $this->payments->applyProviderCallback($adapter->key(), $callback);

        return response()->json(['ok' => true, 'result' => $result]);
    }
}
`````

### `app/Http/Controllers/Support/StaffProofController.php`

`sha256: fe53a95b1b25654de82f985135d8583d60f20a29f7afab144e28fd7310400065`

`````php
<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\SponsorshipPayment;
use App\Services\OperationalAuditService;
use App\Services\Payments\PaymentProofService;
use Illuminate\Support\Facades\Gate;

/**
 * E32 - تنزيل الإثبات للمراجع المالي (sponsorships.confirm عبر SponsorshipPaymentPolicy::downloadProof). الملف خاص: لا رابط مباشر، التنزيل مرفق خامل، وكل تنزيل يُدقَّق (من ومتى).
 */
class StaffProofController extends Controller
{
    public function __construct(protected PaymentProofService $proofs, protected OperationalAuditService $audit) {}

    public function __invoke(SponsorshipPayment $payment)
    {
        Gate::authorize('downloadProof', $payment);
        abort_unless($this->proofs->exists($payment), 404);

        $this->audit->log('payment_proof_downloaded', $payment, ['public_id' => $payment->public_id, 'reference' => $payment->reference], auth()->user());

        return $this->proofs->download($payment);
    }
}
`````

### `app/Http/Controllers/Support/SupportController.php`

`sha256: ed8d40c344c3851c3b4bbb68f022b38590952423725e84ac8ea464b7800a7c1c`

`````php
<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\SponsorshipProgram;
use App\Services\Payments\SupportCatalog;
use App\Services\Sponsorship\SponsorshipTargets;

/**
 * E32 - مدخل الدعم العام (قراءة فقط): البرامج النشطة الآن فقط. برنامج مسودّة/مغلق/ملغى/منتهٍ ⇒ 404 (لا بدء طلب). لا بيانات دفع هنا؛ زر «ادعم» يقود لصفحة الدفع (تتطلب تسجيل الدخول).
 * أحجيات المجتمع والسلاسل ليست أهدافًا للرعاية أصلًا (SponsorshipTargets)، فلا يوجد دعم لها.
 */
class SupportController extends Controller
{
    public function __construct(protected SupportCatalog $catalog, protected SponsorshipTargets $targets) {}

    public function index()
    {
        abort_unless((bool) config('sponsorship.enabled', true), 404);

        $programs = $this->catalog->programs();

        return view('support.index', [
            'programs' => $programs,
            'cards' => $this->catalog->cards($programs),
            'targets' => $this->targets,
            'enabled' => (bool) config('payments.enabled', true),
        ]);
    }

    public function show(SponsorshipProgram $program)
    {
        abort_unless((bool) config('sponsorship.enabled', true) && $program->isActiveNow(), 404);

        $programs = collect([$program]);

        return view('support.program', [
            'program' => $program,
            'card' => $this->catalog->cards($programs)[(int) $program->id],
            'targetLabel' => $this->targets->label($program),
            'targetUrl' => $this->targets->url($program),
            'enabled' => (bool) config('payments.enabled', true),
        ]);
    }
}
`````

### `app/Http/Requests/Support/CheckoutRequest.php`

`sha256: 9562220ae3f2f393f2471775bc3d375e421fbca3370aba784e715bd1471aec3b`

`````php
<?php

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

/**
 * E32 - إنشاء طلب دعم. الحقول المقبولة فقط: المبلغ/العملة/الخصوصية/الاسم/الطريقة/مفتاح عدم التكرار. أي حقل آخر (status/accounting/tier/user_id/program_id…) يُتجاهل كليًا:
 * الخادم لا يقرأه ولا يطبّقه (المتحكم يمرّر الحقول المعتمدة صراحةً، لا $request->all()). البرنامج يأتي من المسار (slug) لا من الجسم.
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'max:20'],
            'currency' => ['required', 'string', 'size:3'],
            'visibility' => ['required', 'in:public,anonymous'],
            'display_name' => ['nullable', 'string', 'max:'.(int) config('payments.checkout.display_name_max', 80)],
            'provider' => ['nullable', 'string', 'max:32'],
            'request_key' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['amount' => 'المبلغ', 'currency' => 'العملة', 'visibility' => 'الظهور', 'display_name' => 'الاسم المعروض', 'provider' => 'طريقة الدفع'];
    }
}
`````

### `app/Http/Requests/Support/ProofUploadRequest.php`

`sha256: b9eb168d45c8ba3f8fa7591225dcebe18b5a7b5cfec19bdcc30a87640a23f118`

`````php
<?php

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

/** E32 - رفع/استبدال الإثبات. الملف مطلوب هنا؛ الفحص الحقيقي لمحتواه في PaymentProofService. */
class ProofUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['proof' => ['required', 'file', 'max:'.(int) config('payments.proof.max_kb', 5120)]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['proof' => 'الإثبات'];
    }
}
`````

### `app/Http/Requests/Support/TransferredRequest.php`

`sha256: 6bb066abfb22ae1e3e77383369ab502d92b0c4b73985cd29f8b5d46da2e9d2ee`

`````php
<?php

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

/** E32 - «حوّلت المبلغ»: ملاحظة المحوِّل + إثبات اختياري. الفحص الحقيقي لمحتوى الملف (النوع الفعلي/الحجم/الأبعاد) في PaymentProofService؛ هنا حدود خشنة فقط. */
class TransferredRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'payer_note' => ['nullable', 'string', 'max:'.(int) config('payments.checkout.note_max', 255)],
            'proof' => ['nullable', 'file', 'max:'.(int) config('payments.proof.max_kb', 5120)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['payer_note' => 'الملاحظة', 'proof' => 'الإثبات'];
    }
}
`````

### `app/Models/SponsorshipPayment.php`

`sha256: fd6290c072faad0bdd81b306d93dcd2a8b237c0eee63ec056a7455c40c9dd054`

`````php
<?php

namespace App\Models;

use App\Services\Payments\PaymentException;
use App\Services\Sponsorship\SponsorshipMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * E32 - طلب دعم/دفع. يتتبّع مسار الدفع فقط؛ **المساهمة المؤكَّدة (SponsorshipContribution) هي الوحيدة التي تُحتسب مالًا** (تمويل/مستوى/صدارة/تعزيز).
 * طلب في أي حالة غير verified/refunded لا يؤثر في أي مجموع أو مستوى أو تعزيز.
 *
 * آلة الحالات (7 حالات فقط، لا حالات غامضة):
 *   awaiting_payment → submitted | verified (تحقق مباشر من المالية دون ضغط «حوّلت») | cancelled | expired | rejected
 *   submitted        → verified | rejected | cancelled | awaiting_payment (طلب توضيح)
 *   verified         → refunded
 *   rejected | cancelled | expired | refunded → (نهائية)
 * الحقول المالية/الهوية (المبلغ/العملة/البرنامج/المستخدم/المزوّد/المرجع) لا تتغير بعد الإنشاء إطلاقًا؛ ربط المساهمة مرة واحدة فقط (null → قيمة) ولا يتغير.
 * لا حذف أبدًا. لا تعيين جماعي ($guarded): الكتابة عبر SponsorshipPaymentService حصرًا (قفل + تدقيق).
 */
class SponsorshipPayment extends Model
{
    public const STATUS_AWAITING = 'awaiting_payment';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_AWAITING, self::STATUS_SUBMITTED, self::STATUS_VERIFIED, self::STATUS_REJECTED,
        self::STATUS_CANCELLED, self::STATUS_EXPIRED, self::STATUS_REFUNDED,
    ];

    /** مفتوحة = تنتظر إجراءً (تُحتسب في سقف الطلبات المفتوحة لكل مستخدم). */
    public const OPEN_STATUSES = [self::STATUS_AWAITING, self::STATUS_SUBMITTED];

    /** رموز سبب الرفض المسموحة (قائمة بيضاء؛ لا نص حر في العمود). */
    public const FAILURE_CODES = ['amount_mismatch', 'reference_missing', 'proof_unreadable', 'not_received', 'duplicate', 'suspected_fraud', 'other'];

    /** @var list<string> حقول لا تتغير بعد الإنشاء. */
    public const IMMUTABLE_FIELDS = ['public_id', 'reference', 'sponsorship_program_id', 'user_id', 'provider', 'amount_minor', 'currency', 'is_anonymous'];

    private const TRANSITIONS = [
        self::STATUS_AWAITING => [self::STATUS_SUBMITTED, self::STATUS_VERIFIED, self::STATUS_CANCELLED, self::STATUS_EXPIRED, self::STATUS_REJECTED],
        self::STATUS_SUBMITTED => [self::STATUS_VERIFIED, self::STATUS_REJECTED, self::STATUS_CANCELLED, self::STATUS_AWAITING],
        self::STATUS_VERIFIED => [self::STATUS_REFUNDED],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_EXPIRED => [],
        self::STATUS_REFUNDED => [],
    ];

    protected $guarded = ['*'];

    /** حقول داخلية لا تُسلسَل أبدًا (JSON/API/عرض). */
    protected $hidden = ['review_note', 'proof_path', 'proof_sha256', 'provider_reference', 'request_key', 'failure_code'];

    protected static function booted(): void
    {
        static::creating(function (SponsorshipPayment $p) {
            $p->public_id ??= (string) Str::ulid();
        });

        static::updating(function (SponsorshipPayment $p) {
            $was = (string) $p->getOriginal('status');

            if ($p->isDirty('status') && ! in_array((string) $p->status, self::TRANSITIONS[$was] ?? [], true)) {
                throw new PaymentException('انتقال حالة غير مسموح لطلب الدعم هذا.');
            }

            foreach (self::IMMUTABLE_FIELDS as $field) {
                if ($p->isDirty($field)) {
                    throw new PaymentException('حقول الطلب الأساسية محمية؛ أنشئ طلبًا جديدًا.');
                }
            }

            // الربط بالمساهمة مرة واحدة: null → قيمة فقط.
            if ($p->isDirty('sponsorship_contribution_id') && $p->getOriginal('sponsorship_contribution_id') !== null) {
                throw new PaymentException('ربط المساهمة لا يتغير بعد تحديده.');
            }

            // المعادل المحاسبي يُسجَّل مرة عند التحقق ولا يُعدَّل بعده.
            if (($p->isDirty('accounting_amount_minor') || $p->isDirty('accounting_currency')) && $p->getOriginal('accounting_amount_minor') !== null) {
                throw new PaymentException('المعادل المحاسبي محمي بعد التحقق.');
            }
        });

        static::deleting(function () {
            throw new PaymentException('لا تُحذف طلبات الدعم؛ التاريخ المالي يبقى (إلغاء/رفض/انتهاء/استرداد بدل الحذف).');
        });
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'accounting_amount_minor' => 'integer',
            'proof_size' => 'integer',
            'is_anonymous' => 'boolean',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'clarification_requested_at' => 'datetime',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
            'refunded_at' => 'datetime',
            'proof_uploaded_at' => 'datetime',
            'proof_purged_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(SponsorshipProgram::class, 'sponsorship_program_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supporter(): BelongsTo
    {
        return $this->belongsTo(SupporterProfile::class, 'supporter_profile_id');
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(SponsorshipContribution::class, 'sponsorship_contribution_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn($query->getModel()->getTable().'.status', self::OPEN_STATUSES);
    }

    public function isStatus(string ...$statuses): bool
    {
        return in_array($this->status, $statuses, true);
    }

    public function isOpen(): bool
    {
        return $this->isStatus(...self::OPEN_STATUSES);
    }

    public function hasProof(): bool
    {
        return $this->proof_path !== null && $this->proof_purged_at === null;
    }

    /** المبلغ الأصلي منسَّقًا للعرض (لا وحدات صغرى خام في الواجهات). */
    public function amountLabel(): string
    {
        return SponsorshipMoney::format((int) $this->amount_minor, (string) $this->currency);
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_AWAITING => 'بانتظار الدفع',
            self::STATUS_SUBMITTED => 'بانتظار التحقق',
            self::STATUS_VERIFIED => 'تم التحقق',
            self::STATUS_REJECTED => 'مرفوض',
            self::STATUS_CANCELLED => 'ملغى',
            self::STATUS_EXPIRED => 'منتهي',
            self::STATUS_REFUNDED => 'مُسترد',
        ];
    }

    /** @return array<string, string> */
    public static function failureLabels(): array
    {
        return [
            'amount_mismatch' => 'المبلغ المحوَّل لا يطابق الطلب',
            'reference_missing' => 'رمز المرجع غير ظاهر في التحويل',
            'proof_unreadable' => 'الإثبات غير واضح أو غير مقروء',
            'not_received' => 'لم يصلنا التحويل',
            'duplicate' => 'طلب مكرر',
            'suspected_fraud' => 'اشتباه بعملية غير سليمة',
            'other' => 'سبب آخر',
        ];
    }
}
`````

### `app/Policies/SponsorshipPaymentPolicy.php`

`sha256: d3b06a121ad349b15a4486ae7a76f7c676ab478f41ac7e7542eb26b9f13d2b0c`

`````php
<?php

namespace App\Policies;

use App\Models\User;

/**
 * E32 - طلبات الدفع (مالية). العرض في لوحة الإدارة sponsorships.view؛ كل الإجراءات (تحقق/رفض/توضيح/إلغاء/استرداد) sponsorships.confirm عبر SponsorshipPaymentService؛
 * تنزيل الإثبات (بيانات مالية/شخصية) لمن يملك sponsorships.confirm فقط. لا تعديل خام ولا حذف من أي واجهة: التاريخ المالي يبقى.
 * مالك الطلب يصل لطلبه عبر مسارات /support/* المقيَّدة بملكيته في الاستعلام نفسه (404 لغيره)؛ لا يمرّ عبر هذه السياسة.
 */
class SponsorshipPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sponsorships.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('sponsorships.view');
    }

    public function downloadProof(User $user, $model): bool
    {
        return $user->can('sponsorships.confirm');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, $model): bool
    {
        return false;
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
`````

### `app/Services/Payments/ManualPaymentProvider.php`

`sha256: 7caf17f559bf75e9387a02066ccaca6ccc883070742eff229087991f6da2cee7`

`````php
<?php

namespace App\Services\Payments;

use App\Models\SponsorshipPayment;
use App\Services\PlatformSettingsService;
use Illuminate\Http\Request;

/**
 * E32 - المزوّد اليدوي (تحويل بنكي/حوالة): يعمل كاملًا الآن. الدفع يتم خارج المنصة، ثم يُرسل المستخدم الإثبات، ويتحقق مراجع مخوَّل بـSponsorshipPaymentService::verify.
 * لا webhook ولا إعادة توجيه ولا استرداد عبر مزوّد (الاسترداد الفعلي خارجي ثم يُسجَّل بالنظام). التفعيل = شرط النشر (PAYMENTS_MANUAL_ENABLED) ∧ مفتاح الإدارة (payments.manual_enabled).
 */
class ManualPaymentProvider implements PaymentProvider
{
    public const KEY = 'manual';

    public function __construct(protected PlatformSettingsService $settings) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return (string) ($this->settings->get('payments', 'manual_title') ?: 'تحويل بنكي');
    }

    public function isEnabled(): bool
    {
        return (bool) config('payments.providers.manual.enabled', true) && (bool) $this->settings->get('payments', 'manual_enabled', true);
    }

    public function supportsRedirect(): bool
    {
        return false;
    }

    public function supportsWebhook(): bool
    {
        return false;
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    /** @return list<string>|null قائمة إدارة اختيارية (payments.allowed_currencies)؛ null = بلا تضييق إضافي. */
    public function currencies(): ?array
    {
        $raw = trim((string) $this->settings->get('payments', 'allowed_currencies', ''));

        if ($raw === '') {
            return null;
        }

        $codes = array_values(array_unique(array_filter(array_map(static fn (string $c): string => strtoupper(trim($c)), explode(',', $raw)), static fn (string $c): bool => preg_match('/^[A-Z]{3}$/', $c) === 1)));

        return $codes === [] ? null : $codes;
    }

    public function createPayment(SponsorshipPayment $payment): PaymentInit
    {
        return new PaymentInit;
    }

    public function verifyCallback(Request $request): ProviderCallback
    {
        throw new PaymentException('هذه الطريقة لا تستقبل إشعارات تلقائية.');
    }

    public function refundReference(SponsorshipPayment $payment): ?string
    {
        return null;
    }

    public function status(SponsorshipPayment $payment): string
    {
        return (string) $payment->status;
    }
}
`````

### `app/Services/Payments/PaymentAnalytics.php`

`sha256: aedc5b071dcb5f928cbdf711d7d7dfe53dbb302ff63618a079c9e7442a2d3587`

`````php
<?php

namespace App\Services\Payments;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E32 - لوحة العمليات المالية (إدارة). تجميعات محدودة بعدد ثابت من الاستعلامات (لا حلقات) مع كاش قصير (analytics.cache_ttl). كلها قراءة فقط.
 * الأرقام الصافية (إجمالي المؤكَّد/المتوسط) من المساهمات المؤكَّدة بالمعادل المحاسبي؛ حصة المزوّد اليدوي من الطلبات المحقَّقة.
 */
class PaymentAnalytics
{
    /**
     * @return array{pending: int, awaiting_verification: int, verified_today: int, failed: int, refunded: int, confirmed_total_minor: int, confirmed_count: int, average_minor: int, manual_share: int, currency: string}
     */
    public function summary(bool $fresh = false): array
    {
        $compute = fn (): array => $this->compute();
        $ttl = (int) config('payments.analytics.cache_ttl', 120);

        if ($fresh || $ttl <= 0) {
            return $compute();
        }

        try {
            return Cache::remember('payments:analytics:v1', $ttl, $compute);
        } catch (\Throwable) {
            return $compute();
        }
    }

    public function forget(): void
    {
        try {
            Cache::forget('payments:analytics:v1');
        } catch (\Throwable) {
            // الكاش غير متاح: لا شيء يُمسح
        }
    }

    /** @return array<string, mixed> */
    protected function compute(): array
    {
        $counts = SponsorshipPayment::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $since = now()->subDays(max(1, (int) config('payments.analytics.window_days', 30)));
        $failed = SponsorshipPayment::query()->whereIn('status', [SponsorshipPayment::STATUS_REJECTED, SponsorshipPayment::STATUS_CANCELLED, SponsorshipPayment::STATUS_EXPIRED])->where('updated_at', '>=', $since)->count();

        $confirmed = SponsorshipContribution::query()->where('status', SponsorshipContribution::STATUS_CONFIRMED)
            ->selectRaw('COALESCE(SUM(accounting_amount_minor), 0) as total, COUNT(*) as n')->first();
        $total = (int) ($confirmed->total ?? 0);
        $n = (int) ($confirmed->n ?? 0);

        $verified = (int) ($counts[SponsorshipPayment::STATUS_VERIFIED] ?? 0) + (int) ($counts[SponsorshipPayment::STATUS_REFUNDED] ?? 0);
        $manual = $verified === 0 ? 0 : SponsorshipPayment::query()->whereIn('status', [SponsorshipPayment::STATUS_VERIFIED, SponsorshipPayment::STATUS_REFUNDED])->where('provider', ManualPaymentProvider::KEY)->count();

        return [
            'pending' => (int) ($counts[SponsorshipPayment::STATUS_AWAITING] ?? 0),
            'awaiting_verification' => (int) ($counts[SponsorshipPayment::STATUS_SUBMITTED] ?? 0),
            'verified_today' => SponsorshipPayment::query()->where('status', SponsorshipPayment::STATUS_VERIFIED)->where('verified_at', '>=', now()->startOfDay())->count(),
            'failed' => $failed,
            'refunded' => (int) ($counts[SponsorshipPayment::STATUS_REFUNDED] ?? 0),
            'confirmed_total_minor' => $total,
            'confirmed_count' => $n,
            'average_minor' => $n > 0 ? intdiv($total, $n) : 0,
            'manual_share' => $verified > 0 ? (int) intdiv($manual * 100, $verified) : 0,
            'currency' => \App\Services\Sponsorship\SponsorshipMoney::accountingCurrency(),
        ];
    }
}
`````

### `app/Services/Payments/PaymentException.php`

`sha256: 4158aad342e729d779e8663a32562be1b1a305a03a04ed9bf4ca3671cd9f8df7`

`````php
<?php

namespace App\Services\Payments;

use App\Services\Sponsorship\SponsorshipException;

/** E32 - خطأ أعمال دفع مفهوم للمستخدم/المراجع (رسالة عربية آمنة للعرض). يرث SponsorshipException فتلتقطه معالجات الرعاية القائمة. لا يحمل بيانات خاصة. */
class PaymentException extends SponsorshipException {}
`````

### `app/Services/Payments/PaymentInit.php`

`sha256: bd900b6b1dd791543d955f0cc6e82be90c740e8344de090b02521fb56098d457`

`````php
<?php

namespace App\Services\Payments;

/** E32 - نتيجة إنشاء الدفع لدى المزوّد: مرجع المزوّد (إن وُجد) ورابط إعادة توجيه (للمزوّدين الإلكترونيين). اليدوي: كلاهما null. */
final class PaymentInit
{
    public function __construct(public readonly ?string $providerReference = null, public readonly ?string $redirectUrl = null) {}
}
`````

### `app/Services/Payments/PaymentInstructions.php`

`sha256: 61fa4770abea92ba7078098d025ee11f891a3be0ef5c49000c0d337a9f8806ac`

`````php
<?php

namespace App\Services\Payments;

use App\Models\SponsorshipPayment;
use App\Services\PlatformSettingsService;

/**
 * E32 - تعليمات التحويل اليدوي العلنية (من إعدادات المنصة، مجموعة payments). حقول مقصودة للعرض فقط: عنوان/مستفيد/بنك/حساب/آيبان/ملاحظة مرجع/ملاحظة إضافية.
 * لا أسرار هنا ولا يُرفع منها شيء لصفحة عامة: تُعرض لصاحب الطلب فقط (صفحة طلبه). كل القيم نص عادي يُهرَّب عند العرض.
 */
class PaymentInstructions
{
    public function __construct(protected PlatformSettingsService $settings) {}

    /** @return array{title: string, beneficiary: ?string, bank_name: ?string, account: ?string, reference_note: ?string, extra_note: ?string, proof_required: bool} */
    public function manual(): array
    {
        $text = fn (string $key): ?string => filled($v = $this->settings->get('payments', $key)) ? trim((string) $v) : null;

        return [
            'title' => $text('manual_title') ?? 'تحويل بنكي',
            'beneficiary' => $text('manual_beneficiary'),
            'bank_name' => $text('manual_bank_name'),
            'account' => $text('manual_account'),
            'reference_note' => $text('manual_reference_note'),
            'extra_note' => $text('manual_extra_note'),
            'proof_required' => (bool) $this->settings->get('payments', 'proof_required', false),
        ];
    }

    /** @return array<string, mixed>|null null إن لم تكن طريقة يدوية أو لم تُضبط بيانات الحساب بعد. */
    public function forPayment(SponsorshipPayment $payment): ?array
    {
        if ($payment->provider !== ManualPaymentProvider::KEY) {
            return null;
        }

        $manual = $this->manual();

        return $manual + [
            'reference' => (string) $payment->reference,
            'amount' => $payment->amountLabel(),
            'configured' => $manual['beneficiary'] !== null || $manual['account'] !== null,
        ];
    }
}
`````

### `app/Services/Payments/PaymentProofService.php`

`sha256: 4efa54a6de596a4d7d66180ae1c93d70cf686b4a6fd4f1c76d909de6e83ddb78`

`````php
<?php

namespace App\Services\Payments;

use App\Models\SponsorshipPayment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * E32 - إثبات التحويل (ملف **خاص**). لا يُخزَّن على قرص علني أبدًا ولا يُقدَّم برابط مباشر: التنزيل عبر متحكم مخوَّل (PaymentProofController) فقط.
 *  - JPEG/PNG/WEBP/PDF بنوع المحتوى الفعلي (finfo): الصور تتطابق مع getimagesize ويُحدّ عدد البكسلات؛ PDF يبدأ بـ%PDF- ويُقدَّم كمستند خامل (attachment + nosniff) لا يُعرض HTML.
 *    SVG/HTML/التنفيذيات مرفوضة بالبناء (ليست بالقائمة البيضاء). حدّ حجم من الإعداد. اسم الملف يولّده الخادم (ULID + لاحقة من النوع المكتشف)؛ اسم العميل لا يدخل المسار ولا يُحفظ.
 *  - بصمة SHA-256 تُحفظ لاكتشاف إعادة استخدام الإثبات نفسه بين طلبات (مؤشر احتيال للمراجع).
 *  - الحذف لا يطال إلا ملفات داخل مجلد الإثباتات (ownedPath) ولا يقبل مسارًا من العميل.
 */
class PaymentProofService
{
    public function diskName(): string
    {
        return (string) config('payments.proof.disk', 'local');
    }

    public function disk(): Filesystem
    {
        $name = $this->diskName();

        // الإثبات بيانات مالية/شخصية: يُرفض أي قرص علني (الاسم أو إعداد الرؤية) بالبناء.
        if ($name === 'public' || config("filesystems.disks.{$name}.visibility") === 'public') {
            throw new PaymentException('إعداد تخزين الإثبات غير آمن: يجب أن يكون القرص خاصًا.');
        }

        return Storage::disk($name);
    }

    public function directory(): string
    {
        return trim((string) config('payments.proof.directory', 'payment-proofs'), '/');
    }

    /**
     * يتحقق من الملف ويخزّنه خاصًا. @return array{path: string, mime: string, size: int, sha256: string}
     *
     * @throws PaymentException
     */
    public function store(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw new PaymentException('تعذّر رفع الملف، حاول مرة أخرى.');
        }

        $allowed = (array) config('payments.proof.mimes');
        $path = (string) $file->getRealPath();
        $size = (int) $file->getSize();

        if ($path === '' || $size <= 0) {
            throw new PaymentException('الملف فارغ أو غير مقروء.');
        }

        if ($size > ((int) config('payments.proof.max_kb', 5120)) * 1024) {
            throw new PaymentException('حجم الملف أكبر من المسموح ('.(int) config('payments.proof.max_kb', 5120).' ك.ب).');
        }

        $mime = $this->detectMime($path);

        if (! isset($allowed[$mime])) {
            throw new PaymentException('صيغة الإثبات غير مدعومة. المسموح: JPEG أو PNG أو WEBP أو PDF.');
        }

        if ($mime === 'application/pdf') {
            if ((new \SplFileObject($path))->fread(5) !== '%PDF-') {
                throw new PaymentException('ملف PDF غير صالح.');
            }
        } else {
            $info = @getimagesize($path);

            if ($info === false || ($info['mime'] ?? '') !== $mime || ((int) $info[0] * (int) $info[1]) > (int) config('payments.proof.max_pixels', 40_000_000)) {
                throw new PaymentException('الصورة غير صالحة أو كبيرة الأبعاد.');
            }
        }

        $name = Str::lower((string) Str::ulid()).'.'.$allowed[$mime];
        $stored = $this->disk()->putFileAs($this->directory().'/'.now()->format('Y/m'), $file, $name, ['visibility' => 'private']);

        if ($stored === false) {
            throw new PaymentException('تعذّر حفظ الإثبات، حاول لاحقًا.');
        }

        return ['path' => (string) $stored, 'mime' => $mime, 'size' => $size, 'sha256' => (string) hash_file('sha256', $path)];
    }

    public function exists(SponsorshipPayment $payment): bool
    {
        return $payment->hasProof() && $this->ownedPath($payment->proof_path) && $this->disk()->exists((string) $payment->proof_path);
    }

    /** تنزيل خامل: attachment دائمًا (لا عرض مضمَّن) + nosniff + بلا تخزين مؤقت عام. */
    public function download(SponsorshipPayment $payment): StreamedResponse
    {
        abort_unless($this->exists($payment), 404);

        $extension = (string) ((array) config('payments.proof.mimes'))[(string) $payment->proof_mime] ?: 'bin';

        return $this->disk()->download((string) $payment->proof_path, 'proof-'.$payment->reference.'.'.$extension, [
            'Content-Type' => (string) $payment->proof_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function ownedPath(?string $path): bool
    {
        return ! blank($path) && ! str_contains($path, '..') && ! str_contains($path, "\0") && str_starts_with($path, $this->directory().'/');
    }

    public function delete(?string $path): void
    {
        if ($this->ownedPath($path) && $this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }

    protected function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return '';
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) ? $mime : '';
    }
}
`````

### `app/Services/Payments/PaymentProvider.php`

`sha256: 7476852eb00d8174341f4b29efc1f945c4045387fa4584ca47836dbb7af754c5`

`````php
<?php

namespace App\Services\Payments;

use App\Models\SponsorshipPayment;
use Illuminate\Http\Request;

/**
 * E32 - عقد مزوّد الدفع (محايد للمزوّد). المزوّد الوحيد المنفَّذ الآن: ManualPaymentProvider (تحويل يدوي + تحقق مالي).
 * مزوّد إلكتروني مستقبلي (Stripe/PayPal/PayTabs/HyperPay/Tap/MyFatoorah/بوابة بنكية) ينفّذ هذا العقد ويُسجَّل في config/payments.php دون المساس بنموذج SponsorshipContribution
 * (المساهمة تُنشأ دائمًا عبر SponsorshipPaymentService::verify فقط). تفاصيل ما يجب تنفيذه: docs/sponsorship-payments-e32.md («عقد المزوّد الإلكتروني المستقبلي»).
 *
 * قواعد ملزمة لأي تنفيذ:
 *  - لا يُحفظ رقم بطاقة/CVV/أسرار مزوّد/جسم webhook خام في قاعدة البيانات أو السجلات.
 *  - verifyCallback **يتحقق من توقيع المزوّد** (أو يرمي استثناءً) قبل أي أثر؛ لا يعتمد على مصدر الطلب أو عنوانه.
 *  - مرجع المزوّد فريد داخل المزوّد (قيد قاعدة بيانات)، ومعرّف الحدث يجعل المعالجة idempotent.
 */
interface PaymentProvider
{
    /** مفتاح فريد ثابت (حروف صغيرة/أرقام/شرطة سفلية، ≤32). */
    public function key(): string;

    /** تسمية عربية للعرض. */
    public function label(): string;

    /** مفعَّل الآن؟ (إعداد النشر + أي مفتاح إدارة). المزوّد غير المفعَّل يرفضه السجل كأنه غير موجود. */
    public function isEnabled(): bool;

    /** هل يوجّه المستخدم لصفحة دفع خارجية؟ (اليدوي: لا). */
    public function supportsRedirect(): bool;

    /** هل يستقبل إشعارات خادم-إلى-خادم (webhook)؟ (اليدوي: لا). */
    public function supportsWebhook(): bool;

    /** هل يدعم استرداد عبر المزوّد؟ (اليدوي: لا — الاسترداد يُنفَّذ خارجيًا ثم يُسجَّل بالنظام). */
    public function supportsRefund(): bool;

    /**
     * العملات التي يقبلها هذا المزوّد (رموز ISO) أو null = كل العملات المسموحة عامة. تُقاطَع دائمًا مع قوائم الرعاية والدفع.
     *
     * @return list<string>|null
     */
    public function currencies(): ?array;

    /** ينشئ الدفع لدى المزوّد (اليدوي: لا شيء). يُستدعى مرة واحدة بعد إنشاء الطلب. */
    public function createPayment(SponsorshipPayment $payment): PaymentInit;

    /**
     * يتحقق من توقيع واستجابة المزوّد ويُرجع حدثًا موحَّدًا. يرمي PaymentException عند توقيع غير صالح/مزوّد لا يدعم webhook.
     *
     * @throws PaymentException
     */
    public function verifyCallback(Request $request): ProviderCallback;

    /** يطلب استردادًا لدى المزوّد ويُرجع مرجع الاسترداد، أو null إن لم يدعم. @throws PaymentException */
    public function refundReference(SponsorshipPayment $payment): ?string;

    /** حالة الدفع لدى المزوّد (للمطابقة). اليدوي: يعكس حالة الطلب المحلية. */
    public function status(SponsorshipPayment $payment): string;
}
`````

### `app/Services/Payments/PaymentProviderRegistry.php`

`sha256: b05b202572d0262a172312d6dd037fad239b108e1887cdee8c9ff0fddebc9a55`

`````php
<?php

namespace App\Services\Payments;

use Illuminate\Contracts\Container\Container;

/**
 * E32 - سجل المزوّدين (من config/payments.php). مفتاح فريد لكل مزوّد؛ الصنف يُحلّ من حاوية الخدمات (قابل للاستبدال/الحقن بالاختبارات والمستقبل).
 * مزوّد غير معروف أو معطَّل ⇒ PaymentException (يُعامل كأنه غير موجود). لا يوجد مزوّد وهمي في الإنتاج.
 */
class PaymentProviderRegistry
{
    /** @var array<string, PaymentProvider> */
    protected array $resolved = [];

    public function __construct(protected Container $container) {}

    /** @return list<string> مفاتيح المزوّدين المعرَّفين بالإعداد (مفعّلين أو لا). */
    public function configured(): array
    {
        return array_map('strval', array_keys((array) config('payments.providers', [])));
    }

    /** @return list<string> */
    public function enabledKeys(): array
    {
        return array_values(array_filter($this->configured(), fn (string $key) => $this->isEnabled($key)));
    }

    public function isEnabled(string $key): bool
    {
        try {
            return (bool) config('payments.enabled', true) && $this->provider($key)->isEnabled();
        } catch (PaymentException) {
            return false;
        }
    }

    /** @throws PaymentException مزوّد غير معروف أو معطَّل */
    public function get(string $key): PaymentProvider
    {
        $provider = $this->provider($key);

        if (! (bool) config('payments.enabled', true) || ! $provider->isEnabled()) {
            throw new PaymentException('طريقة الدفع غير متاحة حاليًا.');
        }

        return $provider;
    }

    /** مزوّد معرَّف (حتى لو معطَّل) لقراءة الحالة/المطابقة. @throws PaymentException */
    public function provider(string $key): PaymentProvider
    {
        $definition = config("payments.providers.{$key}");

        if (! is_array($definition) || ! is_string($definition['class'] ?? null) || preg_match('/^[a-z0-9_]{1,32}$/', $key) !== 1) {
            throw new PaymentException('طريقة دفع غير معروفة.');
        }

        if (! array_key_exists($key, $this->resolved)) {
            $instance = $this->container->make($definition['class']);

            if (! $instance instanceof PaymentProvider || $instance->key() !== $key) {
                throw new PaymentException('إعداد مزوّد الدفع غير صالح.');
            }

            $this->resolved[$key] = $instance;
        }

        return $this->resolved[$key];
    }

    /** يسمح للاختبارات/المستقبل بحقن مزوّد (يُشترط توافق المفتاح مع الإعداد). */
    public function swap(string $key, PaymentProvider $provider): void
    {
        $this->resolved[$key] = $provider;
    }

    public function forget(): void
    {
        $this->resolved = [];
    }
}
`````

### `app/Services/Payments/PaymentReconciliationService.php`

`sha256: 823d092fc85d9af66e64dce899ba61b0dbba38904bd4b95d389c5ef4530d68b6`

`````php
<?php

namespace App\Services\Payments;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use Illuminate\Support\Facades\DB;

/**
 * E32 - مطابقة مالية **للقراءة فقط**: تكتشف عدم اتساق بين طلبات الدفع والمساهمات ولا تُصلح شيئًا أبدًا (إصلاح التاريخ المالي إجراء إداري صريح ومدقَّق).
 * كل فحص استعلام واحد محدود بسقف؛ المخرجات معرّفات علنية (ULID) ورموز مرجع وأكواد فقط — لا أسماء ولا بريد ولا مسارات إثبات ولا ملاحظات ولا أسرار.
 *
 * الأكواد: verified_without_contribution · contribution_not_confirmed · amount_mismatch · currency_mismatch · refund_state_mismatch · orphan_materialized_contribution · duplicate_provider_reference · duplicate_reference
 */
class PaymentReconciliationService
{
    public const CODES = [
        'verified_without_contribution' => 'طلب محقَّق بلا مساهمة مرتبطة',
        'contribution_not_confirmed' => 'طلب محقَّق ومساهمته غير مؤكَّدة',
        'amount_mismatch' => 'مبلغ الطلب لا يطابق المساهمة',
        'currency_mismatch' => 'عملة الطلب لا تطابق المساهمة',
        'refund_state_mismatch' => 'حالة الاسترداد غير متطابقة بين الطلب والمساهمة',
        'orphan_materialized_contribution' => 'مساهمة مجسَّدة من مرجع طلب لا يرتبط بها',
        'duplicate_provider_reference' => 'مرجع مزوّد مكرر',
        'duplicate_reference' => 'رمز مرجع مكرر',
    ];

    /**
     * @return list<array{code: string, payment: ?string, reference: ?string, contribution: ?string}>
     */
    public function run(): array
    {
        $limit = max(1, (int) config('payments.reconcile.max_findings', 200));
        $findings = [];

        $add = function (string $code, iterable $rows) use (&$findings, $limit): void {
            foreach ($rows as $row) {
                if (count($findings) >= $limit) {
                    return;
                }

                $findings[] = ['code' => $code, 'payment' => $row->payment ?? null, 'reference' => $row->reference ?? null, 'contribution' => $row->contribution ?? null];
            }
        };

        $joined = fn () => DB::table('sponsorship_payments as p')->join('sponsorship_contributions as c', 'c.id', '=', 'p.sponsorship_contribution_id')
            ->select(['p.public_id as payment', 'p.reference as reference', 'c.public_id as contribution'])->orderBy('p.id')->limit($limit);

        $add('verified_without_contribution', DB::table('sponsorship_payments as p')->where('p.status', SponsorshipPayment::STATUS_VERIFIED)->whereNull('p.sponsorship_contribution_id')
            ->select(['p.public_id as payment', 'p.reference as reference'])->orderBy('p.id')->limit($limit)->get());

        $add('contribution_not_confirmed', $joined()->where('p.status', SponsorshipPayment::STATUS_VERIFIED)->where('c.status', '!=', SponsorshipContribution::STATUS_CONFIRMED)->get());

        $add('amount_mismatch', $joined()->whereIn('p.status', [SponsorshipPayment::STATUS_VERIFIED, SponsorshipPayment::STATUS_REFUNDED])
            ->where(fn ($q) => $q->whereColumn('c.original_amount_minor', '!=', 'p.amount_minor')->orWhereColumn('c.accounting_amount_minor', '!=', 'p.accounting_amount_minor'))->get());

        $add('currency_mismatch', $joined()->whereIn('p.status', [SponsorshipPayment::STATUS_VERIFIED, SponsorshipPayment::STATUS_REFUNDED])
            ->where(fn ($q) => $q->whereColumn('c.currency', '!=', 'p.currency')->orWhereColumn('c.accounting_currency', '!=', 'p.accounting_currency'))->get());

        $add('refund_state_mismatch', $joined()->where(fn ($q) => $q
            ->where(fn ($w) => $w->where('p.status', SponsorshipPayment::STATUS_REFUNDED)->where('c.status', '!=', SponsorshipContribution::STATUS_REFUNDED))
            ->orWhere(fn ($w) => $w->where('p.status', SponsorshipPayment::STATUS_VERIFIED)->where('c.status', SponsorshipContribution::STATUS_REFUNDED)))->get());

        // مساهمة تحمل مرجع طلب (provider + external_reference = reference) لكن الطلب لا يشير إليها.
        $add('orphan_materialized_contribution', DB::table('sponsorship_contributions as c')
            ->join('sponsorship_payments as p', function ($join) {
                $join->on('p.provider', '=', 'c.provider')->on('p.reference', '=', 'c.external_reference');
            })
            ->where(fn ($q) => $q->whereNull('p.sponsorship_contribution_id')->orWhereColumn('p.sponsorship_contribution_id', '!=', 'c.id'))
            ->select(['p.public_id as payment', 'p.reference as reference', 'c.public_id as contribution'])->orderBy('c.id')->limit($limit)->get());

        $add('duplicate_provider_reference', DB::table('sponsorship_payments')->whereNotNull('provider_reference')
            ->groupBy('provider', 'provider_reference')->havingRaw('COUNT(*) > 1')
            ->select(['provider_reference as reference'])->limit($limit)->get());

        $add('duplicate_reference', DB::table('sponsorship_payments')->groupBy('reference')->havingRaw('COUNT(*) > 1')->select(['reference'])->limit($limit)->get());

        return $findings;
    }

    /**
     * @param  list<array{code: string}>  $findings
     * @return array<string, int>
     */
    public function summarize(array $findings): array
    {
        $counts = array_fill_keys(array_keys(self::CODES), 0);

        foreach ($findings as $finding) {
            $counts[$finding['code']] = ($counts[$finding['code']] ?? 0) + 1;
        }

        return $counts;
    }
}
`````

### `app/Services/Payments/PaymentReferenceGenerator.php`

`sha256: 64450a249a1b80a96542d8416a94461a98d065f18f67d1f3a46c8c249b581487`

`````php
<?php

namespace App\Services\Payments;

use App\Models\SponsorshipPayment;

/**
 * E32 - مرجع التحويل البشري (SUP-XXXXXXXX). أبجدية بلا أحرف ملتبسة وعشوائية قوية (random_int)؛ التفرّد الفعلي بقيد UNIQUE في قاعدة البيانات
 * (هذا الفحص تحسين لتجنّب التصادم فقط، والخدمة تعيد المحاولة عند انتهاك القيد).
 */
final class PaymentReferenceGenerator
{
    public static function generate(): string
    {
        $alphabet = (string) config('payments.request.reference_alphabet', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
        $length = max(6, min(16, (int) config('payments.request.reference_length', 8)));
        $prefix = strtoupper((string) config('payments.request.reference_prefix', 'SUP'));
        $max = strlen($alphabet) - 1;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = '';

            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }

            $reference = $prefix.'-'.$code;

            if (! SponsorshipPayment::query()->where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        return $prefix.'-'.strtoupper(bin2hex(random_bytes(max(4, intdiv($length, 2)))));
    }
}
`````

### `app/Services/Payments/PaymentRetentionService.php`

`sha256: 44b027b1dc3d15b7c08bad0308efaca96fcb9a2f5cc70c740b3076808f5d9691`

`````php
<?php

namespace App\Services\Payments;

use App\Models\SponsorshipPayment;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E32 - الاحتفاظ بإثباتات التحويل (بيانات مالية/شخصية). **لا يُحذف سجل الطلب أبدًا**؛ يُقلَّم ملف الإثبات فقط بعد مدة من الحالة النهائية (PAYMENTS_PROOF_RETENTION_DAYS)،
 * وتُحفظ بصمته ونوعه وحجمه لأغراض التدقيق. لا يُقلَّم أبدًا إثبات طلب مفتوح (بانتظار الدفع/قيد التحقق)، ولا إثبات مرفوض أو مسترد قبل انقضاء نافذة النزاع (PAYMENTS_DISPUTE_WINDOW_DAYS).
 * --dry-run يعدّ فقط بلا أي حذف.
 */
class PaymentRetentionService
{
    public function __construct(protected PaymentProofService $proofs, protected OperationalAuditService $audit) {}

    /** @return \Illuminate\Database\Eloquent\Builder<SponsorshipPayment> */
    public function candidates()
    {
        $retention = max(1, (int) config('payments.retention.proof_days_after_final', 365));
        $dispute = max($retention, max(0, (int) config('payments.retention.dispute_window_days', 90)));
        $cut = now()->subDays($retention);
        $disputeCut = now()->subDays($dispute);

        return SponsorshipPayment::query()->whereNotNull('proof_path')->whereNull('proof_purged_at')
            ->where(function ($q) use ($cut, $disputeCut) {
                $q->where(fn ($w) => $w->where('status', SponsorshipPayment::STATUS_VERIFIED)->where('verified_at', '<', $cut))
                    ->orWhere(fn ($w) => $w->where('status', SponsorshipPayment::STATUS_REFUNDED)->where('refunded_at', '<', $disputeCut))
                    ->orWhere(fn ($w) => $w->where('status', SponsorshipPayment::STATUS_REJECTED)->where('rejected_at', '<', $disputeCut))
                    ->orWhere(fn ($w) => $w->where('status', SponsorshipPayment::STATUS_CANCELLED)->where('cancelled_at', '<', $cut))
                    ->orWhere(fn ($w) => $w->where('status', SponsorshipPayment::STATUS_EXPIRED)->where('expired_at', '<', $cut));
            });
    }

    public function count(): int
    {
        return $this->candidates()->count();
    }

    /** @return int عدد الإثباتات المُقلَّمة. */
    public function prune(?int $limit = null): int
    {
        $limit ??= max(1, (int) config('payments.retention.prune_batch', 200));
        $count = 0;

        foreach ($this->candidates()->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $path = null;

            $done = DB::transaction(function () use ($id, &$path): bool {
                $locked = SponsorshipPayment::query()->lockForUpdate()->find($id);

                if ($locked === null || $locked->proof_path === null || $locked->proof_purged_at !== null || $locked->isOpen()) {
                    return false;
                }

                $path = $locked->proof_path;
                $locked->forceFill(['proof_path' => null, 'proof_purged_at' => now()])->save();
                $this->audit->log('payment_proof_pruned', $locked, ['public_id' => $locked->public_id, 'status' => $locked->status]);

                return true;
            });

            if ($done) {
                $this->proofs->delete($path);
                $count++;
            }
        }

        return $count;
    }
}
`````

### `app/Services/Payments/PaymentRules.php`

`sha256: e43494c8f947fb2b1895535c9ad835480dffd4f2ad7b906b5b500331e3daf515`

`````php
<?php

namespace App\Services\Payments;

use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipMoney;

/**
 * E32 - قواعد الدفع الخادمية الموحَّدة (لا تُؤخذ من المتصفح أبدًا): قائمة العملات المسموحة، الحدّان الأدنى/الأعلى، تحليل المبلغ.
 * المبلغ يُحلَّل بـSponsorshipMoney (نص ⇒ وحدة صغرى صحيحة بلا floats) ثم يُقيَّد بحدود الدفع. العملة من قائمة بيضاء (تقاطع الرعاية ∩ الدفع ∩ المزوّد).
 */
final class PaymentRules
{
    /** @return list<string> */
    public static function currencies(?PaymentProvider $provider = null): array
    {
        $codes = SponsorshipMoney::currencies();
        $configured = array_map('strval', (array) config('payments.allowed_currencies', []));

        if ($configured !== []) {
            $codes = array_values(array_intersect($codes, $configured));
        }

        $providerCodes = $provider?->currencies();

        if ($providerCodes !== null) {
            $codes = array_values(array_intersect($codes, $providerCodes));
        }

        return array_values($codes);
    }

    public static function isCurrencyAllowed(string $currency, ?PaymentProvider $provider = null): bool
    {
        return in_array($currency, self::currencies($provider), true);
    }

    public static function minMinor(): int
    {
        return max(1, (int) config('payments.checkout.min_amount_minor', 100));
    }

    public static function maxMinor(): int
    {
        $global = (int) config('sponsorship.max_amount_minor', 1_000_000_000);
        $configured = config('payments.checkout.max_amount_minor');

        return $configured === null ? $global : max(1, min($global, (int) $configured));
    }

    /**
     * يحلّل مبلغ الدفع ويتحقق من العملة والحدود. @throws PaymentException
     */
    public static function parseAmount(string|int $amount, string $currency, ?PaymentProvider $provider = null): int
    {
        $currency = strtoupper(trim($currency));

        if (! self::isCurrencyAllowed($currency, $provider)) {
            throw new PaymentException('عملة غير مسموحة للدعم.');
        }

        try {
            $minor = SponsorshipMoney::parse($amount, $currency);
        } catch (SponsorshipException $e) {
            throw new PaymentException($e->getMessage());
        }

        if ($minor < self::minMinor()) {
            throw new PaymentException('المبلغ أقل من الحدّ الأدنى للدعم ('.SponsorshipMoney::format(self::minMinor(), $currency).').');
        }

        if ($minor > self::maxMinor()) {
            throw new PaymentException('المبلغ يتجاوز الحدّ الأقصى للدعم ('.SponsorshipMoney::format(self::maxMinor(), $currency).').');
        }

        if (! (bool) config('payments.checkout.allow_custom_amount', true) && ! in_array($minor, self::suggestedMinor($currency), true)) {
            throw new PaymentException('اختر أحد المبالغ المقترحة.');
        }

        return $minor;
    }

    /** @return list<int> المبالغ المقترحة بالوحدة الصغرى لعملة معيّنة (للراحة فقط؛ الخادم يتحقق دائمًا). */
    public static function suggestedMinor(string $currency): array
    {
        $factor = 10 ** SponsorshipMoney::exponent($currency);
        $out = [];

        foreach ((array) config('payments.checkout.suggested_amounts', []) as $major) {
            $minor = (int) $major * $factor;

            if ($minor >= self::minMinor() && $minor <= self::maxMinor()) {
                $out[] = $minor;
            }
        }

        return $out;
    }

    /** @return list<array{minor: int, label: string, input: string}> للعرض: input هو المبلغ كما يُكتب في الحقل (بلا رمز). */
    public static function suggestions(string $currency): array
    {
        $factor = 10 ** SponsorshipMoney::exponent($currency);

        return array_map(
            static fn (int $minor): array => ['minor' => $minor, 'label' => SponsorshipMoney::format($minor, $currency), 'input' => (string) intdiv($minor, $factor)],
            self::suggestedMinor($currency),
        );
    }
}
`````

### `app/Services/Payments/PaymentSettingsGuard.php`

`sha256: 49008335cf42f25f0e1115f8843a2e1835e1d355bdeb5c87470f96f3692c28b6`

`````php
<?php

namespace App\Services\Payments;

/**
 * E32 - حارس إعدادات الدفع اليدوي: هذه الحقول **علنية للمحوِّل** فقط (مستفيد/بنك/آيبان/ملاحظات). يرفض بالبناء:
 *  - أي ذكر لبيانات اعتماد أو أسرار (كلمة مرور بنكية/PIN/CVV/مفتاح API/مفتاح خاص/رمز دخول).
 *  - أرقام بطاقات دفع صالحة (16/15 رقمًا ببادئة شبكة معروفة + Luhn) — لا حاجة لها ولا يجوز عرضها.
 * الآيبان/رقم الحساب العاديان (أحرف+أرقام أو أطوال مختلفة) مسموحان. الفحص خادمي في الحفظ، ولا يُسجَّل المحتوى المرفوض.
 */
final class PaymentSettingsGuard
{
    private const SECRET_PATTERN = '/(password|passcode|passwd|pin\s*code|\bpin\b|\bcvv\b|\bcvc\b|api[\s_-]?key|secret|private[\s_-]?key|BEGIN [A-Z ]*PRIVATE KEY|token|كلمة\s*(المرور|السر)|رمز\s*(الدخول|الأمان)|الرقم\s*السري|مفتاح\s*(خاص|API))/iu';

    /**
     * @param  array<string, mixed>  $values
     * @return list<string> مفاتيح الحقول المخالفة (فارغ = سليم)
     */
    public static function violations(array $values): array
    {
        $bad = [];

        foreach ($values as $key => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            if (preg_match(self::SECRET_PATTERN, $value) === 1 || self::containsCardNumber($value)) {
                $bad[] = (string) $key;
            }
        }

        return $bad;
    }

    public static function containsCardNumber(string $text): bool
    {
        if (preg_match_all('/(?<![\w])(?:\d[ -]?){15,16}(?![\w])/u', $text, $matches) < 1) {
            return false;
        }

        foreach ($matches[0] as $candidate) {
            $digits = preg_replace('/\D/', '', $candidate) ?? '';

            if (! in_array(strlen($digits), [15, 16], true) || ! self::hasCardPrefix($digits)) {
                continue;
            }

            if (self::luhn($digits)) {
                return true;
            }
        }

        return false;
    }

    private static function hasCardPrefix(string $digits): bool
    {
        return preg_match('/^(4\d{15}|5[1-5]\d{14}|2(?:2[2-9]\d|[3-6]\d{2}|7[01]\d|720)\d{12}|3[47]\d{13}|6(?:011|5\d{2})\d{12})$/', $digits) === 1;
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];

            if ($alt) {
                $n *= 2;

                if ($n > 9) {
                    $n -= 9;
                }
            }

            $sum += $n;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }
}
`````

### `app/Services/Payments/ProviderCallback.php`

`sha256: d5db7ef809d7e84cde5fcc40424a7cdad79fc9c2fdd441e5682eec292a77d59b`

`````php
<?php

namespace App\Services\Payments;

/**
 * E32 - حدث موحَّد من مزوّد بعد التحقق من توقيعه (لا جسم خام هنا أبدًا).
 * state: paid | failed | refunded. eventId لمعالجة idempotent (تكرار الحدث لا يُكرّر الأثر).
 */
final class ProviderCallback
{
    public const STATE_PAID = 'paid';

    public const STATE_FAILED = 'failed';

    public const STATE_REFUNDED = 'refunded';

    public function __construct(
        public readonly string $eventId,
        public readonly string $providerReference,
        public readonly string $state,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
    ) {}
}
`````

### `app/Services/Payments/SponsorshipPaymentService.php`

`sha256: dca479e330d7975aba81859e7e908f63c7a87116151e7f55fadf27532f08d616`

`````php
<?php

namespace App\Services\Payments;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Rules\NoMarkup;
use App\Services\OperationalAuditService;
use App\Services\PlatformSettingsService;
use App\Services\Sponsorship\ContributionService;
use App\Services\Sponsorship\SponsorshipCache;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipNotifier;
use App\Services\Sponsorship\SupporterService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * E32 - دورة حياة طلب الدعم/الدفع. **نقطة الكتابة المالية الوحيدة** لطلبات الدعم: لا متحكم ولا إجراء Filament يحدّث الطلب ثم ينشئ المساهمة بنفسه.
 *
 * التحقق الذرّي (verify): معاملة واحدة تقفل الطلب (lockForUpdate) ⇒ تتحقق من حالته ⇒ تقفل المستخدم وتحلّ/تنشئ ملف الداعم ⇒ تنشئ المساهمة معلّقة ثم تؤكّدها بـ**منطق E30 نفسه**
 * (ContributionService::confirmLocked: المعادل المحاسبي/البرنامج/الإيقاف/تدقيق التطبيع) ⇒ تربط الطلب بالمساهمة وتسجّل التدقيق ⇒ commit. الإشعارات والكاش بعد الـcommit وفشلها لا يرجع المال.
 * أي استثناء بالمنتصف ⇒ rollback كامل (لا مساهمة ولا ملف داعم ولا تغيير حالة).
 *
 * الحماية من التكرار (قاعدة البيانات هي الحدّ النهائي): إعادة Verify ⇒ لا أثر ثانٍ (فحص الحالة تحت القفل)؛ واليقين البنيوي: UNIQUE(payment.sponsorship_contribution_id)،
 * UNIQUE(contributions.provider, external_reference = مرجع الطلب)، UNIQUE(provider, provider_reference)، UNIQUE(user_id, request_key).
 *
 * لا استرداد آلي لمكافآت اللاعبين (لقطات تاريخية)؛ الاسترداد يعدّل صافي الدعم/المستوى/الصدارة/التمويل فقط عبر منطق E30. ترتيب الأقفال: مساهمة ثم طلب (في الاسترداد) بما يطابق مسار E30 المباشر.
 * لا TransactionPolicy هنا عمدًا (تثبيت E31 P-2: أربع نقاط دخول فقط).
 */
class SponsorshipPaymentService
{
    public const PERMISSION = 'sponsorships.confirm';

    public function __construct(
        protected OperationalAuditService $audit,
        protected PaymentProviderRegistry $providers,
        protected ContributionService $contributions,
        protected SupporterService $supporters,
        protected SponsorshipNotifier $notifier,
        protected PaymentProofService $proofs,
        protected PlatformSettingsService $settings,
    ) {}

    // ------------------------------------------------------------------ إنشاء الطلب (المستخدم)

    /**
     * @throws PaymentException
     */
    public function createRequest(User $user, SponsorshipProgram $program, string|int $amount, string $currency, bool $anonymous, ?string $displayName = null, ?string $providerKey = null, ?string $requestKey = null): SponsorshipPayment
    {
        if (! (bool) config('sponsorship.enabled', true) || ! (bool) config('payments.enabled', true)) {
            throw new PaymentException('الدعم غير متاح حاليًا.');
        }

        if ($user->is_frozen) {
            throw new PaymentException('حسابك مجمّد؛ لا يمكن إنشاء طلب دعم.');
        }

        if (! $user->hasVerifiedEmail()) {
            throw new PaymentException('وثّق بريدك الإلكتروني أولًا لإنشاء طلب دعم.');
        }

        $provider = $this->providers->get($providerKey ?: (string) config('payments.default_provider', 'manual'));
        $currency = strtoupper(trim($currency));
        $minor = PaymentRules::parseAmount($amount, $currency, $provider);
        $requestKey = $this->cleanRequestKey($requestKey);
        $name = $anonymous ? null : $this->cleanOptionalText($displayName ?? $user->name, (int) config('payments.checkout.display_name_max', 80), 'الاسم');

        if ($requestKey !== null && ($existing = $this->findByRequestKey($user, $requestKey)) !== null) {
            return $existing;
        }

        if (! $program->isActiveNow()) {
            throw new PaymentException('هذا البرنامج غير متاح للدعم حاليًا.');
        }

        $profile = SupporterProfile::query()->where('user_id', $user->id)->first(['id', 'status']);

        if ($profile !== null && $profile->status === SupporterProfile::STATUS_SUSPENDED) {
            throw new PaymentException('ملف الداعم الخاص بك موقوف؛ تواصل مع الإدارة.');
        }

        $open = SponsorshipPayment::query()->where('user_id', $user->id)->open()->count();

        if ($open >= max(1, (int) config('payments.checkout.max_open_requests_per_user', 5))) {
            throw new PaymentException('لديك طلبات دعم مفتوحة كثيرة؛ أكمل أو ألغِ بعضها أولًا.');
        }

        $payment = null;

        for ($attempt = 0; $attempt < 5 && $payment === null; $attempt++) {
            try {
                $payment = DB::transaction(function () use ($user, $program, $provider, $minor, $currency, $anonymous, $name, $requestKey, $profile) {
                    $payment = new SponsorshipPayment;
                    $payment->forceFill([
                        'reference' => PaymentReferenceGenerator::generate(),
                        'sponsorship_program_id' => $program->id,
                        'user_id' => $user->id,
                        'supporter_profile_id' => $profile?->id,
                        'provider' => $provider->key(),
                        'request_key' => $requestKey,
                        'amount_minor' => $minor,
                        'currency' => $currency,
                        'status' => SponsorshipPayment::STATUS_AWAITING,
                        'is_anonymous' => $anonymous,
                        'display_name' => $name,
                        'expires_at' => now()->addHours(max(1, (int) config('payments.request.expiry_hours', 48))),
                    ])->save();

                    $this->audit->log('payment_request_created', $payment, [
                        'public_id' => $payment->public_id, 'reference' => $payment->reference, 'program' => $program->slug,
                        'amount_minor' => $minor, 'currency' => $currency, 'provider' => $provider->key(), 'anonymous' => $anonymous,
                    ], $user);

                    return $payment;
                });
            } catch (UniqueConstraintViolationException $e) {
                // نقر مزدوج بنفس المفتاح ⇒ الطلب نفسه؛ وإلا تصادم مرجع نادر ⇒ نعيد بمرجع جديد.
                if ($requestKey !== null && ($existing = $this->findByRequestKey($user, $requestKey)) !== null) {
                    return $existing;
                }
            }
        }

        if ($payment === null) {
            throw new PaymentException('تعذّر إنشاء الطلب الآن، أعد المحاولة.');
        }

        try {
            $init = $provider->createPayment($payment);

            if ($init->providerReference !== null) {
                $payment->forceFill(['provider_reference' => $init->providerReference])->save();
            }
        } catch (\Throwable $e) {
            $this->cancelSystem($payment, 'provider_error');

            throw $e instanceof PaymentException ? $e : new PaymentException('تعذّر بدء الدفع لدى المزوّد.');
        }

        $this->notifier->paymentReceived($payment);

        return $payment->refresh();
    }

    // ------------------------------------------------------------------ خطوات المالك (إثبات/تحويل/إلغاء)

    /**
     * «حوّلت المبلغ»: awaiting_payment → submitted مع ملاحظة المحوِّل وإثبات اختياري (إلزامي إن فرضته الإعدادات). نقر مزدوج ⇒ لا أثر ثانٍ.
     *
     * @throws PaymentException
     */
    public function markTransferred(User $user, SponsorshipPayment $payment, ?string $payerNote = null, ?UploadedFile $proof = null): SponsorshipPayment
    {
        $this->assertOwner($user, $payment);
        $note = $this->cleanOptionalText($payerNote, (int) config('payments.checkout.note_max', 255), 'الملاحظة');
        $stored = $proof !== null ? $this->proofs->store($proof) : null;
        $oldPath = null;

        try {
            $updated = DB::transaction(function () use ($user, $payment, $note, $stored, &$oldPath) {
                $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);
                $this->assertOwner($user, $locked);

                if ($locked->status === SponsorshipPayment::STATUS_SUBMITTED) {
                    if ($stored !== null) {
                        $oldPath = $this->applyProof($locked, $stored);
                        $this->audit->log('payment_proof_replaced', $locked, ['public_id' => $locked->public_id, 'mime' => $stored['mime'], 'size' => $stored['size']], $user);
                    }

                    return $locked;
                }

                if ($locked->status !== SponsorshipPayment::STATUS_AWAITING) {
                    throw new PaymentException('لا يمكن إرسال هذا الطلب في حالته الحالية.');
                }

                if ($locked->expires_at !== null && $locked->expires_at->isPast()) {
                    throw new PaymentException('انتهت صلاحية هذا الطلب؛ أنشئ طلب دعم جديدًا.');
                }

                if ($stored === null && ! $locked->hasProof() && (bool) $this->settings->get('payments', 'proof_required', false)) {
                    throw new PaymentException('أرفق إثبات التحويل لإرسال الطلب.');
                }

                if ($stored !== null) {
                    $oldPath = $this->applyProof($locked, $stored);
                }

                $locked->forceFill([
                    'status' => SponsorshipPayment::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'expires_at' => null,
                    'payer_note' => $note ?? $locked->payer_note,
                ])->save();

                $this->audit->log('payment_proof_submitted', $locked, [
                    'public_id' => $locked->public_id, 'reference' => $locked->reference, 'has_proof' => $locked->hasProof(),
                    'mime' => $locked->proof_mime, 'size' => $locked->proof_size,
                ], $user);

                return $locked;
            });
        } catch (\Throwable $e) {
            if ($stored !== null) {
                $this->proofs->delete($stored['path']);
            }

            throw $e;
        }

        $this->proofs->delete($oldPath);

        return $updated;
    }

    /**
     * رفع/استبدال الإثبات (المالك) ما دام الطلب مفتوحًا. بعد التحقق/الرفض/الإلغاء/الانتهاء: ثابت. @throws PaymentException
     */
    public function attachProof(User $user, SponsorshipPayment $payment, UploadedFile $proof): SponsorshipPayment
    {
        $this->assertOwner($user, $payment);
        $stored = $this->proofs->store($proof);
        $oldPath = null;

        try {
            $updated = DB::transaction(function () use ($user, $payment, $stored, &$oldPath) {
                $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);
                $this->assertOwner($user, $locked);

                if (! $locked->isOpen()) {
                    throw new PaymentException('الإثبات ثابت بعد انتهاء معالجة الطلب.');
                }

                $replacing = $locked->hasProof();
                $oldPath = $this->applyProof($locked, $stored);
                $this->audit->log($replacing ? 'payment_proof_replaced' : 'payment_proof_submitted', $locked, [
                    'public_id' => $locked->public_id, 'mime' => $stored['mime'], 'size' => $stored['size'], 'status' => $locked->status,
                ], $user);

                return $locked;
            });
        } catch (\Throwable $e) {
            $this->proofs->delete($stored['path']);

            throw $e;
        }

        $this->proofs->delete($oldPath);

        return $updated;
    }

    /** المالك يلغي طلبه **قبل** الإرسال فقط (بعد الإرسال يلغيه المراجع). @throws PaymentException */
    public function cancelByOwner(User $user, SponsorshipPayment $payment): SponsorshipPayment
    {
        $this->assertOwner($user, $payment);

        return DB::transaction(function () use ($user, $payment) {
            $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $this->assertOwner($user, $locked);

            if ($locked->status === SponsorshipPayment::STATUS_CANCELLED) {
                return $locked;
            }

            if ($locked->status !== SponsorshipPayment::STATUS_AWAITING) {
                throw new PaymentException('يُلغى الطلب قبل إرسال التحويل فقط؛ للتعديل بعدها تواصل مع الإدارة.');
            }

            $locked->forceFill(['status' => SponsorshipPayment::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $user->id])->save();
            $this->audit->log('payment_cancelled', $locked, ['public_id' => $locked->public_id, 'by' => 'owner'], $user);

            return $locked;
        });
    }

    // ------------------------------------------------------------------ المراجعة المالية (sponsorships.confirm)

    /**
     * يتحقق من الدفع ويجسّد مساهمة مؤكَّدة ذريًا. إعادة النداء على طلب محقَّق ⇒ يُعاد كما هو (لا مساهمة ثانية).
     *
     * @throws SponsorshipException
     */
    public function verify(User $actor, SponsorshipPayment $payment, string|int|null $accountingAmount = null): SponsorshipPayment
    {
        abort_unless($actor->can(self::PERMISSION), 403);

        return $this->verifyCore($actor, $payment, $accountingAmount);
    }

    /** @throws SponsorshipException */
    public function reject(User $actor, SponsorshipPayment $payment, string $failureCode, ?string $message = null, ?string $reviewNote = null): SponsorshipPayment
    {
        abort_unless($actor->can(self::PERMISSION), 403);

        return $this->rejectCore($actor, $payment, $failureCode, $message, $reviewNote);
    }

    /** يطلب توضيحًا/إثباتًا إضافيًا: submitted → awaiting_payment برسالة مرئية لصاحب الطلب ومهلة جديدة. @throws SponsorshipException */
    public function requestClarification(User $actor, SponsorshipPayment $payment, string $message): SponsorshipPayment
    {
        abort_unless($actor->can(self::PERMISSION), 403);
        $message = $this->contributions->requireReason($message);

        $updated = DB::transaction(function () use ($actor, $payment, $message) {
            $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status !== SponsorshipPayment::STATUS_SUBMITTED) {
                throw new PaymentException('يُطلب التوضيح للطلبات المرسَلة فقط.');
            }

            $locked->forceFill([
                'status' => SponsorshipPayment::STATUS_AWAITING,
                'clarification_requested_at' => now(),
                'user_message' => $message,
                'expires_at' => now()->addHours(max(1, (int) config('payments.request.expiry_hours', 48))),
            ])->save();

            $this->audit->log('payment_clarification_requested', $locked, ['public_id' => $locked->public_id, 'reference' => $locked->reference], $actor);

            return $locked;
        });

        $this->notifier->paymentActionRequired($updated);

        return $updated;
    }

    /** إلغاء إداري لطلب مفتوح (مثل: تكرار/خطأ). السبب مرئي لصاحب الطلب. @throws SponsorshipException */
    public function cancel(User $actor, SponsorshipPayment $payment, string $reason): SponsorshipPayment
    {
        abort_unless($actor->can(self::PERMISSION), 403);
        $reason = $this->contributions->requireReason($reason);

        $updated = DB::transaction(function () use ($actor, $payment, $reason) {
            $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status === SponsorshipPayment::STATUS_CANCELLED) {
                return $locked;
            }

            if (! $locked->isOpen()) {
                throw new PaymentException('لا يُلغى إلا الطلب المفتوح؛ المحقَّق يُسترد.');
            }

            $locked->forceFill([
                'status' => SponsorshipPayment::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'user_message' => $reason,
            ])->save();
            $this->audit->log('payment_cancelled', $locked, ['public_id' => $locked->public_id, 'by' => 'staff', 'reason' => $reason], $actor);

            return $locked;
        });

        $this->notifier->paymentRejected($updated);

        return $updated;
    }

    /**
     * يسجّل استرداد دعم محقَّق (التحويل المعاكس يتم خارجيًا). المساهمة تُسترد بسلطة E30 (refundLocked) داخل المعاملة نفسها؛ لا سحب لمكافآت لاعبين سابقة.
     * إعادة الاسترداد ⇒ آمنة (لا أثر ثانٍ). @throws SponsorshipException
     */
    public function refund(User $actor, SponsorshipPayment $payment, string $reason): SponsorshipPayment
    {
        abort_unless($actor->can(self::PERMISSION), 403);

        return $this->refundCore($actor, $payment, $this->contributions->requireReason($reason));
    }

    // ------------------------------------------------------------------ مزوّد خارجي مستقبلي (webhook موقَّع)

    /**
     * يطبّق حدثًا موحَّدًا **بعد** تحقق توقيع المزوّد (يتم في المزوّد/المتحكم). idempotent بطبيعة الحالة (تكرار الحدث لا يكرّر الأثر).
     * لا يُحقَّق آليًا إلا إن طابق المبلغ والعملة الطلب وكانت عملة الطلب هي عملة المحاسبة (لا FX حيّ)؛ غير ذلك يُترك للمراجعة مع تدقيق.
     *
     * @return string applied|duplicate|ignored|needs_review
     */
    public function applyProviderCallback(string $providerKey, ProviderCallback $callback): string
    {
        $payment = SponsorshipPayment::query()->where('provider', $providerKey)->where('provider_reference', $callback->providerReference)->first();

        if ($payment === null) {
            return 'ignored';
        }

        if ($callback->state === ProviderCallback::STATE_PAID) {
            if ($payment->status === SponsorshipPayment::STATUS_VERIFIED || $payment->status === SponsorshipPayment::STATUS_REFUNDED) {
                return 'duplicate';
            }

            $matches = ($callback->amountMinor === null || $callback->amountMinor === (int) $payment->amount_minor) && ($callback->currency === null || strtoupper($callback->currency) === $payment->currency);

            if (! $matches || $payment->currency !== \App\Services\Sponsorship\SponsorshipMoney::accountingCurrency() || ! $payment->isOpen()) {
                $this->audit->log('payment_callback_needs_review', $payment, ['public_id' => $payment->public_id, 'event' => $callback->eventId, 'amount_matches' => $matches]);

                return 'needs_review';
            }

            $this->verifyCore(null, $payment, null);

            return 'applied';
        }

        if ($callback->state === ProviderCallback::STATE_FAILED) {
            if (! $payment->isOpen()) {
                return 'duplicate';
            }

            $this->rejectCore(null, $payment, 'not_received', null, null);

            return 'applied';
        }

        if ($callback->state === ProviderCallback::STATE_REFUNDED) {
            if ($payment->status === SponsorshipPayment::STATUS_REFUNDED) {
                return 'duplicate';
            }

            $this->refundCore(null, $payment, 'refund reported by provider');

            return 'applied';
        }

        return 'ignored';
    }

    // ------------------------------------------------------------------ انتهاء الصلاحية المجدول

    /**
     * يُنهي الطلبات «بانتظار الدفع» المتأخرة فقط (المرسَل/المحقَّق/المسترد لا ينتهي آليًا). حالة فقط: لا حذف تاريخ. idempotent. @return int عدد المنتهي.
     */
    public function expireStale(?int $limit = null): int
    {
        $limit ??= max(1, (int) config('payments.request.expire_batch', 200));
        $ids = SponsorshipPayment::query()->where('status', SponsorshipPayment::STATUS_AWAITING)->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->orderBy('id')->limit($limit)->pluck('id');
        $count = 0;

        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id): int {
                $locked = SponsorshipPayment::query()->lockForUpdate()->find($id);

                if ($locked === null || $locked->status !== SponsorshipPayment::STATUS_AWAITING || $locked->expires_at === null || $locked->expires_at->isFuture()) {
                    return 0;
                }

                $locked->forceFill(['status' => SponsorshipPayment::STATUS_EXPIRED, 'expired_at' => now()])->save();
                $this->audit->log('payment_expired', $locked, ['public_id' => $locked->public_id, 'reference' => $locked->reference, 'program' => $locked->sponsorship_program_id]);

                return 1;
            });
        }

        return $count;
    }

    // ------------------------------------------------------------------ تكامل E30 (استرداد مباشر لمساهمة مرتبطة بطلب)

    /**
     * يُستدعى من ContributionService::refund داخل معاملته (الصف مقفول): إن كانت المساهمة مجسَّدة من طلب محقَّق ⇒ يصير الطلب «مُستردًا» بالمعاملة نفسها فلا تتعارض الحالتان.
     */
    public function syncRefundFromContribution(SponsorshipContribution $contribution, User $actor, string $reason): void
    {
        $payment = SponsorshipPayment::query()->where('sponsorship_contribution_id', $contribution->id)->lockForUpdate()->first();

        if ($payment === null || $payment->status !== SponsorshipPayment::STATUS_VERIFIED) {
            return;
        }

        $payment->forceFill(['status' => SponsorshipPayment::STATUS_REFUNDED, 'refunded_at' => now(), 'refunded_by' => $actor->id])->save();
        $this->audit->log('payment_refunded', $payment, ['public_id' => $payment->public_id, 'reference' => $payment->reference, 'source' => 'contribution_refund', 'reason' => $reason], $actor);
    }

    /** بعد الـcommit في مسار الاسترداد المباشر: إشعار صاحب الطلب (مفتاح دلالي ⇒ لا تكرار مع مسار الاسترداد عبر الخدمة). */
    public function notifyLinkedRefund(SponsorshipContribution $contribution): void
    {
        try {
            $payment = SponsorshipPayment::query()->where('sponsorship_contribution_id', $contribution->id)->where('status', SponsorshipPayment::STATUS_REFUNDED)->first();

            if ($payment !== null) {
                $this->notifier->paymentRefunded($payment);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ================================================================== الأنوية (قابلة لاستدعاء بلا مستخدم من مسار webhook الموقَّع)

    /** @throws SponsorshipException */
    protected function verifyCore(?User $actor, SponsorshipPayment $payment, string|int|null $accountingAmount): SponsorshipPayment
    {
        $before = null;
        $supporterId = 0;
        $contribution = null;
        $materialized = false;

        $verified = DB::transaction(function () use ($actor, $payment, $accountingAmount, &$before, &$supporterId, &$contribution, &$materialized) {
            $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);

            // إعادة Verify (زر مكرر/إعادة محاولة مهمة): لا أثر ثانٍ.
            if ($locked->status === SponsorshipPayment::STATUS_VERIFIED && $locked->sponsorship_contribution_id !== null) {
                return $locked;
            }

            if (! $locked->isOpen()) {
                throw new PaymentException('لا يمكن التحقق من طلب في حالة «'.$locked->statusLabel().'».');
            }

            $owner = User::query()->findOrFail($locked->user_id);
            $program = SponsorshipProgram::query()->findOrFail($locked->sponsorship_program_id);
            $profile = $this->supporters->ensureForUser($owner, $locked->display_name, ! $locked->is_anonymous, $actor);

            $contribution = new SponsorshipContribution;
            $contribution->forceFill([
                'supporter_profile_id' => $profile->id,
                'sponsorship_program_id' => $program->id,
                'original_amount_minor' => (int) $locked->amount_minor,
                'currency' => $locked->currency,
                'status' => SponsorshipContribution::STATUS_PENDING,
                'is_anonymous' => (bool) $locked->is_anonymous,
                'reference' => $locked->reference,
                'provider' => $locked->provider,
                'external_reference' => $locked->reference,
                'created_by' => $actor?->id,
            ])->save();

            // منطق التأكيد والمعادل المحاسبي كما في E30 تمامًا (أي رفض هنا يتراجع بكل المعاملة).
            $contribution = $this->contributions->confirmLocked($actor, $contribution, $accountingAmount, $before, $supporterId);

            $locked->forceFill([
                'status' => SponsorshipPayment::STATUS_VERIFIED,
                'verified_at' => now(),
                'verified_by' => $actor?->id,
                'supporter_profile_id' => $profile->id,
                'sponsorship_contribution_id' => $contribution->id,
                'accounting_amount_minor' => (int) $contribution->accounting_amount_minor,
                'accounting_currency' => $contribution->accounting_currency,
            ])->save();

            $this->audit->log('payment_verified', $locked, [
                'public_id' => $locked->public_id, 'reference' => $locked->reference, 'contribution' => $contribution->public_id, 'supporter' => $profile->public_id,
                'amount_minor' => (int) $locked->amount_minor, 'currency' => $locked->currency,
                'accounting_amount_minor' => (int) $contribution->accounting_amount_minor, 'accounting_currency' => $contribution->accounting_currency,
                'normalized' => $locked->currency !== $contribution->accounting_currency,
            ], $actor);
            $this->audit->log('contribution_materialized', $contribution, [
                'public_id' => $contribution->public_id, 'payment' => $locked->public_id, 'reference' => $locked->reference, 'anonymous' => (bool) $contribution->is_anonymous,
            ], $actor);

            $materialized = true;

            return $locked;
        });

        if ($materialized && $contribution !== null) {
            SponsorshipCache::bust();
            $this->contributions->publishChange($contribution, $supporterId, $before, true);
        }

        return $verified;
    }

    /** @throws SponsorshipException */
    protected function rejectCore(?User $actor, SponsorshipPayment $payment, string $failureCode, ?string $message, ?string $reviewNote): SponsorshipPayment
    {
        if (! in_array($failureCode, SponsorshipPayment::FAILURE_CODES, true)) {
            throw new PaymentException('سبب الرفض غير صالح.');
        }

        $message = $this->cleanOptionalText($message, 255, 'الرسالة') ?? (SponsorshipPayment::failureLabels()[$failureCode] ?? null);
        $reviewNote = $this->cleanOptionalText($reviewNote, 500, 'الملاحظة الداخلية');
        $changed = false;

        $updated = DB::transaction(function () use ($actor, $payment, $failureCode, $message, $reviewNote, &$changed) {
            $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status === SponsorshipPayment::STATUS_REJECTED) {
                return $locked;
            }

            if (! $locked->isOpen()) {
                throw new PaymentException('لا يُرفض إلا الطلب المفتوح؛ المحقَّق يُسترد.');
            }

            $locked->forceFill([
                'status' => SponsorshipPayment::STATUS_REJECTED, 'rejected_at' => now(), 'rejected_by' => $actor?->id,
                'failure_code' => $failureCode, 'user_message' => $message, 'review_note' => $reviewNote,
            ])->save();
            $this->audit->log('payment_rejected', $locked, ['public_id' => $locked->public_id, 'reference' => $locked->reference, 'code' => $failureCode], $actor);
            $changed = true;

            return $locked;
        });

        if ($changed) {
            $this->notifier->paymentRejected($updated);
        }

        return $updated;
    }

    /** @throws SponsorshipException */
    protected function refundCore(?User $actor, SponsorshipPayment $payment, string $reason): SponsorshipPayment
    {
        $before = null;
        $supporterId = 0;
        $contribution = null;
        $changed = false;

        // ترتيب الأقفال: المساهمة ثم الطلب (مطابق لمسار E30 المباشر ⇒ لا انعكاس أقفال).
        $contributionId = (int) SponsorshipPayment::query()->whereKey($payment->id)->value('sponsorship_contribution_id');

        $updated = DB::transaction(function () use ($actor, $payment, $reason, $contributionId, &$before, &$supporterId, &$contribution, &$changed) {
            $lockedContribution = $contributionId > 0 ? SponsorshipContribution::query()->lockForUpdate()->find($contributionId) : null;
            $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status === SponsorshipPayment::STATUS_REFUNDED) {
                return $locked;
            }

            if ($locked->status !== SponsorshipPayment::STATUS_VERIFIED || $lockedContribution === null) {
                throw new PaymentException('يُسترد الطلب المحقَّق فقط.');
            }

            if ($lockedContribution->isConfirmed()) {
                $contribution = $this->contributions->refundLocked($actor, $lockedContribution, $reason, $before, $supporterId);
            }

            $locked->forceFill(['status' => SponsorshipPayment::STATUS_REFUNDED, 'refunded_at' => now(), 'refunded_by' => $actor?->id])->save();
            $this->audit->log('payment_refunded', $locked, [
                'public_id' => $locked->public_id, 'reference' => $locked->reference, 'contribution' => $lockedContribution->public_id, 'reason' => $reason, 'source' => 'payment',
            ], $actor);
            $changed = true;

            return $locked;
        });

        if ($changed) {
            SponsorshipCache::bust();

            if ($contribution !== null) {
                $this->contributions->publishChange($contribution, $supporterId, $before, false);
            }

            $this->notifier->paymentRefunded($updated);
        }

        return $updated;
    }

    // ================================================================== مساعدات

    protected function assertOwner(User $user, SponsorshipPayment $payment): void
    {
        if ((int) $payment->user_id !== (int) $user->id) {
            throw new PaymentException('هذا الطلب لا يخصّك.');
        }
    }

    /**
     * يكتب حقول الإثبات على الصف المقفول ويُرجع مسار الملف القديم ليُحذف **بعد** الـcommit.
     *
     * @param  array{path: string, mime: string, size: int, sha256: string}  $stored
     */
    protected function applyProof(SponsorshipPayment $locked, array $stored): ?string
    {
        $old = $locked->proof_path;

        $locked->forceFill([
            'proof_path' => $stored['path'], 'proof_mime' => $stored['mime'], 'proof_size' => $stored['size'], 'proof_sha256' => $stored['sha256'],
            'proof_uploaded_at' => now(), 'proof_purged_at' => null,
        ])->save();

        return $old;
    }

    protected function cancelSystem(SponsorshipPayment $payment, string $code): void
    {
        try {
            DB::transaction(function () use ($payment, $code) {
                $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);

                if ($locked->status === SponsorshipPayment::STATUS_AWAITING) {
                    $locked->forceFill(['status' => SponsorshipPayment::STATUS_CANCELLED, 'cancelled_at' => now(), 'failure_code' => substr($code, 0, 32)])->save();
                    $this->audit->log('payment_cancelled', $locked, ['public_id' => $locked->public_id, 'by' => 'system', 'code' => $code]);
                }
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function findByRequestKey(User $user, string $key): ?SponsorshipPayment
    {
        return SponsorshipPayment::query()->where('user_id', $user->id)->where('request_key', $key)->first();
    }

    protected function cleanRequestKey(?string $key): ?string
    {
        $key = trim((string) $key);

        return $key !== '' && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key) === 1 ? $key : null;
    }

    /** نص عادي اختياري (NoMarkup + حدّ طول) أو null. @throws PaymentException */
    protected function cleanOptionalText(?string $value, int $max, string $label): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $validator = Validator::make(['v' => $value], ['v' => ['string', 'min:2', 'max:'.$max, new NoMarkup]], [], ['v' => $label]);

        if ($validator->fails()) {
            throw new PaymentException((string) $validator->errors()->first());
        }

        return $value;
    }
}
`````

### `app/Services/Payments/SupportCatalog.php`

`sha256: 4843a0cb4d89b41ab839efd1ec6f5a34866a2bf1c4a1da57ee38faaa530c6e68`

`````php
<?php

namespace App\Services\Payments;

use App\Models\SponsorshipProgram;
use App\Models\SponsorshipProgramRole;
use App\Services\Sponsorship\SponsorshipRewardService;
use App\Services\Sponsorship\SupportTotals;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * E32 - نموذج القراءة العام لصفحات الدعم (/support): البرامج النشطة الآن فقط + مجاميع التمويل المؤكَّد + التعزيز الحالي + الراعي الرئيسي، بعدد استعلامات ثابت (لا N+1 بعدد البرامج) وبسقف صفوف.
 * كله من قاعدة البيانات مباشرة (لا كاش) وكله مؤكَّد فقط: الطلبات المعلّقة/المرسَلة لا تظهر في أي رقم. الراعي المجهول لا يظهر أبدًا.
 */
class SupportCatalog
{
    public const LIMIT = 24;

    public function __construct(protected SupportTotals $totals) {}

    /** @return Collection<int, SponsorshipProgram> */
    public function programs(): Collection
    {
        return SponsorshipProgram::query()->activeNow()->orderByDesc('activated_at')->orderByDesc('id')->limit(self::LIMIT)
            ->get(['id', 'slug', 'title', 'description', 'target_type', 'target_id', 'status', 'starts_at', 'ends_at', 'goal_amount_minor', 'accounting_currency', 'show_public_amounts', 'show_goal_progress', 'reward_boost_enabled', 'funding_step_minor', 'boost_per_step_bps', 'max_boost_bps']);
    }

    /**
     * @param  Collection<int, SponsorshipProgram>  $programs
     * @return array<int, array{funding: int, progress: ?int, boost_bps: int, main: ?array{name: string, slug: string}}>
     */
    public function cards(Collection $programs): array
    {
        $ids = $programs->pluck('id')->map(fn ($id) => (int) $id)->all();
        $funding = $this->totals->programFundingMany($ids);
        $mains = $this->mainSponsors($ids);
        $out = [];

        foreach ($programs as $program) {
            $id = (int) $program->id;
            $total = $funding[$id] ?? 0;

            $out[$id] = [
                'funding' => $total,
                'progress' => $program->show_goal_progress && (int) $program->goal_amount_minor > 0 ? (int) min(100, intdiv($total * 100, (int) $program->goal_amount_minor)) : null,
                'boost_bps' => $program->reward_boost_enabled
                    ? SponsorshipRewardService::computeBps($total, (int) $program->funding_step_minor, (int) $program->boost_per_step_bps, (int) $program->max_boost_bps)
                    : 0,
                'main' => $mains[$id] ?? null,
            ];
        }

        return $out;
    }

    /**
     * الراعي الرئيسي العلني لكل برنامج (استعلام واحد): ملف علني ونشط له مساهمة مؤكَّدة **غير مجهولة** بالبرنامج نفسه.
     *
     * @param  list<int>  $programIds
     * @return array<int, array{name: string, slug: string}>
     */
    protected function mainSponsors(array $programIds): array
    {
        if ($programIds === []) {
            return [];
        }

        $rows = DB::table('sponsorship_program_roles as r')
            ->join('supporter_profiles as sp', 'sp.id', '=', 'r.supporter_profile_id')
            ->whereIn('r.sponsorship_program_id', $programIds)->where('r.role', SponsorshipProgramRole::ROLE_MAIN_SPONSOR)
            ->where('sp.status', 'active')->where('sp.is_public', true)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('sponsorship_contributions as c')
                ->whereColumn('c.supporter_profile_id', 'r.supporter_profile_id')->whereColumn('c.sponsorship_program_id', 'r.sponsorship_program_id')
                ->where('c.status', 'confirmed')->where('c.is_anonymous', false))
            ->orderBy('r.id')->limit(self::LIMIT)
            ->get(['r.sponsorship_program_id as pid', 'sp.display_name as name', 'sp.slug as slug']);

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->pid] ??= ['name' => (string) $row->name, 'slug' => (string) $row->slug];
        }

        return $out;
    }
}
`````

### `config/payments.php`

`sha256: 50727e042323a30fed72b0d8a272b4fd09aa21fdaaf61ea6b56a563e0cf2864d`

`````php
<?php

/*
| E32 - دفع الرعاية وعمليات الدفع. كل القيم قابلة للضبط بالبيئة أو هنا؛ **لا أسرار ولا بيانات بنكية ولا مفاتيح مزوّد** في هذا الملف أو في .env.example.
| لا بطاقات ولا SDK ولا بوابة حيّة: المزوّد الوحيد العامل هو «manual» (تحويل يدوي + إثبات خاص + تحقق مالي). المزوّدون الإلكترونيون المستقبليون
| (Stripe/PayPal/PayTabs/HyperPay/Tap/MyFatoorah/بوابة بنكية) يتصلون عبر عقد PaymentProvider دون المساس بنموذج SponsorshipContribution (docs/sponsorship-payments-e32.md).
| تفاصيل التحويل العلنية (المستفيد/الآيبان…) تُدار من إعدادات المنصة (مجموعة payments) لا من هنا.
*/
return [
    // مفتاح إيقاف شامل: false ⇒ لا طلبات دعم جديدة (الطلبات القائمة تبقى قابلة للمراجعة والاسترداد من الإدارة).
    'enabled' => (bool) env('PAYMENTS_ENABLED', true),

    'default_provider' => (string) env('PAYMENTS_DEFAULT_PROVIDER', 'manual'),

    // سجل المزوّدين (المفتاح فريد). كل مزوّد: enabled (نشر/بيئة) + class (يُحلّ من حاوية الخدمات فيمكن استبداله/حقنه).
    // إضافة مزوّد مستقبلًا = سطر هنا + صنف ينفّذ PaymentProvider. المزوّدون غير المفعّلين هنا يرفضهم السجل كأنهم غير موجودين.
    'providers' => [
        'manual' => [
            'enabled' => (bool) env('PAYMENTS_MANUAL_ENABLED', true),
            'class' => \App\Services\Payments\ManualPaymentProvider::class,
        ],
    ],

    // العملات المسموحة للدفع (تُقاطَع دائمًا مع config/sponsorship.php: لا تُقبل عملة غير معرّفة هناك). قائمة مفصولة بفواصل.
    'allowed_currencies' => array_values(array_filter(array_map(
        static fn (string $c): string => strtoupper(trim($c)),
        explode(',', (string) env('PAYMENTS_ALLOWED_CURRENCIES', 'USD,EUR,TRY'))
    ))),

    'checkout' => [
        // حدّ أدنى بالوحدة الصغرى (100 = 1.00). الحدّ الأعلى = SPONSORSHIP_MAX_AMOUNT_MINOR (E30) ما لم يُضبط أقل هنا.
        'min_amount_minor' => (int) env('PAYMENTS_MIN_AMOUNT_MINOR', 100),
        'max_amount_minor' => env('PAYMENTS_MAX_AMOUNT_MINOR') !== null && env('PAYMENTS_MAX_AMOUNT_MINOR') !== '' ? (int) env('PAYMENTS_MAX_AMOUNT_MINOR') : null,
        // مبالغ مقترحة بالوحدة الرئيسية (للراحة فقط - الخادم يتحقق دائمًا من المبلغ المُرسَل).
        'suggested_amounts' => [10, 25, 50, 100, 250],
        'allow_custom_amount' => (bool) env('PAYMENTS_ALLOW_CUSTOM_AMOUNT', true),
        // سقف الطلبات المفتوحة (بانتظار الدفع/قيد التحقق) لكل مستخدم: يمنع إغراق الطابور.
        'max_open_requests_per_user' => (int) env('PAYMENTS_MAX_OPEN_REQUESTS', 5),
        'note_max' => 255,
        'display_name_max' => 80,
    ],

    'request' => [
        // صلاحية طلب «بانتظار الدفع». المقدَّم/المحقَّق لا ينتهي أبدًا آليًا.
        'expiry_hours' => (int) env('PAYMENTS_REQUEST_EXPIRY_HOURS', 48),
        'reference_prefix' => 'SUP',
        'reference_length' => 8,
        // أبجدية مرجع التحويل بلا أحرف ملتبسة (0/O/1/I).
        'reference_alphabet' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
        'expire_batch' => 200,
    ],

    // إثبات التحويل: ملف **خاص** على قرص غير علني، يُقدَّم عبر متحكم مخوَّل فقط. JPEG/PNG/WEBP/PDF بنوع المحتوى الفعلي؛ لا SVG ولا HTML.
    'proof' => [
        'disk' => (string) env('PAYMENTS_PROOF_DISK', 'local'),
        'directory' => 'payment-proofs',
        'max_kb' => (int) env('PAYMENTS_PROOF_MAX_KB', 5120),
        'mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'],
        'max_pixels' => 40_000_000,
    ],

    // الاحتفاظ: لا يُحذف سجل مالي. الإثبات (بيانات شخصية/مالية) يمكن تقليمه بعد مدة من الحالة النهائية بأمر صريح (payments:prune-proofs --dry-run أولًا).
    'retention' => [
        'proof_days_after_final' => (int) env('PAYMENTS_PROOF_RETENTION_DAYS', 365),
        // لا يُقلَّم إثبات طلب مُسترد/مرفوض حديثًا (نافذة نزاع) مهما كانت المدة أعلاه.
        'dispute_window_days' => (int) env('PAYMENTS_DISPUTE_WINDOW_DAYS', 90),
        'prune_batch' => 200,
    ],

    // بنية Webhook المستقبلية. لا مزوّد إلكتروني حيّ ⇒ المسار مغلق افتراضيًا ويرفض كل مزوّد غير معروف/غير مفعّل/لا يدعمه.
    'webhooks' => [
        'enabled' => (bool) env('PAYMENTS_WEBHOOKS_ENABLED', false),
        'max_body_bytes' => 65536,
    ],

    'reconcile' => [
        'max_findings' => 200,
    ],

    'analytics' => [
        'cache_ttl' => 120,
        'window_days' => 30,
    ],

    'notifications' => [
        'enabled' => (bool) env('PAYMENTS_NOTIFICATIONS_ENABLED', true),
    ],
];
`````

### `database/seeders/DemoE32Seeder.php`

`sha256: 9c35bcd7746bb4559aaa7c429a51defc52e24463f52dd075e588d21052c1bfee`

`````php
<?php

namespace Database\Seeders;

use App\Models\SponsorshipPayment;
use App\Models\SponsorshipProgram;
use App\Models\User;
use App\Services\Payments\SponsorshipPaymentService;
use App\Services\Sponsorship\SponsorshipProgramService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

/**
 * E32 - بيانات Demo لطلبات الدعم/الدفع.   php artisan db:seed --class=DemoE32Seeder
 *
 * - اختيارية وترفض الإنتاج؛ **بيانات وهمية بالكامل** (بلا حسابات بنكية ولا أسرار ولا شبكة).
 * - بالخدمات الرسمية فقط (إنشاء/تحويل/تحقق/رفض/استرداد): لا كتابة مباشرة بحالة أو مبلغ.
 * - Idempotent: كل طلب بمفتاح request_key ثابت لكل مستخدم ⇒ إعادة التشغيل لا تُنشئ طلبات مكررة.
 * - تولّد طلبات: معلّق، مُرسَل مع إثبات، محقَّق، مرفوض، مجهول محقَّق، مسترد.
 */
class DemoE32Seeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public const ADMIN_EMAIL = 'demo-payments-admin@sponsorship.test';

    public function run(): void
    {
        $this->refuseInProduction('DemoE32Seeder');
        $this->call(RolesAndPermissionsSeeder::class);

        $admin = $this->user(self::ADMIN_EMAIL, 'مدير الدفع التجريبي');
        $admin->syncRoles(['administrator']);

        $programs = app(SponsorshipProgramService::class);
        $program = SponsorshipProgram::query()->where('title', 'دعم أحجيات (دفع تجريبي)')->first()
            ?? $programs->create($admin, ['title' => 'دعم أحجيات (دفع تجريبي)', 'target_type' => 'platform', 'description' => 'برنامج تجريبي لمسار الدعم والدفع اليدوي.', 'goal_amount' => '2000', 'show_public_amounts' => true, 'show_goal_progress' => true]);

        if ($program->status === SponsorshipProgram::STATUS_DRAFT) {
            $program = $programs->activate($admin, $program);
        }

        $service = app(SponsorshipPaymentService::class);
        $users = [];

        foreach ([
            'pending' => 'ليلى (طلب معلّق)',
            'submitted' => 'عمر (إثبات مُرسَل)',
            'verified' => 'هالة (محقَّق)',
            'rejected' => 'ياسر (مرفوض)',
            'anonymous' => 'داعم مجهول',
            'refunded' => 'نادر (مسترد)',
        ] as $key => $name) {
            $users[$key] = $this->user("demo-e32-{$key}@sponsorship.test", $name);
        }

        $pending = $this->request($service, $users['pending'], $program, '15', false);

        $submitted = $this->request($service, $users['submitted'], $program, '40', false);
        if ($submitted->status === SponsorshipPayment::STATUS_AWAITING) {
            $service->markTransferred($users['submitted'], $submitted, 'حوّلت من حساب عمر', $this->tinyPng());
        }

        $verified = $this->request($service, $users['verified'], $program, '60', false);
        if ($verified->isOpen()) {
            $service->verify($admin, $verified);
        }

        $rejected = $this->request($service, $users['rejected'], $program, '25', false);
        if ($rejected->isOpen()) {
            $service->reject($admin, $rejected, 'not_received', 'لم يصلنا التحويل بهذا المرجع.', 'تجريبي');
        }

        $anonymous = $this->request($service, $users['anonymous'], $program, '120', true);
        if ($anonymous->isOpen()) {
            $service->verify($admin, $anonymous);
        }

        $refunded = $this->request($service, $users['refunded'], $program, '30', false);
        if ($refunded->isOpen()) {
            $refunded = $service->verify($admin, $refunded);
        }
        if ($refunded->status === SponsorshipPayment::STATUS_VERIFIED) {
            $service->refund($admin, $refunded, 'استرداد تجريبي للعرض');
        }

        $this->command?->info('DemoE32Seeder: طلبات الدعم ('.SponsorshipPayment::query()->count().'). الطلب المعلّق: '.$pending->reference.'. كلمة مرور الحسابات التجريبية: '.self::PASSWORD);
    }

    protected function request(SponsorshipPaymentService $service, User $user, SponsorshipProgram $program, string $amount, bool $anonymous): SponsorshipPayment
    {
        return $service->createRequest($user, $program, $amount, 'USD', $anonymous, null, 'manual', 'demo-e32-'.$user->id);
    }

    protected function user(string $email, string $name): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /** صورة PNG وهمية 1×1 كإثبات تجريبي (لا بيانات حقيقية). */
    protected function tinyPng(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'e32');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        return new UploadedFile($path, 'demo-proof.png', 'image/png', null, true);
    }
}
`````

### `docs/payment-operations-runbook.md`

`sha256: d8cc08d9db96c58925f3ada22c2c38171a01cbfc515a7ad7967b46a0aa073e37`

`````markdown
# دليل عمليات الدفع والرعاية (E32)

> للمراجع المالي ومسؤول التشغيل. الأساس التقني: [sponsorship-payments-e32.md](sponsorship-payments-e32.md). الاستجابة للحوادث العامة: [incident-response.md](incident-response.md).
> **قاعدة ذهبية:** لا تؤكّد دعمًا قبل أن ترى المال فعلًا في حسابك. «تحقق» يجعل المبلغ يدخل فورًا في التمويل والمستوى والصدارة وتعزيز المكافآت.

## الأدوار

| الدور | ما يستطيعه |
|---|---|
| مدير مالي (`sponsorships.confirm`، مثل `administrator`) | تحقق، رفض، طلب توضيح، إلغاء، استرداد، فتح الإثبات، تعديل إعدادات الدفع |
| مدير محتوى (`sponsorships.view/manage`) | يرى الطابور ويدير البرامج والداعمين، **لا** ينفّذ إجراءً ماليًا ولا يرى الإثبات |
| لاعب/زائر | طلب الدعم وإثباته وإلغاؤه قبل الإرسال فقط |

كل إجراء مالي يُدقَّق بالمنفِّذ والوقت. افصل بين من يستلم المال ومن يؤكّده إن أمكن (مبدأ العينين).

## الروتين اليومي

1. افتح «الرعاية والدعم ← طلبات الدعم والدفع» وصفِّ الحالة على `submitted` (الشارة تعرض عددها).
2. لكل طلب: طابق **المبلغ + العملة + رمز المرجع (SUP-…) + اسم المحوِّل** مع كشف الحساب. افتح الإثبات من زر «الإثبات» (مرفق خامل، يُدقَّق).
3. إن وُجدت علامة «إثبات مكرر» فالإثبات نفسه استُعمل في طلب آخر: لا تؤكّد قبل المقارنة (انظر «إثبات مكرر»).
4. نفّذ: تحقق / رفض / توضيح. لا تترك المرسَل أكثر من يوم عمل.
5. أسبوعيًا (أو قبل التقارير المالية): `php artisan sponsorship:reconcile-payments` — يجب أن يخرج `mismatches=0`.

## التحقق (Verify)

1. تأكد من وصول المال بالمبلغ والعملة الصحيحين ورمز المرجع.
2. اضغط «تحقق». **عملة غير عملة المحاسبة (USD افتراضيًا):** أدخل المعادل بعملة المحاسبة حسب سعر يوم الاستلام الفعلي (لا سعر صرف آلي).
3. النتيجة: مساهمة مؤكَّدة مرتبطة، ملف داعم (يُنشأ إن لم يوجد)، إشعار للداعم، تحديث المستوى والصدارة والتمويل.
4. الضغط المتكرر/تبويبان آمنان: لا مساهمة ثانية.
5. إن ظهر خطأ «البرنامج مغلق/ملف الداعم موقوف»: لم يتغير شيء (rollback كامل). أعد فتح البرنامج أو أزل الإيقاف ثم أعد المحاولة، أو ارفض/استرد خارجيًا.

## الرفض (Reject)

اختر السبب من القائمة (المبلغ غير مطابق، المرجع مفقود، الإثبات غير مقروء، لم يصل التحويل، مكرر، اشتباه احتيال، أخرى). **الرسالة** تظهر لصاحب الطلب؛ **الملاحظة الداخلية** لا تظهر له ولا للعامة. الرفض نهائي؛ ينشئ المستخدم طلبًا جديدًا.

## طلب توضيح

للطلب المرسَل الذي ينقصه إثبات أوضح: يعود إلى «بانتظار الدفع» مع رسالتك ومهلة جديدة (48 ساعة افتراضيًا) ويُشعَر المستخدم، فيرسل إثباتًا جديدًا ثم يعود للطابور.

## الإلغاء الإداري

لطلب مفتوح خاطئ أو مكرر (يتطلب سببًا يراه المستخدم). **المحقَّق لا يُلغى: يُسترد.**

## الاسترداد (Refund)

1. أعد المال للداعم **خارج النظام أولًا** (تحويل عكسي).
2. اضغط «استرداد» واكتب السبب. تصير المساهمة `refunded` ويُخصم المبلغ من الصافي ويتراجع المستوى وتتغير الصدارة والتمويل، ويُشعَر الداعم.
3. **لا تُسحب** مكافآت اللاعبين التي مُنحت سابقًا بسبب التعزيز (لقطات تاريخية)؛ فقط يتوقف التعزيز للحلول القادمة. هذا مقصود.
4. الاسترداد المزدوج آمن (لا أثر ثانٍ). الاسترداد من شاشة مساهمات E30 مباشرة يُزامن الطلب تلقائيًا.

## مال وصل لطلب منتهٍ

الطلب `expired` نهائي ولا يتحقق منه. إن وصل المال فعلًا: أنشئ **مساهمة يدوية في E30** («الرعاية والدعم ← المساهمات»: تسجيل ثم تأكيد) بنفس الداعم والمبلغ، ودوّن رمز المرجع في الحقل المرجعي، ثم أخبر الداعم. (البديل: استرد المال وليُنشئ الداعم طلبًا جديدًا.)

## تحويل مكرر / دفع مرتين

1. لا تؤكّد إلا الطلب الذي يطابق المرجع. الطلب الثاني: ارفضه بسبب «مكرر».
2. إن وصل مالان فعليًا وقبلتَ الأول فقط: استرد الثاني خارجيًا ودوّن المرجع في الملاحظة الداخلية.

## إثبات مكرر

العلامة تعني تطابق SHA-256 مع إثبات طلب آخر. قارن الطلبين: نفس المستخدم يعيد المحاولة (عادة بريء) أم مستخدمان مختلفان (اشتباه احتيال). في الاشتباه: ارفض بسبب «اشتباه احتيال» ودوّن التفاصيل داخليًا ولا تعلنها للمستخدم.

## إثبات مفقود أو غير مقروء

ما لم يفرض الإعداد «الإثبات إلزامي» يمكن الإرسال بلا إثبات. اطلب توضيحًا بدل الرفض إن كان المال ظاهرًا في الحساب. بعد تقليم الإثباتات القديمة لا يمكن فتحها (يبقى السجل والبصمة).

## اشتباه احتيال

1. لا تؤكّد. ارفض (اشتباه احتيال) أو أوقف الإجراء وارفع للمسؤول.
2. إن كان حساب اللاعب مسيئًا: جمّد الحساب من إدارة المستخدمين (الحساب المجمَّد لا يُنشئ طلبات ولا يتلقى إشعارات).
3. إن تأكّد التحقق خطأً: استرد فورًا.
4. وثّق في الملاحظة الداخلية ولا تكتب فيها بيانات بطاقات أو كلمات مرور.

## المطابقة المالية

```
php artisan sponsorship:reconcile-payments          # نص
php artisan sponsorship:reconcile-payments --json   # للمراقبة
```

للقراءة فقط؛ الخروج `1` عند اختلاف. التعامل مع الأكواد:

| الكود | الإجراء |
|---|---|
| `verified_without_contribution` / `contribution_not_confirmed` | حالة غير متسقة نادرة (تدخل يدوي بقاعدة البيانات أو عطل). لا تعدّل بالقاعدة. وثّق المعرّف العلني واستشر المطوّر؛ الإصلاح بإجراء إداري مدقَّق |
| `amount_mismatch` / `currency_mismatch` | قارن الطلب بالمساهمة؛ إن كان خطأ إدخال معادل محاسبي فالتصحيح بإجراء E30 (استرداد ثم تسجيل جديد) لا بتعديل الأرقام |
| `refund_state_mismatch` | أعد مزامنة الحالة بإجراء الاسترداد الرسمي (آمن للتكرار) |
| `orphan_materialized_contribution` | مساهمة بمرجع طلب لا يرتبط بها: لا تحذفها؛ ارفع للمطوّر |
| `duplicate_*` | يمنعها UNIQUE؛ ظهورها يعني مشكلة بنية تحتية ⇒ حادثة |

## إعدادات الدفع

«الرعاية والدعم ← إعدادات الدفع» (للمالية فقط): المستفيد/البنك/الآيبان/ملاحظة المرجع. هذه معلومات تحويل **علنية مقصودة** للمحوِّل؛ **لا** كلمات مرور بنكية ولا أرقام بطاقات (يرفضها النظام). تغيير الحساب المستفيد يوجّه أموال الداعمين: تحقق منه بقناة ثانية قبل الحفظ؛ التدقيق يسجّل أن المفتاح تغيّر (لا القيمة). إيقاف الاستقبال مؤقتًا: عطّل «التحويل اليدوي» (الطلبات القائمة تبقى قابلة للمراجعة) أو `PAYMENTS_ENABLED=false` بالنشر.

## الاحتفاظ بالإثباتات

الإثبات بيانات شخصية/مالية. بعد `PAYMENTS_PROOF_RETENTION_DAYS` من الحالة النهائية (وخارج نافذة النزاع `PAYMENTS_DISPUTE_WINDOW_DAYS` للمرفوض/المسترد):

```
php artisan payments:prune-proofs --dry-run   # عدّ المرشَّحين
php artisan payments:prune-proofs             # تقليم (يحذف الملف فقط؛ السجل والبصمة يبقيان)
```

لا يُقلَّم أبدًا إثبات طلب مفتوح. خذ نسخة احتياطية سليمة قبل أول تقليم.

## حادثة بوابة دفع (مستقبلًا)

لا بوابة إلكترونية الآن. عند إضافة واحدة:

1. اشتباه تزوير webhook: عطّل `PAYMENTS_WEBHOOKS_ENABLED` فورًا (يغلق المسار 404) وراجع أحداث `payment_callback_needs_review` في التدقيق.
2. دوّر أسرار المزوّد من مدير الأسرار (لا تُخزَّن بقاعدة البيانات ولا الوثائق).
3. شغّل المطابقة وقارن مع كشف المزوّد؛ ما لم يتحقق آليًا يُراجَع يدويًا.
4. أعد تفعيل المسار بعد التحقق من التوقيعات.

## استكشاف الأخطاء

| العرض | السبب والحل |
|---|---|
| المستخدم لا يستطيع إنشاء طلب | بريد غير موثَّق، حساب مجمَّد، ملف داعم موقوف، سقف الطلبات المفتوحة، البرنامج غير نشط، أو الدفع معطَّل |
| «الدفع غير متاح حاليًا» بالـcheckout | لا طريقة مفعّلة: `PAYMENTS_MANUAL_ENABLED` أو مفتاح الإدارة أو `PAYMENTS_ENABLED` |
| طلب انتهى والمستخدم دفع | «مال وصل لطلب منتهٍ» أعلاه |
| «تعذّر رفع الملف» | الحجم/النوع (JPEG/PNG/WEBP/PDF فقط) أو قرص الإثبات غير خاص |
| لا يظهر المبلغ في الصفحة العامة | البرنامج لا يفعّل «إظهار المبالغ»؛ الدعم المجهول لا يظهر اسمه أبدًا |
| الطلبات لا تنتهي | تأكد من تشغيل المجدول (`schedule:run`) — الأمر `payments:expire-stale` |

## قبل الإطلاق (قائمة قصيرة)

- [ ] ضبط إعدادات الدفع (المستفيد/الحساب) والتحقق منها بقناة ثانية.
- [ ] `PAYMENTS_PROOF_DISK` قرص خاص، والنسخ الاحتياطي يشمله بتشفير.
- [ ] المجدول يعمل (`payments:expire-stale` ظاهر في مركز العمليات).
- [ ] تدريب المراجعين على «لا تؤكّد قبل رؤية المال».
- [ ] مراجعة قانونية لسياسة الاستبدال/المكافآت في الدول المستهدفة (قرار غير تقني).
- [ ] تشغيل اختبارات `PaymentsE32` على MySQL محليًا (انظر دليل E32).
`````

### `docs/sponsorship-payments-e32.md`

`sha256: ae1bb06c078f16b5f7d6c9f6fb69c7a396790556c5afc4a5caa6ced14347e461`

`````markdown
# دفع الرعاية والعمليات المالية (E32)

> يكمل مسار المال الكامل للرعاية فوق [E30](sponsorship-supporters-e30.md): **زائر/مستخدم ← برنامج ← مبلغ ← علني/مجهول ← طريقة دفع ← طلب دعم ← تحويل يدوي + إثبات ← تحقق مالي ← مساهمة مؤكَّدة ← مستوى/صدارة/تمويل/تعزيز**.
> المزوّد العامل الآن واحد: **التحويل اليدوي** (`manual`). **لا بوابة دفع حيّة، لا SDK، لا بطاقات، لا سعر صرف حيّ، لا حزمة Composer/npm جديدة.** البنية جاهزة لإضافة مزوّدين إلكترونيين لاحقًا عبر عقد واحد دون المساس بنموذج المساهمة.
> دليل التشغيل اليومي (تحقق/رفض/استرداد/مطابقة/حوادث): [payment-operations-runbook.md](payment-operations-runbook.md).

## المبادئ الصلبة

1. **المال المؤكَّد وحده يُحتسب.** طلب الدعم (`SponsorshipPayment`) يتتبّع مسار الدفع فقط؛ **`SponsorshipContribution` المؤكَّدة هي السجل المالي الوحيد** الذي تقرؤه خدمات E30 (التمويل، المستوى، الصدارة، التعزيز). طلب في أي حالة غير `verified`/`refunded` لا يؤثر في أي مجموع أو مستوى أو لوحة أو تعزيز.
2. **المستخدم لا يؤكّد دعمه.** لا مسار عام يغيّر الحالة إلى محقَّق أو يمنح مستوى أو يضبط المعادل المحاسبي؛ المتصفح يرسل المبلغ والعملة والخصوصية فقط (`CheckoutRequest` تُمرِّر حقولًا معتمدة صراحةً لا `$request->all()`).
3. **التحقق ذرّي وidempotent.** معاملة واحدة تقفل الطلب، تنشئ/تحلّ ملف الداعم، تنشئ المساهمة وتؤكّدها بمنطق E30 نفسه، وتربط الطلب بها وتدقّق. أي فشل ⇒ rollback كامل. الإشعارات والكاش **بعد** الـcommit وفشلها لا يرجع المال.
4. **قاعدة البيانات هي الحدّ النهائي** (قيود UNIQUE) وليس الكود فقط.
5. **المبالغ أعداد صحيحة بالوحدة الصغرى** (لا floats). بلا سعر صرف حيّ: العملة الأجنبية تتطلب إدخال المعادل المحاسبي عند التحقق (كما E30).
6. **الخصوصية افتراضية:** الدعم المجهول لا يُعرَّف علنًا في أي مكان؛ البريد/الإثبات/ملاحظة المراجع/مرجع التحويل لا تُعرض علنًا؛ لا PAN ولا CVV ولا كلمات مرور بنكية ولا أسرار مزوّد ولا أجسام webhook خام تُخزَّن أو تُسجَّل.
7. **التاريخ المالي لا يُحذف:** لا حذف طلب، ولا حذف تتابعي، وحذف المستخدم محجوب (`FinancialHistoryGuard`).
8. **لا سحب لمكافآت اللاعبين:** الاسترداد يعدّل الصافي والمستوى والصدارة والتمويل فقط؛ المكافآت التي مُنحت للاعبين سابقًا لقطات تاريخية لا تُسحب.
9. **أحجيات المجتمع وسلاسل المبدعين خارج الرعاية:** لا دفع لها ولا تعزيز صفر (E30) — ليست أهدافًا للرعاية أصلًا.

## رحلة الداعم

| الخطوة | الصفحة | ما يحدث |
|---|---|---|
| 1 | `/support` و`/support/{program}` (عامة، بلا حساب) | البرامج النشطة الآن فقط + تمويلها المؤكَّد وتعزيزها الحالي والراعي الرئيسي العلني. برنامج مسودة/مغلق/ملغى/منتهٍ ⇒ 404 |
| 2 | `/support/{program}/checkout` (تسجيل دخول + بريد موثَّق) | ثلاث خطوات: المبلغ والعملة ← طريقة الدفع والخصوصية (باسمي/مجهول) ← التأكيد. الخادم يعيد التحقق من كل شيء |
| 3 | `/support/payments/{ulid}` (المالك فقط) | رمز مرجع التحويل `SUP-XXXXXXXX` + تعليمات التحويل (من إعدادات الإدارة) + «حوّلت المبلغ» + رفع إثبات |
| 4 | الحالة «طلب الدعم بانتظار التحقق» | لا يُقال «تم الدفع» قبل التحقق إطلاقًا |
| 5 | المراجعة المالية | تحقق ⇒ دعم مؤكَّد ويظهر المستوى (إلا إن اختار المجهول) / رفض برسالة / طلب توضيح / استرداد لاحقًا |
| 6 | `/support/history` | سجل طلبات المستخدم (15 في الصفحة) |

الضيف (بلا حساب) **غير مدعوم الآن** (P1): يحتاج عمود بريد مشفّرًا ومسار تحقق؛ لم يُضف عمود بريد خام عمدًا. الزائر يُحوَّل لتسجيل الدخول.

## الجداول

هجرة واحدة جديدة: `2026_10_24_000001_create_sponsorship_payments_table` (لا تعديل لأي هجرة قديمة).

`sponsorship_payments`: `public_id` (ULID) · `reference` (رمز التحويل) · FKs كلها `RESTRICT` (البرنامج، المستخدم، ملف الداعم، المساهمة) · `provider` · `provider_reference` · `request_key` · `amount_minor` + `currency` + `accounting_amount_minor` + `accounting_currency` (أعداد صحيحة/رموز) · `status` · `is_anonymous` + `display_name` · `payer_note` (يراها المالك والمراجع) · `user_message` (مرئية لصاحب الطلب) · `review_note` (داخلية فقط) · `failure_code` (قائمة بيضاء) · حقول الإثبات (`proof_path/mime/size/sha256/uploaded_at/purged_at`) · طوابع المراحل.

قيود قاعدة البيانات (الحدّ النهائي):

| القيد | يمنع |
|---|---|
| `UNIQUE(sponsorship_contribution_id)` | طلبان لمساهمة واحدة / مساهمة ثانية لنفس الطلب |
| `UNIQUE(provider, provider_reference)` | تكرار مرجع المزوّد (NULL المتعدد مسموح لليدوي) |
| `UNIQUE(reference)` | تكرار رمز التحويل |
| `UNIQUE(user_id, request_key)` | نقر مزدوج على «إنشاء الطلب» |
| `UNIQUE(provider, external_reference)` على المساهمات (E30) | مساهمتان من الطلب نفسه |

جدول أحداث `payment_events` **لم يُنشأ عمدًا**: التدقيق التشغيلي (`operational_audit_logs`) يغطي كل انتقال بالمعرّف العلني ورمز المرجع، ولا يحفظ أجسام webhook الخام. يُراجَع عند إضافة أول مزوّد إلكتروني حقيقي (فريد `event_id` مفيد وقتها).

## آلة الحالات

```
awaiting_payment → submitted | verified | cancelled | expired | rejected
submitted        → verified | rejected | cancelled | awaiting_payment (طلب توضيح)
verified         → refunded
rejected | cancelled | expired | refunded → نهائية
```

- المنتهي آليًا هو **بانتظار الدفع فقط** (`PAYMENTS_REQUEST_EXPIRY_HOURS`). المرسَل/المحقَّق/المسترد لا ينتهي أبدًا.
- حقول الهوية المالية (المبلغ، العملة، البرنامج، المستخدم، المزوّد، المرجع، الخصوصية) **ثابتة بعد الإنشاء**؛ ربط المساهمة مرة واحدة فقط (`null → قيمة`).
- طلب منتهٍ نهائي: لا يتحقق منه المراجع. إن وصل مال فعلًا: يُسجَّل بمسار E30 اليدوي (تسجيل + تأكيد مساهمة) — انظر دليل العمليات.

## بنية المزوّدين (قابلة للتوسعة)

`PaymentProvider` (واجهة): `key / label / isEnabled / supportsRedirect / supportsWebhook / supportsRefund / currencies / createPayment / verifyCallback / refundReference / status`.

- `PaymentProviderRegistry` يحلّ المزوّدين من `config/payments.php` (`providers.{key}.class`)، يرفض المفتاح المجهول أو المعطَّل أو غير الصالح (`^[a-z0-9_]{1,32}$`)، ويسمح بالحقن في الاختبارات.
- `ManualPaymentProvider`: لا redirect ولا webhook ولا استرداد آلي. التفعيل = شرط النشر `PAYMENTS_MANUAL_ENABLED` **و** مفتاح الإدارة `payments.manual_enabled`.
- `ProviderCallback` حدث موحَّد (`paid | failed | refunded` + `eventId` + `providerReference` + المبلغ/العملة)، يُنتجه المزوّد **بعد التحقق من توقيعه** (`verifyCallback`).

### مسار Webhook المستقبلي (مغلق افتراضيًا)

`POST /api/payments/webhooks/{provider}` (اسم المسار `payments.webhook`، محدِّد `payment-webhook` 120/دقيقة لكل IP، ضمن مجموعة `api` — بلا جلسة ولا CSRF لأن التوقيع هو المصادقة، **دون استثناء CSRF**):

| الشرط | النتيجة |
|---|---|
| `PAYMENTS_WEBHOOKS_ENABLED=false` (الافتراضي) | 404 |
| مزوّد غير معروف/معطَّل/لا يدعم webhook (مثل `manual`) | 404 |
| جسم أكبر من 64KB | 413 |
| فشل التحقق من التوقيع في المزوّد | 400 بلا أثر |
| نجح | `applyProviderCallback`: `applied | duplicate | ignored | needs_review` |

سلوك `applyProviderCallback` (آمن للتكرار): «مدفوع» يتحقق آليًا **فقط** إن طابق المبلغ والعملة الطلب وكانت عملة الطلب هي عملة المحاسبة (لا FX حيّ) — غير ذلك `needs_review` + تدقيق دون تغيير حالة. «فاشل» ⇒ رفض. «مسترد» ⇒ استرداد. مرجع مجهول ⇒ `ignored`. الجسم لا يُخزَّن ولا يُسجَّل.

### إضافة مزوّد إلكتروني لاحقًا (ليس منفَّذًا الآن)

لم يُنفَّذ أي من Stripe / PayPal / PayTabs / HyperPay / Tap / MyFatoorah / بوابة بنكية. لإضافة واحد:

1. صنف ينفّذ `PaymentProvider` (يقرأ أسراره من البيئة/المدير السري فقط، ولا يخزّنها في قاعدة البيانات).
2. سطر في `config/payments.php → providers` + متغيرات بيئة بأسماء جديدة في `.env.example` **بقيم فارغة**.
3. `createPayment` يعيد `providerReference` (+ `redirectUrl` للتحويل) ويُخزَّن بالعمود الفريد.
4. `verifyCallback` يتحقق من **توقيع المزوّد على الجسم الخام** (HMAC/مفتاح عام) ويرمي `PaymentException` عند الفشل؛ ولا يعيد أي قيمة قبل التحقق.
5. اختبارات العقد (انظر `E32ProviderReconcileExpiryTest` — مزوّد وهمي كامل داخل الاختبار) + اختبار توقيع حقيقي للمزوّد الجديد.
6. تفعيل `PAYMENTS_WEBHOOKS_ENABLED=true` وتسجيل عنوان الـwebhook عند المزوّد.
7. مراجعة جدول `payment_events` (فريد `event_id`) والإبلاغ المالي.

لا شيء من ذلك يلمس `SponsorshipContribution` أو خدمات E30.

## التحقق وتجسيد المساهمة (`SponsorshipPaymentService::verify`)

داخل `DB::transaction` واحدة:

1. `lockForUpdate` على الطلب؛ إن كان محقَّقًا ومربوطًا ⇒ يُعاد كما هو (لا أثر ثانٍ).
2. غير مفتوح (مرفوض/ملغى/منتهٍ/مسترد) ⇒ رفض.
3. `SupporterService::ensureForUser`: يقفل صف المستخدم، يعيد استعمال ملف الداعم الموجود، أو يُنشئ ملفًا فرديًا (`is_public = !مجهول`، `anonymous_by_default = مجهول`). ملف أُنشئ كمجهول لا يصير علنيًا إلا بقرار علني لاحق.
4. إنشاء `SponsorshipContribution` معلّقة (`provider` + `external_reference = رمز الطلب`) ثم **`ContributionService::confirmLocked`** — نفس منطق E30 بالضبط (المعادل المحاسبي، حالة البرنامج، إيقاف الداعم، التدقيق).
5. ربط الطلب بالمساهمة + حقول المعادل + تدقيق `payment_verified` و`contribution_materialized`.

بعد الـcommit فقط: `SponsorshipCache::bust()` + إشعار «تم تأكيد دعمك» (E30 `SupportConfirmed`) + إشعار تغيّر المستوى. فشلها يُسجَّل (`report`) ولا يرجع شيئًا.

ترتيب الأقفال في الاسترداد: **المساهمة ثم الطلب** (مطابق لمسار E30 المباشر) فلا انعكاس أقفال. استرداد مساهمة E30 المرتبطة بطلب مباشرةً يزامن الطلب إلى `refunded` بالمعاملة نفسها (`syncRefundFromContribution`) ويُشعر مرة واحدة.

الصلاحية: `sponsorships.confirm` لكل إجراء مالي (تحقق/رفض/توضيح/إلغاء إداري/استرداد/تنزيل الإثبات). عرض الطابور: `sponsorships.view`. مدير المحتوى يرى بلا تنفيذ.

> **P1 (موثَّق):** صلاحية الاسترداد لا تنفصل عن التأكيد (E30 ربطهما في `sponsorships.confirm`)؛ فصلها يتطلب تغيير E30 وتهجير أدوار.

## الإثبات (ملف حساس)

- **قرص خاص فقط** (`PAYMENTS_PROOF_DISK`، يُرفض `public` أو أي قرص رؤيته public): لا رابط مباشر ولا symlink عام.
- يُقبل JPEG/PNG/WEBP/PDF **بنوع المحتوى الفعلي** (`finfo`) لا بالامتداد؛ الصور تُتحقَّق بـ`getimagesize` مع سقف أبعاد؛ PDF بتوقيع `%PDF-`؛ SVG/HTML/التنفيذي مرفوض؛ حد الحجم `PAYMENTS_PROOF_MAX_KB`.
- الاسم عشوائي يولّده الخادم (ULID) وتحت `payment-proofs/Y/m/`؛ اسم المستخدم للملف لا يدخل المسار.
- التنزيل عبر متحكم مخوَّل فقط: المالك لإثباته، والمراجع (`sponsorships.confirm`) للجميع مع **تدقيق كل تنزيل** (`payment_proof_downloaded`)؛ الاستجابة مرفق خامل + `nosniff` + `CSP sandbox` + `no-store`.
- بصمة SHA-256 لكشف **إعادة استخدام الإثبات نفسه** بين طلبات (عمود «إثبات مكرر» في الطابور).
- استبدال الإثبات يحذف القديم بعد الـcommit؛ بعد الحالة النهائية الإثبات ثابت.

## المطابقة المالية (`sponsorship:reconcile-payments`)

**قراءة فقط دائمًا** (`--dry-run` للتوضيح؛ لا إصلاح آلي). يخرج بـ`0` نظيفًا و`1` عند وجود اختلاف. المخرجات: معرّفات علنية (ULID) ورموز مرجع وأكواد فقط — لا أسماء ولا بريد ولا إثبات ولا ملاحظات ولا أسرار.

| الكود | المعنى |
|---|---|
| `verified_without_contribution` | طلب محقَّق بلا مساهمة |
| `contribution_not_confirmed` | طلب محقَّق ومساهمته غير مؤكَّدة |
| `amount_mismatch` / `currency_mismatch` | عدم تطابق المبلغ/العملة بين الطلب والمساهمة |
| `refund_state_mismatch` | مسترد في طرف ومؤكَّد في الآخر |
| `orphan_materialized_contribution` | مساهمة مجسَّدة من مرجع طلب لا يرتبط بها |
| `duplicate_provider_reference` / `duplicate_reference` | تكرار (يمنعه UNIQUE؛ الفحص دفاع في العمق) |

## الإشعارات (E15 — سجل مغلق)

أربعة أنواع جديدة: `support_request_received` · `support_action_required` · `support_rejected` · `support_refunded` (والتحقق يعيد استعمال `support_confirmed`). كلها بلا مبالغ ولا رموز مرجع ولا ملاحظات داخلية، وتحترم تعطيل الإشعارات والحساب المجمَّد، ومفاتيحها دلالية فلا تتكرر.

## لوحة الإدارة (مجموعة «الرعاية والدعم»)

- **طلبات الدعم والدفع** (`SponsorshipPaymentResource`): طابور بترقيم صفحات (20)، شارة بعدد «بانتظار التحقق»، مرشحات (الحالة/الطريقة/البرنامج/مجهول/إثبات)، عمود «إثبات مكرر»، إجراءات: تحقق (يطلب المعادل المحاسبي للعملة الأجنبية)، رفض (رمز + رسالة + ملاحظة داخلية)، طلب توضيح، إلغاء، استرداد، فتح الإثبات. بلا إنشاء/تعديل/حذف.
- **لوحة مؤشرات** (`PaymentStatsWidget` + `PaymentAnalytics`): استعلامات ثابتة العدد بكاش قصير.
- **إعدادات الدفع** (`PaymentSettingsPage`، `sponsorships.confirm` فقط): المستفيد/البنك/الآيبان/ملاحظات المرجع. `PaymentSettingsGuard` يرفض كلمات المرور وPIN وCVV والمفاتيح وأرقام البطاقات الصالحة (Luhn). التدقيق يسجّل المفاتيح المتغيّرة وبصمة قصيرة لا القيمة.

## الأوامر والجدولة

| الأمر | الوظيفة |
|---|---|
| `payments:expire-stale` (كل 15 دقيقة) | ينهي «بانتظار الدفع» المتأخر فقط (دفعات `expire_batch`). idempotent |
| `sponsorship:reconcile-payments [--json]` | مطابقة للقراءة فقط (يدويًا أو من المراقبة) |
| `payments:prune-proofs [--dry-run]` | تقليم ملفات الإثبات بعد الاحتفاظ ونافذة النزاع. **لا يحذف سجل الطلب ولا يقلّم طلبًا مفتوحًا.** غير مجدول افتراضيًا: شغّله بوعي |

## الأمان والحدود

- المسارات خلف `auth + verified + account.active`؛ الكتابة بـCSRF؛ محدِّدات: `support-read` 120/د · `support-checkout` 10/د و40/س · `support-submit` 10/د · `support-proof-upload` 5/د و20/س · `payment-status` 60/د · `payment-webhook` 120/د.
- الوصول للطلب بالملكية **داخل الاستعلام** (ULID فقط؛ المعرّف التسلسلي غير قابل للتوجيه): غير المالك — حتى المدير — يحصل على 404 متطابق؛ المراجع يعمل من لوحة الإدارة.
- سقف الطلبات المفتوحة لكل مستخدم (`PAYMENTS_MAX_OPEN_REQUESTS`، حدّ ناعم قد يُتجاوز بسباق نادر ولا يمسّ المال).
- نصوص المستخدم (اسم/ملاحظة/رسالة) نص عادي بلا وسوم (`NoMarkup`) ومهرَّبة عند العرض؛ لا `{!!` في أي واجهة.
- لا يُسجَّل في السجلات/التدقيق: بريد، مسار إثبات، بصمة إثبات، ملاحظة المراجع، أجسام webhook، أسرار.

## الأداء

استعلامات ثابتة العدد مهما كثرت الطلبات/البرامج (صفحة الدعم تُحدّ بـ24 بطاقة؛ الصفحة العامة تستعمل `programFundingMany`؛ السجل 15/صفحة بتحميل مسبق للبرنامج؛ الطابور بتحديد أعمدة وعدّ مكرّر الإثبات في استعلام فرعي واحد). الفهارس: الحالة+التاريخ، البرنامج+الحالة، المستخدم+التاريخ، الحالة+الانتهاء، بصمة الإثبات، ملف الداعم (أسماؤها ≤ 64 حرفًا؛ لا فهرس زائد).

## MySQL

الجدول والقيود portable (لا JSON/floats). الاختبارات التشغيلية الحقيقية (قفل صفوف بجلستين، تكرار UNIQUE بالتزامن، RESTRICT) في `E32ConcurrencyRewardTest` وتُتخطى بوضوح على SQLite؛ **يلزم تشغيلها محليًا على MySQL**: `php vendor/bin/pest -c phpunit.mysql.xml.dist tests/Feature/PaymentsE32` (وأوامر E31 في [mysql-production-e31.md](mysql-production-e31.md)). حتى ذلك: **USER-LOCAL MYSQL VALIDATION REQUIRED**.

## بيانات Demo

`php artisan db:seed --class=DemoE32Seeder` — اختياري، يرفض الإنتاج، idempotent، بيانات وهمية بالكامل (طلب معلّق، مُرسَل بإثبات، محقَّق، مرفوض، مجهول محقَّق، مسترد).

## ما لم يُنفَّذ عمدًا (تأجيل)

| البند | السبب / الأولوية |
|---|---|
| مزوّدون إلكترونيون (Stripe/PayPal/…) وبطاقات | خارج النطاق؛ البنية جاهزة (العقد أعلاه) |
| دفع الضيف بلا حساب | P1: يحتاج بريدًا مشفّرًا ومسار تحقق وإثبات ملكية |
| فصل صلاحية الاسترداد عن التأكيد | P1: يمس E30 والأدوار |
| جدول `payment_events` | يُضاف مع أول مزوّد إلكتروني |
| استرداد/سحب مكافآت اللاعبين | ممنوع بالتصميم (لقطات تاريخية) |
| سعر صرف حيّ | ممنوع؛ المعادل المحاسبي يدوي |
| KYC / مكافحة غسل الأموال | قرار قانوني/تشغيلي خارج الكود |
| تذكير آلي بالطلبات المنتهية | غير مطلوب؛ المستخدم ينشئ طلبًا جديدًا |

## الاختبارات

`tests/Feature/PaymentsE32/` — تشغيل: `php vendor/bin/pest tests/Feature/PaymentsE32` (مسار صريح فقط؛ لا حلقات تكرار كبيرة). يغطي: الدفع، الملكية، الإثبات، التحقق الذرّي، عزل غير المحقَّق، الرفض/الاسترداد، المجهول، عقد المزوّد (مزوّد وهمي)، المطابقة، الانتهاء، التزامن/MySQL، سلامة المكافآت، الخصوصية، الأداء، لوحة الإدارة، والحراس الثابتة.
`````

### `resources/views/filament/pages/payment-settings-page.blade.php`

`sha256: ff83c6cb32b41139c687e8919fdb3ca7b0d2e250750b14138d467ba090cd2f85`

`````blade
<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex justify-end">
            <x-filament::button type="submit" icon="heroicon-o-check">
                حفظ إعدادات الدفع
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
`````

### `resources/views/support/_progress.blade.php`

`sha256: 75c1ad6a8506d98c576c700fa3bfd63a932da6d961d6acd12942492e58b20f4a`

`````blade
{{-- E32: تقدّم هدف التمويل (يُعرض فقط إن فعّل البرنامج show_goal_progress). المبالغ الدقيقة فقط إن فعّل show_public_amounts. أرقام مؤكَّدة فقط. --}}
@if ($card['progress'] !== null)
    <div>
        <div class="flex items-center justify-between text-xs font-bold text-slate-400 mb-1">
            <span>تقدّم الهدف</span>
            <span class="text-gold">{{ $card['progress'] }}٪</span>
        </div>
        <div class="h-2 rounded-full bg-white/5 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $card['progress'] }}" aria-label="تقدّم هدف التمويل">
            <div class="h-full bg-gradient-to-l from-amethyst to-gold" style="width: {{ $card['progress'] }}%"></div>
        </div>
        @if ($program->show_public_amounts)
            <p class="text-xs text-slate-400 mt-1">{{ \App\Services\Sponsorship\SponsorshipMoney::format($card['funding'], $program->accounting_currency) }} من {{ \App\Services\Sponsorship\SponsorshipMoney::format((int) $program->goal_amount_minor, $program->accounting_currency) }}</p>
        @endif
    </div>
@endif
`````

### `resources/views/support/_status.blade.php`

`sha256: 42d2878d8f396e200caa5320527023d0d0e63d16dfe8f4216653fd0f669c0dcb`

`````blade
{{-- E32: شارة حالة الطلب. لا نعتمد اللون وحده (النص دائمًا). «تم التحقق» فقط بعد تحقق الفريق؛ قبلها لا ندّعي نجاح الدفع أبدًا. --}}
@php
    $tones = ['awaiting_payment' => 'warning', 'submitted' => 'primary', 'verified' => 'success', 'rejected' => 'danger', 'cancelled' => 'neutral', 'expired' => 'neutral', 'refunded' => 'neutral'];
@endphp
<x-status-badge :tone="$tones[$payment->status] ?? 'neutral'" :small="$small ?? false">{{ $payment->statusLabel() }}</x-status-badge>
`````

### `resources/views/support/checkout.blade.php`

`sha256: a5b6c2f375573b885b7b575110f19448be7ce7578b2e6943346ed4dd589da322`

`````blade
@extends('layouts.app')

@section('title', 'ادعم: '.$program->title)
@section('content_width', 'max-w-2xl')

{{--
    E32: دفع بثلاث خطوات (Blade + Alpine بلا SPA): 1) المبلغ والعملة · 2) طريقة الدفع والخصوصية · 3) التأكيد والتعليمات.
    الخادم يعيد التحقق من كل شيء (المبلغ/العملة/الطريقة/البرنامج من المسار)؛ ما يُعرض هنا للراحة فقط. بلا JavaScript تظهر الخطوات كلها متتابعة وتعمل.
    مفتاح عدم التكرار (request_key) يمنع إنشاء طلبين بنقرة مزدوجة. لا بيانات دفع هنا؛ التعليمات تظهر بعد إنشاء الطلب فقط.
--}}
@section('content')
    <div class="space-y-6">
        <x-page-header :title="'ادعم: '.$program->title" icon="gift" :back="route('support.program', $program->slug)" backLabel="البرنامج" class="anim-fade-up !mb-0" />

        @if (! $enabled)
            <x-empty-state icon="gift" title="الدفع غير متاح حاليًا" message="لا توجد طريقة دفع مفعّلة الآن. حاول لاحقًا." />
        @else
            <form method="POST" action="{{ route('support.checkout.store', $program->slug) }}" class="puzzle-card !p-6 md:!p-8 space-y-6 anim-fade-up"
                  x-data="{ step: 1, amount: @js(old('amount', '')), currency: @js($defaultCurrency), visibility: @js(old('visibility', 'public')), provider: @js(old('provider', array_key_first($methods))), methods: @js($methods),
                            get methodLabel() { return this.methods[this.provider]?.label ?? '' },
                            get currencyList() { return this.methods[this.provider]?.currencies ?? [] },
                            next() { if (this.step === 1 && !String(this.amount).trim()) { this.$refs.amount.focus(); return } this.step = Math.min(3, this.step + 1) },
                            prev() { this.step = Math.max(1, this.step - 1) },
                            submitting: false }"
                  x-on:submit="submitting = true">
                @csrf
                <input type="hidden" name="request_key" value="{{ $requestKey }}">

                <ol class="flex items-center gap-2 text-xs font-bold" aria-label="خطوات الدفع">
                    <li :class="step >= 1 ? 'text-amethyst' : 'text-slate-500'">١ المبلغ</li>
                    <li aria-hidden="true" class="text-slate-600">‹</li>
                    <li :class="step >= 2 ? 'text-amethyst' : 'text-slate-500'">٢ الطريقة والخصوصية</li>
                    <li aria-hidden="true" class="text-slate-600">‹</li>
                    <li :class="step >= 3 ? 'text-amethyst' : 'text-slate-500'">٣ التأكيد</li>
                </ol>

                {{-- الخطوة 1: المبلغ والعملة --}}
                <section x-show="step === 1" aria-labelledby="step1-title" class="space-y-4">
                    <h2 id="step1-title" class="font-display font-black text-white">المبلغ والعملة</h2>

                    @if ($suggestions !== [])
                        <div class="flex flex-wrap gap-2" role="group" aria-label="مبالغ مقترحة">
                            @foreach ($suggestions as $s)
                                <button type="button" class="chip !py-1.5 !px-3 text-xs" x-on:click="amount = '{{ $s['input'] }}'" :class="String(amount) === '{{ $s['input'] }}' ? 'chip-active' : ''">{{ $s['label'] }}</button>
                            @endforeach
                        </div>
                    @endif

                    <div class="grid grid-cols-3 gap-3">
                        <div class="col-span-2">
                            <label for="amount" class="block text-sm font-bold text-slate-300 mb-2">المبلغ</label>
                            <input id="amount" x-ref="amount" type="text" inputmode="decimal" autocomplete="off" name="amount" x-model="amount" required maxlength="20"
                                   @unless ($allowCustom) readonly @endunless class="input-gem" placeholder="مثال: 25">
                            @if ($minLabel)<span class="text-xs text-slate-500 mt-1.5 block">الحد الأدنى: {{ $minLabel }}</span>@endif
                        </div>
                        <div>
                            <label for="currency" class="block text-sm font-bold text-slate-300 mb-2">العملة</label>
                            <select id="currency" name="currency" x-model="currency" required class="input-gem">
                                @foreach ($currencies as $code)
                                    <option value="{{ $code }}" @selected($defaultCurrency === $code)>{{ $currencyOptions[$code] ?? $code }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-end"><button type="button" class="btn-gem" x-on:click="next()">التالي</button></div>
                </section>

                {{-- الخطوة 2: طريقة الدفع والخصوصية --}}
                <section x-show="step === 2" x-cloak aria-labelledby="step2-title" class="space-y-4">
                    <h2 id="step2-title" class="font-display font-black text-white">طريقة الدفع والخصوصية</h2>

                    <fieldset class="space-y-2">
                        <legend class="text-sm font-bold text-slate-300 mb-1">طريقة الدفع</legend>
                        @foreach ($methods as $key => $method)
                            <label class="flex items-center gap-3 glass rounded-xl px-4 py-3 cursor-pointer">
                                <input type="radio" name="provider" value="{{ $key }}" x-model="provider" @checked(old('provider', array_key_first($methods)) === $key)>
                                <span class="font-bold text-white">{{ $method['label'] }}</span>
                            </label>
                        @endforeach
                    </fieldset>

                    <fieldset class="space-y-2">
                        <legend class="text-sm font-bold text-slate-300 mb-1">كيف تريد أن يظهر دعمك؟</legend>
                        <label class="flex items-center gap-3 glass rounded-xl px-4 py-3 cursor-pointer">
                            <input type="radio" name="visibility" value="public" x-model="visibility" @checked(old('visibility', 'public') === 'public')>
                            <span class="font-bold text-white">أظهر اسمي علنًا</span>
                        </label>
                        <label class="flex items-center gap-3 glass rounded-xl px-4 py-3 cursor-pointer">
                            <input type="radio" name="visibility" value="anonymous" x-model="visibility" @checked(old('visibility') === 'anonymous')>
                            <span class="font-bold text-white">أدعم بشكل مجهول</span>
                        </label>
                        <p class="text-xs text-slate-500">الدعم المجهول لا يظهر اسمك في أي صفحة عامة. تحتفظ الإدارة بهوية الداعم داخليًا للتحقق فقط.</p>
                    </fieldset>

                    <div x-show="visibility === 'public'">
                        <label for="display_name" class="block text-sm font-bold text-slate-300 mb-2">الاسم المعروض</label>
                        <input id="display_name" type="text" name="display_name" maxlength="{{ (int) config('payments.checkout.display_name_max', 80) }}" value="{{ old('display_name', $defaultName) }}" class="input-gem">
                    </div>

                    <div class="flex justify-between"><button type="button" class="btn-secondary" x-on:click="prev()">السابق</button><button type="button" class="btn-gem" x-on:click="next()">التالي</button></div>
                </section>

                {{-- الخطوة 3: التأكيد --}}
                <section x-show="step === 3" x-cloak aria-labelledby="step3-title" class="space-y-4">
                    <h2 id="step3-title" class="font-display font-black text-white">التأكيد والتعليمات</h2>
                    <dl class="glass rounded-xl p-4 text-sm space-y-2">
                        <div class="flex justify-between gap-3"><dt class="text-slate-400">البرنامج</dt><dd class="font-bold text-white text-end break-words">{{ $program->title }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-400">المبلغ</dt><dd class="font-bold text-white" x-text="(amount || '—') + ' ' + currency"></dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-400">الطريقة</dt><dd class="font-bold text-white" x-text="methodLabel"></dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-400">الظهور</dt><dd class="font-bold text-white" x-text="visibility === 'anonymous' ? 'مجهول' : 'باسمي'"></dd></div>
                    </dl>
                    <p class="text-xs text-slate-400">سيُنشأ طلب دعم برمز مرجع. ستظهر لك تعليمات التحويل في الصفحة التالية. <strong class="text-white">لا يُحتسب الدعم قبل تحقق الفريق من التحويل.</strong></p>
                    <div class="flex justify-between">
                        <button type="button" class="btn-secondary" x-on:click="prev()">السابق</button>
                        <button type="submit" class="btn-gem" :disabled="submitting">إنشاء طلب الدعم</button>
                    </div>
                </section>
            </form>
        @endif
    </div>
@endsection
`````

### `resources/views/support/history.blade.php`

`sha256: a91c2b336d65ccf826235ed18fbaf82592ed8db1e54d84df2ec6bda3ce39a473`

`````blade
@extends('layouts.app')

@section('title', 'طلبات دعمي')
@section('content_width', 'max-w-4xl')

{{-- E32: سجل طلبات الدعم للمستخدم الحالي فقط (ترقيم 15). لا ملاحظات إدارية ولا تفاصيل معادل محاسبي ولا مراجع داخلية. --}}
@section('content')
    <div class="space-y-6">
        <x-page-header title="طلبات دعمي" icon="wallet" :back="route('support.index')" backLabel="ادعم المنصة" class="anim-fade-up !mb-0" />

        @if ($payments->isEmpty())
            <x-empty-state icon="gift" title="لا طلبات دعم بعد" message="عند إنشاء طلب دعم سيظهر هنا مع حالته." />
        @else
            <div class="grid gap-2 anim-fade-up">
                @foreach ($payments as $payment)
                    <a href="{{ route('support.payments.show', ['payment' => $payment->public_id]) }}" class="glass rounded-2xl px-4 py-3 flex flex-wrap items-center gap-3 hover:bg-white/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                        <span class="font-mono font-black text-white" dir="ltr">{{ $payment->reference }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm text-slate-300">{{ $payment->program?->title }}</span>
                        <span class="font-black text-white">{{ $payment->amountLabel() }}</span>
                        <span class="text-xs text-slate-400">{{ $payment->is_anonymous ? 'مجهول' : 'باسمي' }}</span>
                        <span class="text-xs text-slate-400">{{ $payment->created_at?->format('Y-m-d') }}</span>
                        @include('support._status', ['small' => true])
                    </a>
                @endforeach
            </div>
            {{ $payments->links() }}
        @endif
    </div>
@endsection
`````

### `resources/views/support/index.blade.php`

`sha256: 8462bcbb86f545c653663d38ebeea2e4f53e2dca36ba3adfbc7eb5284f1d0207`

`````blade
@extends('layouts.app')

@section('title', 'ادعم المنصة')
@section('content_width', 'max-w-5xl')

{{--
    E32: مدخل الدعم العام. يعرض البرامج النشطة الآن فقط. أرقام التمويل/التعزيز مؤكَّدة فقط (الطلبات المعلّقة لا تظهر في أي رقم). الراعي المجهول لا يظهر.
    الدعم طوعي ولا يمنح أي أفضلية في اللعب أو المكافآت الشخصية. كل النصوص مهرّبة.
--}}
@section('content')
    <div class="space-y-8">
        <x-page-header title="ادعم المنصة" icon="gift" subtitle="دعمك يموّل المكافآت والفعاليات الرسمية ويُعزّز جوائز اللاعبين. الدعم طوعي ولا يمنحك أي أفضلية في اللعب." class="anim-fade-up !mb-0" />

        @auth
            <div class="flex flex-wrap gap-2 anim-fade-up">
                <a href="{{ route('support.history') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="wallet" class="w-4 h-4" />طلبات دعمي</a>
                <a href="{{ route('supporters.index') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="trophy" class="w-4 h-4" />لوحة الداعمين</a>
            </div>
        @endauth

        @if (! $enabled)
            <x-empty-state icon="gift" title="الدعم متوقف مؤقتًا" message="لا يمكن إنشاء طلبات دعم جديدة الآن. شكرًا لتفهّمك." />
        @elseif ($programs->isEmpty())
            <x-empty-state icon="gift" title="لا برامج دعم نشطة حاليًا" message="ستظهر هنا برامج الرعاية عند تفعيلها." />
        @else
            <section aria-labelledby="programs-title" class="space-y-4 anim-fade-up">
                <h2 id="programs-title" class="sr-only">برامج الدعم</h2>
                <div class="grid sm:grid-cols-2 gap-4">
                    @foreach ($programs as $program)
                        @php($card = $cards[$program->id])
                        <article class="puzzle-card !p-5 space-y-3" data-program="{{ $program->slug }}">
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="font-display font-black text-white break-words min-w-0">{{ $program->title }}</h3>
                                <x-status-badge tone="success" small>نشط</x-status-badge>
                            </div>
                            <p class="text-xs text-slate-400">{{ $targets->typeLabel($program->target_type) }}</p>
                            @if (filled($program->description))
                                <p class="text-sm text-slate-300 break-words">{{ \Illuminate\Support\Str::limit($program->description, 160) }}</p>
                            @endif
                            @include('support._progress')
                            @if ($card['boost_bps'] > 0)
                                <p class="text-xs font-bold text-emerald">تعزيز المكافآت الرسمية الحالي: {{ rtrim(rtrim(number_format($card['boost_bps'] / 100, 2), '0'), '.') }}٪</p>
                            @endif
                            @if ($card['main'])
                                <p class="text-xs text-slate-400">الراعي الرئيسي: <a href="{{ route('supporters.show', $card['main']['slug']) }}" class="font-bold text-amethyst hover:underline">{{ $card['main']['name'] }}</a></p>
                            @endif
                            <div class="flex flex-wrap gap-2 pt-1">
                                <a href="{{ route('support.program', $program->slug) }}" class="btn-secondary">التفاصيل</a>
                                <a href="{{ route('support.checkout', $program->slug) }}" class="btn-gem">ادعم هذا البرنامج</a>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <p class="text-xs text-slate-500 anim-fade-up">لا نطلب بيانات بطاقات ولا كلمات مرور بنكية أبدًا. الدعم لا يُحتسب إلا بعد تحقق الفريق من التحويل.</p>
    </div>
@endsection
`````

### `resources/views/support/payment.blade.php`

`sha256: 0bdb8b5173198820a129ce155e77b6a516b7c16b21ca344bab19cdd0df549720`

`````blade
@extends('layouts.app')

@section('title', 'طلب الدعم '.$payment->reference)
@section('content_width', 'max-w-2xl')

{{--
    E32: صفحة طلب الدعم لصاحبه فقط. لا نعرض «تم الدفع/نجح» قبل التحقق: الحالة المرسَلة = «طلب الدعم بانتظار التحقق».
    تعليمات التحويل (من إعدادات المنصة) تظهر للطلب المفتوح فقط. لا تُعرض ملاحظات المراجع ولا رموز الرفض ولا مسار الإثبات. كل النصوص مهرّبة.
--}}
@section('content')
    <div class="space-y-6">
        <x-page-header :title="'طلب دعم '.$payment->reference" icon="gift" :back="route('support.history')" backLabel="طلبات دعمي" class="anim-fade-up !mb-0" />

        <section class="puzzle-card !p-6 space-y-4 anim-fade-up" aria-labelledby="summary-title">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="summary-title" class="font-display font-black text-white">ملخّص الطلب</h2>
                @include('support._status')
            </div>

            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-slate-400">رمز المرجع</dt><dd class="font-mono font-black text-white select-all" dir="ltr">{{ $payment->reference }}</dd></div>
                <div><dt class="text-slate-400">المبلغ</dt><dd class="font-black text-white">{{ $payment->amountLabel() }}</dd></div>
                <div class="col-span-2"><dt class="text-slate-400">البرنامج</dt><dd class="font-bold text-white break-words">{{ $payment->program?->title }}</dd></div>
                <div><dt class="text-slate-400">الظهور</dt><dd class="font-bold text-white">{{ $payment->is_anonymous ? 'مجهول' : 'باسمي' }}</dd></div>
                <div><dt class="text-slate-400">أُنشئ</dt><dd class="font-bold text-white">{{ $payment->created_at?->format('Y-m-d H:i') }}</dd></div>
            </dl>

            @if (filled($payment->user_message))
                <div class="rounded-xl border border-gold/30 bg-gold/10 text-gold px-4 py-3 text-sm font-bold">
                    <span class="block text-xs text-slate-300 mb-1">رسالة من الفريق</span>{{ $payment->user_message }}
                </div>
            @endif

            @switch($payment->status)
                @case('awaiting_payment')
                    <p class="text-sm text-slate-300">أتمم التحويل خارج المنصة ثم اضغط «حوّلت المبلغ». @if ($payment->expires_at)ينتهي الطلب تلقائيًا في <strong class="text-white">{{ $payment->expires_at->format('Y-m-d H:i') }}</strong> إن لم يُرسَل.@endif</p>
                    @break
                @case('submitted')
                    <div class="rounded-xl border border-amethyst/30 bg-amethyst/10 px-4 py-3 text-sm font-bold text-white" role="status">طلب الدعم بانتظار التحقق. سنُعلمك فور مراجعته؛ لا يُحتسب الدعم قبل ذلك.</div>
                    @break
                @case('verified')
                    <div class="rounded-xl border border-emerald/30 bg-emerald/10 px-4 py-3 text-sm font-bold text-emerald" role="status">تم التحقق وتأكيد دعمك. شكرًا لك! يظهر مستواك ضمن «الداعمون» (إلا إن اخترت الدعم المجهول).</div>
                    @break
                @case('rejected')
                    <p class="text-sm text-slate-300">لم يُقبل هذا الطلب. يمكنك إنشاء طلب جديد متى شئت.</p>
                    @break
                @case('refunded')
                    <p class="text-sm text-slate-300">سُجّل استرداد هذا الدعم. لا تُسحب مكافآت اللاعبين التي مُنحت سابقًا.</p>
                    @break
            @endswitch
        </section>

        @if ($instructions)
            <section class="puzzle-card !p-6 space-y-4 anim-fade-up" aria-labelledby="how-title">
                <h2 id="how-title" class="font-display font-black text-white">{{ $instructions['title'] }}</h2>

                @if (! $instructions['configured'])
                    <p class="text-sm text-slate-300">لم تُضبط بيانات التحويل بعد. تواصل مع الإدارة قبل التحويل.</p>
                @else
                    <ol class="list-decimal ps-5 space-y-1 text-sm text-slate-300">
                        <li>حوّل مبلغ <strong class="text-white">{{ $instructions['amount'] }}</strong>.</li>
                        <li>اكتب رمز المرجع <strong class="font-mono text-white" dir="ltr">{{ $instructions['reference'] }}</strong> في ملاحظة التحويل.</li>
                        <li>أرفق إثبات التحويل (صورة أو PDF) ثم اضغط «حوّلت المبلغ».</li>
                        <li>انتظر تحقق الفريق. بعد الموافقة يتحدّث مستواك ضمن الداعمين.</li>
                    </ol>
                    <dl class="glass rounded-xl p-4 text-sm space-y-2">
                        @if ($instructions['beneficiary'])<div class="flex justify-between gap-3"><dt class="text-slate-400">المستفيد</dt><dd class="font-bold text-white text-end break-words">{{ $instructions['beneficiary'] }}</dd></div>@endif
                        @if ($instructions['bank_name'])<div class="flex justify-between gap-3"><dt class="text-slate-400">البنك</dt><dd class="font-bold text-white text-end break-words">{{ $instructions['bank_name'] }}</dd></div>@endif
                        @if ($instructions['account'])<div><dt class="text-slate-400">الحساب / الآيبان</dt><dd class="font-mono font-bold text-white break-all whitespace-pre-line select-all" dir="ltr">{{ $instructions['account'] }}</dd></div>@endif
                    </dl>
                    @if ($instructions['reference_note'])<p class="text-xs text-slate-400 whitespace-pre-line">{{ $instructions['reference_note'] }}</p>@endif
                    @if ($instructions['extra_note'])<p class="text-xs text-slate-400 whitespace-pre-line">{{ $instructions['extra_note'] }}</p>@endif
                @endif
            </section>
        @endif

        @if ($payment->isOpen())
            <section class="puzzle-card !p-6 space-y-4 anim-fade-up" aria-labelledby="send-title">
                <h2 id="send-title" class="font-display font-black text-white">{{ $payment->status === 'submitted' ? 'إرسال إثبات إضافي' : 'إرسال التحويل' }}</h2>

                @if ($proofAvailable)
                    <p class="text-sm text-slate-300">إثبات مرفوع: <a class="font-bold text-amethyst hover:underline" href="{{ route('support.payments.proof', ['payment' => $payment->public_id]) }}">تنزيل ({{ strtoupper(pathinfo((string) $payment->proof_path, PATHINFO_EXTENSION)) }})</a></p>
                @endif

                @if ($payment->status === 'awaiting_payment')
                    <form method="POST" action="{{ route('support.payments.transferred', ['payment' => $payment->public_id]) }}" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        <div>
                            <label for="payer_note" class="block text-sm font-bold text-slate-300 mb-2">ملاحظة (اختياري): اسم المحوِّل / تاريخ التحويل</label>
                            <input id="payer_note" type="text" name="payer_note" maxlength="{{ (int) config('payments.checkout.note_max', 255) }}" value="{{ old('payer_note', $payment->payer_note) }}" class="input-gem">
                        </div>
                        <div>
                            <label for="proof" class="block text-sm font-bold text-slate-300 mb-2">إثبات التحويل (JPEG / PNG / WEBP / PDF، حتى {{ number_format((int) config('payments.proof.max_kb', 5120) / 1024, 0) }} م.ب)</label>
                            <input id="proof" type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf" class="input-gem">
                        </div>
                        <button type="submit" class="btn-gem w-full justify-center">حوّلت المبلغ</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('support.payments.proof.upload', ['payment' => $payment->public_id]) }}" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        <div>
                            <label for="proof" class="block text-sm font-bold text-slate-300 mb-2">استبدال الإثبات</label>
                            <input id="proof" type="file" name="proof" required accept="image/jpeg,image/png,image/webp,application/pdf" class="input-gem">
                        </div>
                        <button type="submit" class="btn-secondary">رفع الإثبات</button>
                    </form>
                @endif

                @if ($payment->status === 'awaiting_payment')
                    <form method="POST" action="{{ route('support.payments.cancel', ['payment' => $payment->public_id]) }}" onsubmit="return confirm('إلغاء طلب الدعم؟')">
                        @csrf
                        <button type="submit" class="btn-danger">إلغاء الطلب</button>
                    </form>
                @endif
            </section>
        @elseif ($proofAvailable)
            <p class="text-xs text-slate-500">الإثبات محفوظ بشكل خاص ولا يراه إلا المراجع المالي وأنت.</p>
        @endif
    </div>
@endsection
`````

### `resources/views/support/program.blade.php`

`sha256: c67a96d82697b7b92f7e64b4201fad95e0875ceda774c94cd0a7977787fe9b36`

`````blade
@extends('layouts.app')

@section('title', $program->title)
@section('content_width', 'max-w-3xl')

{{-- E32: صفحة برنامج الدعم. أرقام مؤكَّدة فقط، لا بيانات دفع، الراعي المجهول لا يظهر. زر «ادعم» يقود لصفحة الدفع (تسجيل دخول مطلوب). --}}
@section('content')
    <div class="space-y-6">
        <x-page-header :title="$program->title" icon="gift" :back="route('support.index')" backLabel="ادعم المنصة" class="anim-fade-up !mb-0" />

        <article class="puzzle-card !p-6 space-y-4 anim-fade-up" data-program="{{ $program->slug }}">
            <div class="flex flex-wrap items-center gap-2">
                <x-status-badge tone="success">نشط</x-status-badge>
                <span class="text-xs text-slate-400">{{ $targetLabel }}</span>
            </div>

            @if (filled($program->description))
                <p class="text-sm text-slate-300 break-words whitespace-pre-line">{{ $program->description }}</p>
            @endif

            @if ($targetUrl)
                <a href="{{ $targetUrl }}" class="text-xs font-bold text-amethyst hover:underline">صفحة الهدف</a>
            @endif

            @include('support._progress')

            @if ($card['boost_bps'] > 0)
                <p class="text-sm font-bold text-emerald">تعزيز المكافآت الرسمية الحالي: {{ rtrim(rtrim(number_format($card['boost_bps'] / 100, 2), '0'), '.') }}٪</p>
            @endif

            @if ($card['main'])
                <p class="text-sm text-slate-300">الراعي الرئيسي: <a href="{{ route('supporters.show', $card['main']['slug']) }}" class="font-bold text-amethyst hover:underline">{{ $card['main']['name'] }}</a></p>
            @endif

            @if ($enabled)
                <div class="pt-2 flex flex-wrap gap-2">
                    <a href="{{ route('support.checkout', $program->slug) }}" class="btn-gem">ادعم هذا البرنامج</a>
                    <a href="{{ route('supporters.index', ['scope' => 'program', 'program' => $program->slug]) }}" class="btn-secondary">داعمو البرنامج</a>
                </div>
            @endif
        </article>

        <p class="text-xs text-slate-500">الدعم طوعي ولا يمنحك أي أفضلية في اللعب. لا يُحتسب إلا بعد تحقق الفريق من التحويل.</p>
    </div>
@endsection
`````

### `routes/payments.php`

`sha256: 9fccab618e2c901e7d46ebc403a724e20d4beb68ec42387598b86919ec482f30`

`````php
<?php

use App\Http\Controllers\Support\CheckoutController;
use App\Http\Controllers\Support\PaymentController;
use App\Http\Controllers\Support\StaffProofController;
use App\Http\Controllers\Support\SupportController;
use Illuminate\Support\Facades\Route;

/*
| E32 - مسارات الدعم والدفع (تُضمَّن من routes/web.php بمجموعة web: الجلسة وCSRF مفعّلان لكل مسار كتابة).
| القراءة العامة (/support, /support/{program}) بلا حساب؛ كل ما يخصّ الطلبات والمال خلف auth + verified + account.active.
| الطلب يُحَلّ بالملكية داخل الاستعلام (404 لغير المالك). لا مسار هنا يؤكّد أو يمنح أو يستردّ: ذلك من لوحة الإدارة عبر SponsorshipPaymentService حصرًا.
| الأسبقية: المسارات الثابتة (history, payments/…) قبل {program} كي لا يلتقطها وسيط البرنامج.
*/

Route::middleware('throttle:support-read')->group(function () {
    Route::get('/support', [SupportController::class, 'index'])->name('support.index');
});

Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::get('/support/history', [PaymentController::class, 'history'])->middleware('throttle:payment-status')->name('support.history');

    Route::prefix('/support/payments/{payment}')->name('support.payments.')->group(function () {
        Route::get('/', [PaymentController::class, 'show'])->middleware('throttle:payment-status')->name('show');
        Route::get('/proof', [PaymentController::class, 'proof'])->middleware('throttle:payment-status')->name('proof');
        Route::post('/transferred', [PaymentController::class, 'transferred'])->middleware(['throttle:support-submit', 'throttle:support-proof-upload'])->name('transferred');
        Route::post('/proof', [PaymentController::class, 'uploadProof'])->middleware('throttle:support-proof-upload')->name('proof.upload');
        Route::post('/cancel', [PaymentController::class, 'cancel'])->middleware('throttle:support-submit')->name('cancel');
    });

    // مراجع مالي: تنزيل الإثبات (سياسة downloadProof ⇒ sponsorships.confirm) + تدقيق كل تنزيل.
    Route::get('/payments/staff/{payment:public_id}/proof', StaffProofController::class)->middleware('throttle:payment-status')->name('payments.staff.proof');

    Route::get('/support/{program:slug}/checkout', [CheckoutController::class, 'create'])->middleware('throttle:support-read')->name('support.checkout');
    Route::post('/support/{program:slug}/checkout', [CheckoutController::class, 'store'])->middleware('throttle:support-checkout')->name('support.checkout.store');
});

Route::middleware('throttle:support-read')->group(function () {
    Route::get('/support/{program:slug}', [SupportController::class, 'show'])->name('support.program');
});
`````

### `routes/payments_webhooks.php`

`sha256: 78821a026e6a07e4ff1de00d8310133c200c1b231bd82385b4df27da0ed2ef79`

`````php
<?php

use App\Http\Controllers\Support\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
| E32 - Webhook عام لمزوّدين إلكترونيين مستقبليين. يُسجَّل في bootstrap/app.php بمجموعة «api» (بلا جلسة ولا كوكيز ولا CSRF بطبيعتها، لا استثناء من وسيط) وبحدّ معدّل payment-webhook.
| المسار مغلق افتراضيًا (PAYMENTS_WEBHOOKS_ENABLED=false) ويرفض كل مزوّد غير معروف/معطَّل/لا يدعم webhook، ولا يعتمد حدثًا دون تحقق توقيع المزوّد نفسه. انظر PaymentWebhookController.
*/
Route::post('/api/payments/webhooks/{provider}', PaymentWebhookController::class)->where('provider', '[a-z0-9_]{1,32}')->middleware('throttle:payment-webhook')->name('payments.webhook');
`````

### `tests/Feature/PaymentsE32/E32AdminOpsTest.php`

`sha256: f36a0f014230b8df5f825f3b06c24672cc24d035a131296804d26e891f3c9d61`

`````php
<?php

use App\Filament\Pages\PaymentSettingsPage;
use App\Filament\Resources\SponsorshipPaymentResource;
use App\Filament\Resources\SponsorshipPaymentResource\Pages\ListSponsorshipPayments;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use App\Services\Database\FinancialHistoryGuard;
use App\Services\Notifications\NotificationType;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentRetentionService;
use App\Services\PlatformSettingsService;
use Database\Seeders\DemoE32Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E32 - لوحة الإدارة (طابور المراجعة المالي + إعدادات الدفع) بالصلاحيات، حراسة النموذج، الاحتفاظ بالإثباتات، البذرة، وسجل الإشعارات.
*/

beforeEach(fn () => e32_seed());

function e32_queue(?\App\Models\User $who = null)
{
    return Livewire::actingAs($who ?? e32_finance())->test(ListSponsorshipPayments::class);
}

// ملاحظة: كل اختبار هنا يستعمل مستخدم لوحة واحدًا في طلبات HTTP؛ تبديل مستخدم Filament داخل الاختبار نفسه بعد أول طلب يترك حارس الجلسة بحال غير قابلة للمقارنة (سلوك أداة الاختبار لا التطبيق).
test('E32-A1: the review queue is closed to guests and ordinary users', function () {
    $url = SponsorshipPaymentResource::getUrl('index');

    $this->get($url)->assertRedirect();
    expect($this->actingAs(e16User())->get($url)->getStatusCode())->toBeIn([403, 404]);
});

test('E32-A1b: a content manager (sponsorships.view) can open the review queue', function () {
    $this->actingAs(e32_manager())->get(SponsorshipPaymentResource::getUrl('index'))->assertOk();
});

test('E32-A1c: finance can open the review queue', function () {
    $this->actingAs(e32_finance())->get(SponsorshipPaymentResource::getUrl('index'))->assertOk();
});

test('E32-A2: a content manager sees the queue but every financial action is hidden and uncallable', function () {
    $payment = e32_submitted();
    $component = e32_queue(e32_manager())->assertCanSeeTableRecords([$payment]);

    foreach (['verify', 'reject', 'clarify', 'cancel', 'refund', 'proof'] as $action) {
        $component->assertTableActionHidden($action, $payment);
    }

    expect($payment->fresh()->status)->toBe('submitted');
});

test('E32-A3: finance verifies from the queue; a foreign currency requires the accounting equivalent in the form', function () {
    $program = e32_program();
    $usd = e32_submitted(null, $program, '20');
    e32_queue()->callTableAction('verify', $usd)->assertHasNoTableActionErrors();
    expect($usd->fresh()->status)->toBe('verified')->and(e32_funding($program))->toBe(2000);

    $user = e16User();
    $eur = e32_payments()->markTransferred($user, e32_request($user, $program, '10', false, 'EUR'), 'يورو');
    $component = e32_queue();
    $component->callTableAction('verify', $eur, data: [])->assertHasTableActionErrors(['accounting_amount' => 'required']);
    expect($eur->fresh()->status)->toBe('submitted');

    $component->callTableAction('verify', $eur, data: ['accounting_amount' => '11'])->assertHasNoTableActionErrors();
    expect($eur->fresh()->status)->toBe('verified')->and($eur->fresh()->accounting_amount_minor)->toBe(1100);
});

test('E32-A4: reject, clarify, cancel and refund actions work from the queue with required reasons', function () {
    $component = e32_queue();

    $rejected = e32_submitted();
    $component->callTableAction('reject', $rejected, data: [])->assertHasTableActionErrors(['code' => 'required']);
    $component->callTableAction('reject', $rejected, data: ['code' => 'not_received', 'message' => 'لم يصل', 'note' => 'ملاحظة داخلية'])->assertHasNoTableActionErrors();
    expect($rejected->fresh()->status)->toBe('rejected')->and($rejected->fresh()->review_note)->toBe('ملاحظة داخلية');

    $clarify = e32_submitted();
    $component->callTableAction('clarify', $clarify, data: ['reason' => 'ar'])->assertHasTableActionErrors(['reason']);
    $component->callTableAction('clarify', $clarify, data: ['reason' => 'أرسل إيصالًا أوضح'])->assertHasNoTableActionErrors();
    expect($clarify->fresh()->status)->toBe('awaiting_payment');

    $cancel = e32_submitted();
    $component->callTableAction('cancel', $cancel, data: ['reason' => 'طلب مكرر'])->assertHasNoTableActionErrors();
    expect($cancel->fresh()->status)->toBe('cancelled');

    $refund = e32_verified();
    $component->callTableAction('refund', $refund, data: ['reason' => 'استرداد من اللوحة'])->assertHasNoTableActionErrors();
    expect($refund->fresh()->status)->toBe('refunded')->and(e32_contribution($refund)->status)->toBe('refunded');
});

test('E32-A5: invalid actions show a friendly error instead of a 500 and change nothing (e.g. refunding an unverified payment is hidden)', function () {
    $payment = e32_submitted();

    e32_queue()->assertTableActionHidden('refund', $payment)->assertTableActionVisible('verify', $payment)->assertTableActionVisible('reject', $payment);
    e32_payments()->reject(e32_finance(), $payment, 'other');
    e32_queue()->assertTableActionHidden('verify', $payment->fresh())->assertTableActionHidden('reject', $payment->fresh());
});

test('E32-A6: the queue flags a reused proof (same hash on another request) and filters by status, anonymity and proof', function () {
    $a = e32_submitted();
    $b = e32_submitted(null, null, '10', true);
    $c = e32_submitted(null, null, '10', false, false);
    DB::table('sponsorship_payments')->where('id', $b->id)->update(['proof_sha256' => $a->fresh()->proof_sha256]);

    $component = e32_queue();
    $records = $component->instance()->getTableRecords();
    expect((int) $records->firstWhere('id', $a->id)->dup_proof)->toBe(1)->and((int) $records->firstWhere('id', $b->id)->dup_proof)->toBe(1)->and((int) $records->firstWhere('id', $c->id)->dup_proof)->toBe(0);

    $component->filterTable('is_anonymous', true)->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c]);
    e32_queue()->filterTable('has_proof', false)->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$a, $b]);
    e32_payments()->reject(e32_finance(), $a, 'duplicate');
    e32_queue()->filterTable('status', 'rejected')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c]);
});

test('E32-A7: the queue offers no create, edit or delete - financial history is append-only', function () {
    $payment = e32_verified();

    expect(SponsorshipPaymentResource::canCreate())->toBeFalse()->and(SponsorshipPaymentResource::canEdit($payment))->toBeFalse()->and(SponsorshipPaymentResource::canDelete($payment))->toBeFalse()
        ->and(array_keys(SponsorshipPaymentResource::getPages()))->toBe(['index']);
    expect(fn () => $payment->delete())->toThrow(PaymentException::class)->and(SponsorshipPayment::query()->count())->toBe(1);
    expect(\Illuminate\Support\Facades\Gate::forUser(e32_finance())->allows('delete', $payment))->toBeFalse();
});

test('E32-A8: the payment model protects immutable fields, transitions and the contribution link', function () {
    $payment = e32_submitted();

    foreach (['amount_minor' => 1, 'currency' => 'EUR', 'user_id' => 99999, 'reference' => 'SUP-HACKED22', 'provider' => 'x', 'is_anonymous' => true, 'sponsorship_program_id' => 99999] as $field => $value) {
        $model = SponsorshipPayment::query()->findOrFail($payment->id);
        expect(fn () => $model->forceFill([$field => $value])->save())->toThrow(PaymentException::class, 'محمية');
    }

    $rejected = e32_payments()->reject(e32_finance(), e32_submitted(), 'other');
    expect(fn () => SponsorshipPayment::query()->findOrFail($rejected->id)->forceFill(['status' => 'verified'])->save())->toThrow(PaymentException::class)
        ->and(fn () => SponsorshipPayment::query()->findOrFail($payment->id)->forceFill(['status' => 'refunded'])->save())->toThrow(PaymentException::class);
    expect(fn () => SponsorshipPayment::create(['amount_minor' => 5]))->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class);
});

test('E32-A9a: the payment settings page is closed to guests and to content managers', function () {
    $url = '/admin/payment-settings';

    $this->get($url)->assertRedirect();
    expect($this->actingAs(e32_manager())->get($url)->getStatusCode())->toBeIn([403, 404]);
});

test('E32-A9b: finance opens the settings page, persists the instructions and the audit lists changed keys without their values', function () {
    $this->actingAs(e32_finance())->get('/admin/payment-settings')->assertOk();

    Livewire::actingAs(e32_finance())->test(PaymentSettingsPage::class)
        ->fillForm(['manual_enabled' => true, 'manual_title' => 'حوالة بنكية', 'manual_beneficiary' => 'جمعية أحجيات', 'manual_account' => 'TR33 0006 1005 1978 6457 8413 26', 'allowed_currencies' => ['USD', 'EUR']])
        ->call('save')->assertHasNoFormErrors();

    $settings = app(PlatformSettingsService::class);
    expect($settings->get('payments', 'manual_beneficiary'))->toBe('جمعية أحجيات')->and($settings->get('payments', 'allowed_currencies'))->toBe('USD,EUR');

    $audit = DB::table('operational_audit_logs')->where('action', 'payment_settings_updated')->first();
    expect($audit)->not->toBeNull()->and((string) $audit->metadata)->not->toContain('TR33')->and((string) $audit->metadata)->not->toContain('جمعية أحجيات');

    expect(\App\Services\Payments\PaymentRules::currencies(app(\App\Services\Payments\PaymentProviderRegistry::class)->get('manual')))->toBe(['USD', 'EUR']);
});

test('E32-A10: the settings page refuses credentials and card numbers (nothing is saved)', function () {
    $settings = app(PlatformSettingsService::class);
    $before = $settings->getGroup('payments');

    Livewire::actingAs(e32_finance())->test(PaymentSettingsPage::class)
        ->fillForm(['manual_title' => 'تحويل', 'manual_account' => 'الرقم السري للحساب 1234 وكلمة المرور abc'])
        ->call('save');
    Livewire::actingAs(e32_finance())->test(PaymentSettingsPage::class)
        ->fillForm(['manual_title' => 'تحويل', 'manual_extra_note' => 'ادفع على البطاقة 4111 1111 1111 1111'])
        ->call('save');

    expect($settings->getGroup('payments'))->toBe($before)->and(e32_audit('payment_settings_updated'))->toBe(0);
});

test('E32-A11: proof retention prunes only final, old proofs - never an open request or one inside the dispute window - and keeps the record', function () {
    config(['payments.retention.proof_days_after_final' => 30, 'payments.retention.dispute_window_days' => 60]);
    $open = e32_submitted();
    $verified = e32_verified();
    $rejected = e32_payments()->reject(e32_finance(), e32_submitted(), 'not_received');
    $freshVerified = e32_verified();
    $verifiedPath = $verified->fresh()->proof_path;

    // يتقدّم الزمن 45 يومًا: المحقَّق (>30) مرشَّح، المرفوض (نافذة نزاع 60) لا، والمفتوح لا.
    $this->travel(45)->days();
    $retention = app(PaymentRetentionService::class);
    expect($retention->count())->toBe(2);   // verified + freshVerified (كلاهما >30 يومًا) ؛ rejected داخل نافذة النزاع ؛ open لا يُقلَّم
    $this->artisan('payments:prune-proofs', ['--dry-run' => true])->expectsOutputToContain('candidates=2')->assertExitCode(0);
    expect(Storage::disk('local')->exists($verifiedPath))->toBeTrue();

    $this->artisan('payments:prune-proofs')->expectsOutputToContain('pruned=2')->assertExitCode(0);
    expect(Storage::disk('local')->exists($verifiedPath))->toBeFalse()->and($verified->fresh()->proof_path)->toBeNull()->and($verified->fresh()->proof_purged_at)->not->toBeNull()
        ->and($verified->fresh()->proof_sha256)->not->toBeNull()->and($verified->fresh()->status)->toBe('verified')->and(SponsorshipPayment::query()->count())->toBe(4)
        ->and($open->fresh()->proof_path)->not->toBeNull()->and($rejected->fresh()->proof_path)->not->toBeNull()->and(e32_audit('payment_proof_pruned'))->toBe(2);

    $this->artisan('payments:prune-proofs')->expectsOutputToContain('pruned=0')->assertExitCode(0);
    $this->travel(30)->days();
    expect($retention->count())->toBe(1);   // المرفوض انتهت نافذة نزاعه الآن؛ المفتوح ما زال محميًا
    expect($freshVerified->fresh()->proof_purged_at)->not->toBeNull();
});

test('E32-A12: the history-table guard and the transfer sums know the payments table', function () {
    expect(FinancialHistoryGuard::HISTORY_TABLES)->toHaveKey('sponsorship_payments')
        ->and(config('db_transfer.financial_sums'))->toHaveKey('sponsorship_payments')
        ->and(config('db_transfer.financial_sums.sponsorship_payments'))->toBe(['amount_minor', 'accounting_amount_minor']);

    $user = e16User();
    e32_request($user);
    expect(fn () => DB::table('users')->where('id', $user->id)->delete())->toThrow(\Illuminate\Database\QueryException::class);
});

test('E32-A13: the four new notification types are registered with existing routes and no money text', function () {
    foreach (['support_request_received', 'support_action_required', 'support_rejected', 'support_refunded'] as $value) {
        $type = NotificationType::from($value);
        expect($type->allowedRoutes())->toContain('support.payments.show');
        foreach ($type->allowedRoutes() as $route) {
            expect(Route::has($route))->toBeTrue("missing route {$route}");
        }
        expect($type->title([]))->not->toBeEmpty()->and($type->body([]))->not->toMatch('/[0-9$€₺]/u');
    }

    $user = e16User();
    $payment = e32_request($user);
    $notes = e32_notes($user, 'support_request_received');
    expect($notes)->toHaveCount(1);
});

test('E32-A14: the demo seeder is idempotent, builds every state, refuses production and leaves a clean reconciliation', function () {
    $this->seed(DemoE32Seeder::class);
    $first = SponsorshipPayment::query()->count();
    $statuses = SponsorshipPayment::query()->pluck('status')->unique()->sort()->values()->all();
    $contributions = SponsorshipContribution::query()->count();

    $this->seed(DemoE32Seeder::class);

    expect(SponsorshipPayment::query()->count())->toBe($first)->toBe(6)->and($statuses)->toBe(['awaiting_payment', 'refunded', 'rejected', 'submitted', 'verified'])
        ->and(SponsorshipContribution::query()->count())->toBe($contributions)->toBe(3)
        ->and(SponsorshipPayment::query()->where('is_anonymous', true)->where('status', 'verified')->count())->toBe(1);
    $this->artisan('sponsorship:reconcile-payments')->assertExitCode(0);

    $this->app['env'] = 'production';
    expect(fn () => (new DemoE32Seeder)->run())->toThrow(RuntimeException::class);
});

test('E32-A15: the finance dashboard widget and navigation badge count only what needs attention, with no sensitive data', function () {
    e32_submitted();
    e32_submitted();
    e32_request();
    e32_verified();
    app(\App\Services\Payments\PaymentAnalytics::class)->forget();

    expect(SponsorshipPaymentResource::getNavigationBadge())->toBe('2');
    $summary = app(\App\Services\Payments\PaymentAnalytics::class)->summary(true);
    expect($summary['awaiting_verification'])->toBe(2)->and($summary['pending'])->toBe(1)->and($summary['verified_today'])->toBe(1)->and($summary['confirmed_count'])->toBe(1)
        ->and(array_keys($summary))->toBe(['pending', 'awaiting_verification', 'verified_today', 'failed', 'refunded', 'confirmed_total_minor', 'confirmed_count', 'average_minor', 'manual_share', 'currency']);

    e32_queue()->assertSeeHtml('wire:');
});
`````

### `tests/Feature/PaymentsE32/E32CheckoutTest.php`

`sha256: ae5cfe427a15583d4aefb662ce1618a2cac1dc064bce52ae24332e170f11b24c`

`````php
<?php

use App\Models\SponsorshipPayment;
use App\Models\SupporterProfile;
use App\Services\Sponsorship\SponsorshipProgramService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E32 (AK1) - صفحة الدفع وإنشاء طلب الدعم: البرنامج من المسار فقط، المبلغ/العملة تُتحقَّق في الخادم، ولا حقل مالي أو حالة يأتي من المتصفح.
*/

beforeEach(fn () => e32_seed());

function e32_post(array $over = [], $program = null, $user = null)
{
    $program ??= e32_program();
    $user ??= e16User();

    return [test()->actingAs($user)->post(route('support.checkout.store', $program->slug), $over + ['amount' => '25', 'currency' => 'USD', 'visibility' => 'public']), $program, $user];
}

test('E32-1: an active program renders the three-step checkout for a verified user', function () {
    $program = e32_program(['title' => 'برنامج دفع نشط']);

    $this->actingAs(e16User())->get(route('support.checkout', $program->slug))->assertOk()->assertSee('برنامج دفع نشط')->assertSee('المبلغ والعملة')->assertSee('طريقة الدفع والخصوصية')->assertSee('التأكيد والتعليمات');
});

test('E32-1b: guests are sent to login and unverified users cannot reach the checkout', function () {
    $program = e32_program();

    $this->get(route('support.checkout', $program->slug))->assertRedirect(route('login'));
    $this->post(route('support.checkout.store', $program->slug), ['amount' => '10', 'currency' => 'USD', 'visibility' => 'public'])->assertRedirect(route('login'));
    $this->actingAs(\App\Models\User::factory()->unverified()->create())->get(route('support.checkout', $program->slug))->assertRedirect();
    expect(SponsorshipPayment::query()->count())->toBe(0);
});

test('E32-2: a draft program cannot be checked out', function () {
    $program = e32_program([], false);

    $this->actingAs(e16User())->get(route('support.checkout', $program->slug))->assertNotFound();
    [$response] = e32_post([], $program);
    expect(SponsorshipPayment::query()->count())->toBe(0);
});

test('E32-3: a closed program cannot be checked out', function () {
    $program = e32_program();
    app(SponsorshipProgramService::class)->close(e30_admin(), $program);

    $this->actingAs(e16User())->get(route('support.checkout', $program->slug))->assertNotFound();
    e32_post([], $program);
    expect(SponsorshipPayment::query()->count())->toBe(0);
});

test('E32-4: an expired program (ends_at in the past) cannot be checked out', function () {
    $program = e32_program();
    DB::table('sponsorship_programs')->where('id', $program->id)->update(['ends_at' => now()->subDay()]);

    $this->actingAs(e16User())->get(route('support.checkout', $program->slug))->assertNotFound();
    [$response] = e32_post([], $program->fresh());
    $response->assertSessionHas('error');
    expect(SponsorshipPayment::query()->count())->toBe(0);
});

test('E32-5: the amount is required', function () {
    [$response] = e32_post(['amount' => '']);

    $response->assertSessionHasErrors('amount');
    expect(SponsorshipPayment::query()->count())->toBe(0);
});

test('E32-6/7: zero and negative amounts are rejected', function () {
    foreach (['0', '0.00', '-5', '-0.01', 'abc', '1e3'] as $bad) {
        [$response] = e32_post(['amount' => $bad]);
        expect(session()->has('error') || session()->has('errors'))->toBeTrue("accepted: {$bad}");
    }

    expect(SponsorshipPayment::query()->count())->toBe(0);

    // الأرقام الهندية مقبولة عمدًا (مستخدم عربي) وتُحلَّل لقيمة صحيحة بلا floats.
    [$arabic] = e32_post(['amount' => '١٠']);
    $arabic->assertSessionHas('success');
    expect(SponsorshipPayment::query()->value('amount_minor'))->toBe(1000);
});

test('E32-8: the maximum amount is enforced and the boundary is accepted', function () {
    config(['payments.checkout.max_amount_minor' => 5000]);

    [$over] = e32_post(['amount' => '50.01']);
    $over->assertSessionHas('error');
    expect(SponsorshipPayment::query()->count())->toBe(0);

    [$ok] = e32_post(['amount' => '50']);
    $ok->assertSessionHas('success');
    expect(SponsorshipPayment::query()->value('amount_minor'))->toBe(5000);
});

test('E32-8b: the minimum amount is enforced', function () {
    [$under] = e32_post(['amount' => '0.99']);
    $under->assertSessionHas('error');
    expect(SponsorshipPayment::query()->count())->toBe(0);
});

test('E32-9: an unsupported currency is rejected (unknown code, and a sponsorship currency outside the payments allow-list)', function () {
    [$a] = e32_post(['currency' => 'XXX']);
    $a->assertSessionHas('error');

    [$b] = e32_post(['currency' => 'SAR']); // معرّفة بالرعاية لكنها خارج قائمة الدفع الافتراضية
    $b->assertSessionHas('error');

    config(['payments.allowed_currencies' => ['USD']]);
    [$c] = e32_post(['currency' => 'EUR']);
    $c->assertSessionHas('error');

    expect(SponsorshipPayment::query()->count())->toBe(0);
});

test('E32-10: supported currencies are accepted and the amount is stored exactly in minor units', function () {
    foreach (['USD' => '12.34', 'EUR' => '5', 'TRY' => '250.5'] as $currency => $amount) {
        [$response] = e32_post(['currency' => $currency, 'amount' => $amount]);
        $response->assertSessionHas('success');
    }

    expect(SponsorshipPayment::query()->orderBy('id')->get(['currency', 'amount_minor'])->map(fn ($p) => [$p->currency, $p->amount_minor])->all())
        ->toBe([['USD', 1234], ['EUR', 500], ['TRY', 25050]]);
});

test('E32-11: the anonymous choice is stored and no display name is kept for it', function () {
    [$response, , $user] = e32_post(['visibility' => 'anonymous', 'display_name' => 'اسم يجب ألا يُحفظ']);
    $payment = SponsorshipPayment::query()->firstOrFail();

    $response->assertRedirect(route('support.payments.show', $payment->public_id));
    expect($payment->is_anonymous)->toBeTrue()->and($payment->display_name)->toBeNull()->and($payment->user_id)->toBe($user->id)->and($payment->status)->toBe('awaiting_payment');

    [$named] = e32_post(['visibility' => 'public', 'display_name' => 'اسم عام'], null, e16User());
    expect(SponsorshipPayment::query()->latest('id')->first()->display_name)->toBe('اسم عام');
});

test('E32-12: the browser cannot set a confirmed status or link a contribution', function () {
    [$response] = e32_post(['status' => 'verified', 'verified_at' => now()->toDateTimeString(), 'sponsorship_contribution_id' => 1, 'verified_by' => 1, 'confirmed' => true]);
    $payment = SponsorshipPayment::query()->firstOrFail();

    expect($payment->status)->toBe('awaiting_payment')->and($payment->verified_at)->toBeNull()->and($payment->sponsorship_contribution_id)->toBeNull()
        ->and(\App\Models\SponsorshipContribution::query()->count())->toBe(0);
});

test('E32-13: the browser cannot set the accounting amount or any server-owned financial field', function () {
    e32_post(['accounting_amount' => '1', 'accounting_amount_minor' => 1, 'accounting_currency' => 'EUR', 'user_id' => 999, 'sponsorship_program_id' => 999, 'reference' => 'HACKED', 'public_id' => 'X', 'expires_at' => now()->addYears(5)->toDateTimeString()]);
    $payment = SponsorshipPayment::query()->firstOrFail();

    expect($payment->accounting_amount_minor)->toBeNull()->and($payment->accounting_currency)->toBeNull()->and($payment->reference)->not->toBe('HACKED')->and($payment->reference)->toStartWith('SUP-')
        ->and($payment->user_id)->not->toBe(999)->and($payment->expires_at->lessThan(now()->addDays(3)))->toBeTrue();
});

test('E32-14: the browser cannot set a supporter tier; tiers are derived only from confirmed support', function () {
    [$response, , $user] = e32_post(['tier' => 'diamond', 'supporter_tier_id' => 5, 'supporter_tier' => 'gold']);

    expect(SupporterProfile::query()->where('user_id', $user->id)->exists())->toBeFalse()->and(SponsorshipPayment::query()->firstOrFail()->supporter_profile_id)->toBeNull()
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('sponsorship_payments', 'tier'))->toBeFalse();
});

test('E32-15: CSRF protects the checkout and every payment mutation route (419 without a token, nothing changes)', function () {
    $user = e16User();
    $program = e32_program();
    $payment = e32_request($user, $program);
    $original = $this->app['env'];
    $this->app['env'] = 'local';

    try {
        $this->actingAs($user);
        $this->post(route('support.checkout.store', $program->slug), ['amount' => '10', 'currency' => 'USD', 'visibility' => 'public'])->assertStatus(419);
        $this->post(route('support.payments.transferred', $payment->public_id), ['payer_note' => 'x'])->assertStatus(419);
        $this->post(route('support.payments.proof.upload', $payment->public_id))->assertStatus(419);
        $this->post(route('support.payments.cancel', $payment->public_id))->assertStatus(419);
    } finally {
        $this->app['env'] = $original;
    }

    expect(SponsorshipPayment::query()->count())->toBe(1)->and($payment->fresh()->status)->toBe('awaiting_payment');
});

test('E32-16: the checkout rate limit bites (10 per minute per user) while reads stay available', function () {
    $user = e16User();
    $program = e32_program();
    $this->actingAs($user);

    $statuses = e26_hammer($this, 'post', route('support.checkout.store', $program->slug), 12, ['amount' => '', 'currency' => 'USD', 'visibility' => 'public']);
    expect(e26_first_429($statuses))->toBe(11);

    $this->get(route('support.program', $program->slug))->assertOk();
});

test('E32-16b: a double-click with the same request key creates exactly one request', function () {
    $user = e16User();
    $program = e32_program();
    $key = (string) \Illuminate\Support\Str::ulid();

    e32_post(['request_key' => $key], $program, $user);
    e32_post(['request_key' => $key], $program, $user);

    expect(SponsorshipPayment::query()->count())->toBe(1);
});

test('E32-16c: the open-request cap blocks flooding and checkout stays closed when payments are disabled', function () {
    config(['payments.checkout.max_open_requests_per_user' => 2]);
    $user = e16User();
    $program = e32_program();

    e32_post([], $program, $user);
    e32_post([], $program, $user);
    [$third] = e32_post([], $program, $user);
    $third->assertSessionHas('error');
    expect(SponsorshipPayment::query()->count())->toBe(2);

    config(['payments.enabled' => false]);
    [$off] = e32_post([], $program, e16User());
    $off->assertSessionHas('error');
    expect(SponsorshipPayment::query()->count())->toBe(2);
});
`````

### `tests/Feature/PaymentsE32/E32ConcurrencyRewardTest.php`

`sha256: 49586e3896708f2686f1bda08eaffc53050d9c3ba426fff20d289413d20c3956`

`````php
<?php

use App\Models\CurrencyTransaction;
use App\Models\Puzzle;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipEventRewardPlan;
use App\Models\SponsorshipPayment;
use App\Models\SponsorshipRewardBonus;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Services\OperationalAuditService;
use App\Services\Payments\PaymentException;
use App\Services\PuzzleAttemptService;
use App\Services\Sponsorship\EventRewardPlanService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../DatabaseE31/_support.php';
require_once __DIR__.'/../CompetitiveRewards/RewardTestHelpers.php';
require_once __DIR__.'/../CreatorSeriesE30/_support.php';

/*
| E32 (AK12 + AK13) - التزامن وسلامة قاعدة البيانات (محاكاة إعادة إرسال مزدوج على SQLite + اختبارات MySQL حقيقية تُتخطى بوضوح على غيرها)،
| وسلامة اقتصاد المكافآت: التمويل المحقَّق وحده يعزّز المكافآت الرسمية؛ المجتمع/السلاسل صفر؛ لا ميزة شخصية للداعم؛ خطة الحدث المقفلة ثابتة.
*/

beforeEach(function () {
    e32_seed();
    config(['gems.daily_earn_cap' => 100000]);
});

afterEach(fn () => Carbon::setTestNow());

function e32_play_as(?User $user = null, int $reward = 200): array
{
    $user ??= User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => $reward]);

    return [app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح'), $user];
}

/** يشغّل استدعاءً ويعيد رمز خطأ MySQL إن رمى QueryException (وإلا 0). */
function e32_mysql_code(Closure $fn): int
{
    try {
        $fn();
    } catch (QueryException $e) {
        return (int) ($e->errorInfo[1] ?? 0);
    }

    return 0;
}

function e32_session(string $name): \Illuminate\Database\Connection
{
    config(['database.connections.'.$name => (array) config('database.connections.'.config('database.default'))]);
    DB::purge($name);

    return DB::connection($name);
}

// ------------------------------------------------------------------ AK12: التزامن (قابل للتشغيل على SQLite)

test('E32-93: a double submit of Verify with a STALE in-memory payment (two browser tabs) never creates a second contribution', function () {
    $program = e32_program();
    $payment = e32_submitted(null, $program, '20');
    $tabA = SponsorshipPayment::query()->findOrFail($payment->id);
    $tabB = SponsorshipPayment::query()->findOrFail($payment->id);   // نسخة قديمة: ما زالت submitted في الذاكرة

    e32_payments()->verify(e32_finance(), $tabA);
    $second = e32_payments()->verify(e32_finance(), $tabB);

    expect($tabB->status)->toBe('submitted')->and($second->status)->toBe('verified')->and(SponsorshipContribution::query()->count())->toBe(1)
        ->and(e32_funding($program))->toBe(2000)->and(e32_audit('contribution_materialized'))->toBe(1);

    // رفض بنسخة قديمة بعد التحقق لا يهدم المال المؤكَّد
    expect(fn () => e32_payments()->reject(e32_finance(), $tabB, 'other'))->toThrow(PaymentException::class);
    expect(e32_contribution($payment)->status)->toBe('confirmed');
});

test('E32-94: the database refuses two payments linked to one contribution and the model refuses re-linking', function () {
    $a = e32_verified();
    $b = e32_submitted();

    expect(fn () => DB::table('sponsorship_payments')->where('id', $b->id)->update(['sponsorship_contribution_id' => $a->sponsorship_contribution_id]))->toThrow(QueryException::class);

    $model = SponsorshipPayment::query()->findOrFail($a->id);
    expect(function () use ($model, $b) {
        $model->forceFill(['sponsorship_contribution_id' => SponsorshipContribution::query()->where('id', '!=', $model->sponsorship_contribution_id)->value('id') ?? $b->id])->save();
    })->toThrow(PaymentException::class);

    // وفي الاتجاه الآخر: مساهمتان بالمرجع الخارجي نفسه لدى المزوّد نفسه مرفوضتان من قاعدة البيانات
    $other = e32_verified();
    $first = e32_contribution($a);
    expect(fn () => DB::table('sponsorship_contributions')->where('id', $other->sponsorship_contribution_id)->update(['external_reference' => $first->external_reference, 'provider' => $first->provider]))->toThrow(QueryException::class);
});

test('E32-95: reference, provider reference and request key are unique in the database; many NULL provider references are allowed', function () {
    $user = e16User();
    $a = e32_request($user, null, '10', false, 'USD', 'key-unique-0001');
    $b = e32_request($user);
    $c = e32_request(e16User());   // مراجع مزوّد NULL متعددة مسموحة (يدوي)

    expect($a->provider_reference)->toBeNull()->and($b->provider_reference)->toBeNull()->and($c->provider_reference)->toBeNull();
    expect(fn () => DB::table('sponsorship_payments')->where('id', $b->id)->update(['reference' => $a->reference]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('sponsorship_payments')->where('id', $b->id)->update(['request_key' => 'key-unique-0001']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('sponsorship_payments')->where('id', $c->id)->update(['public_id' => $a->public_id]))->toThrow(QueryException::class);

    // نفس مفتاح الطلب لمستخدم آخر مسموح (التفرد لكل مستخدم)
    expect(e32_request(e16User(), null, '10', false, 'USD', 'key-unique-0001')->exists)->toBeTrue();
});

test('E32-96: a double submit of Refund (stale copies, and E30 direct refund first) is safe and counted once', function () {
    $payment = e32_verified();
    $tabA = SponsorshipPayment::query()->findOrFail($payment->id);
    $tabB = SponsorshipPayment::query()->findOrFail($payment->id);

    e32_payments()->refund(e32_finance(), $tabA, 'استرداد من التبويب الأول');
    $again = e32_payments()->refund(e32_finance(), $tabB, 'استرداد من التبويب الثاني');

    expect($again->status)->toBe('refunded')->and(e32_audit('payment_refunded'))->toBe(1)->and(e32_notes($payment->user, 'support_refunded'))->toHaveCount(1)
        ->and(SponsorshipContribution::query()->where('status', 'refunded')->count())->toBe(1);
});

test('E32-97b: an audit failure in the middle of verification rolls back the contribution, the profile and the payment state together', function () {
    $user = e16User();
    $payment = e32_submitted($user);

    app()->bind(OperationalAuditService::class, fn () => new class extends OperationalAuditService
    {
        public function log(string $action, ?object $subject = null, array $metadata = [], ?User $actor = null): void
        {
            if ($action === 'payment_verified') {
                throw new RuntimeException('simulated failure after the contribution was confirmed');
            }

            parent::log($action, $subject, $metadata, $actor);
        }
    });
    app()->forgetInstance(\App\Services\Payments\SponsorshipPaymentService::class);
    app()->forgetInstance(\App\Services\Sponsorship\ContributionService::class);
    app()->forgetInstance(\App\Services\Sponsorship\SupporterService::class);
    $level = DB::transactionLevel();

    expect(fn () => e32_payments()->verify(e32_finance(), $payment))->toThrow(RuntimeException::class);

    expect(DB::transactionLevel())->toBe($level)->and($payment->fresh()->status)->toBe('submitted')->and($payment->fresh()->sponsorship_contribution_id)->toBeNull()
        ->and(SponsorshipContribution::query()->count())->toBe(0)->and(SupporterProfile::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(e32_funding($payment->program))->toBe(0)->and(e32_notes($user, 'support_confirmed'))->toHaveCount(0);
});

test('E32-98: every financial mutation takes a row lock inside a transaction (structural guard) and the lock order is contribution then payment', function () {
    $source = (string) file_get_contents(app_path('Services/Payments/SponsorshipPaymentService.php'));

    foreach (['markTransferred', 'attachProof', 'cancelByOwner', 'requestClarification', 'cancel', 'verifyCore', 'rejectCore', 'refundCore', 'expireStale'] as $method) {
        preg_match('/function '.$method.'\(.*?\n    }\n/s', $source, $m);
        expect($m[0] ?? '')->toContain('DB::transaction')->and($m[0])->toContain('lockForUpdate');
    }

    preg_match('/function refundCore\(.*?\n    }\n/s', $source, $m);
    expect(strpos($m[0], 'SponsorshipContribution::query()->lockForUpdate()'))->toBeLessThan(strpos($m[0], 'SponsorshipPayment::query()->lockForUpdate()'));

    // لا مسار خارج الخدمة يكتب حالة الطلب مباشرة (المتحكمات/Filament/الأوامر تمرّ بالخدمة)
    foreach (['Http/Controllers/Support', 'Filament', 'Console/Commands'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($dir))) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $code = (string) file_get_contents($file->getPathname());
                expect($code)->not->toMatch('/SponsorshipPayment::query\(\)[^;]*->(update|delete)\(/')->and($code)->not->toMatch('/->forceFill\(\[\s*\'status\'/');
            }
        }
    }
});

// ------------------------------------------------------------------ AK12: MySQL حقيقية (تُتخطى بوضوح على غيرها)

test('E32-MY1: [MySQL] the payments schema is InnoDB/utf8mb4 with RESTRICT foreign keys; users and programs with payments cannot be deleted', function () {
    e31_requires_mysql();

    $row = DB::selectOne('select engine as e, table_collation as c from information_schema.tables where table_schema = database() and table_name = ?', ['sponsorship_payments']);
    expect(strtolower((string) $row->e))->toBe('innodb')->and((string) $row->c)->toStartWith('utf8mb4');

    $payment = e32_verified();
    expect(e32_mysql_code(fn () => DB::table('users')->where('id', $payment->user_id)->delete()))->toBe(1451)
        ->and(e32_mysql_code(fn () => DB::table('sponsorship_programs')->where('id', $payment->sponsorship_program_id)->delete()))->toBe(1451)
        ->and(e32_mysql_code(fn () => DB::table('sponsorship_contributions')->where('id', $payment->sponsorship_contribution_id)->delete()))->toBe(1451);
});

test('E32-MY2: [MySQL] two real sessions racing on the payment unique keys - the second waits (1205) then gets a duplicate (1062); never two rows', function () {
    e31_requires_mysql();

    $table = 'e32_race_probe_'.getmypid();
    $first = e32_session('e32_race_first');
    $second = e32_session('e32_race_second');
    // CREATE TABLE ... LIKE ينسخ الأعمدة والفهارس الفريدة الحقيقية لجدول الطلبات (بلا مفاتيح أجنبية) فيختبر قيودنا الفعلية.
    $first->statement("create table `{$table}` like `sponsorship_payments`");

    try {
        $row = fn (string $public, string $reference, string $providerRef, ?int $contribution) => [
            'public_id' => str_pad($public, 26, '0'), 'reference' => $reference, 'sponsorship_program_id' => 1, 'user_id' => 1, 'provider' => 'fakepay', 'provider_reference' => $providerRef,
            'amount_minor' => 100, 'currency' => 'USD', 'status' => 'awaiting_payment', 'is_anonymous' => 0, 'sponsorship_contribution_id' => $contribution,
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ];

        $first->beginTransaction();
        $first->table($table)->insert($row('A', 'SUP-AAAAAAA2', 'ext-same', 7));
        $second->statement('set session innodb_lock_wait_timeout = 1');

        $whileOpen = e32_mysql_code(fn () => $second->table($table)->insert($row('B', 'SUP-BBBBBBB2', 'ext-same', 8)));
        $first->commit();
        $afterCommitProviderRef = e32_mysql_code(fn () => $second->table($table)->insert($row('B', 'SUP-BBBBBBB2', 'ext-same', 8)));
        $afterCommitContribution = e32_mysql_code(fn () => $second->table($table)->insert($row('C', 'SUP-CCCCCCC2', 'ext-other', 7)));
        $afterCommitReference = e32_mysql_code(fn () => $second->table($table)->insert($row('D', 'SUP-AAAAAAA2', 'ext-third', 9)));

        expect($whileOpen)->toBe(1205)->and($afterCommitProviderRef)->toBe(1062)->and($afterCommitContribution)->toBe(1062)->and($afterCommitReference)->toBe(1062)
            ->and((int) $first->table($table)->count())->toBe(1);
    } finally {
        $first->statement("drop table if exists `{$table}`");
        DB::purge('e32_race_first');
        DB::purge('e32_race_second');
    }
});

test('E32-MY3: [MySQL] Arabic text and emoji in notes and names round-trip byte for byte, and money columns stay integers', function () {
    e31_requires_mysql();

    $user = e16User();
    $payment = e32_request($user, null, '12.34', false, 'USD', null, 'لُغْزُ 💎 ‏مرحبا');
    $payment = e32_payments()->markTransferred($user, $payment, 'حوّلت 💎 من حساب عمر');
    $row = DB::selectOne('select display_name, payer_note, amount_minor from sponsorship_payments where id = ?', [$payment->id]);

    expect($row->display_name)->toBe('لُغْزُ 💎 ‏مرحبا')->and($row->payer_note)->toBe('حوّلت 💎 من حساب عمر')->and($payment->fresh()->amount_minor)->toBeInt()->toBe(1234);
});

// ------------------------------------------------------------------ AK13: سلامة اقتصاد المكافآت

test('E32-99: verified funding boosts an official eligible reward with a separate ledger row and a bonus snapshot', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);
    e32_verified(null, $program, '50'); // 5%

    [$result, $player] = e32_play_as();

    $rows = CurrencyTransaction::query()->where('user_id', $player->id)->orderBy('id')->get();
    expect($result['sponsor_bonus'])->toBe(10)->and($rows)->toHaveCount(2)->and($rows[1]->reason)->toStartWith('sponsor_bonus:')->and($rows[1]->amount)->toBe(10)->and(SponsorshipRewardBonus::query()->count())->toBe(1);
});

test('E32-100: unverified money of every kind (awaiting, submitted, rejected, cancelled, expired) gives no boost', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);

    e32_request(null, $program, '900');
    e32_submitted(null, $program, '900');
    e32_payments()->reject(e32_finance(), e32_submitted(null, $program, '900'), 'not_received');
    e32_payments()->cancel(e32_finance(), e32_submitted(null, $program, '900'), 'طلب مكرر');
    $stale = e32_request(null, $program, '900');
    $this->travel(49)->hours();
    e32_payments()->expireStale();

    [$result] = e32_play_as();
    expect($result['sponsor_bonus'])->toBe(0)->and($stale->fresh()->status)->toBe('expired')->and(SponsorshipRewardBonus::query()->count())->toBe(0);
});

test('E32-101: a community puzzle gets zero boost and never touches the economy even with verified funding', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);
    e32_verified(null, $program, '500');

    $work = e28_published(e28_creator(), 'text');
    $player = e16User();
    app(\App\Services\Creators\CommunityPlayService::class)->attempt($player, $work->fresh(), 'إجابة خاطئة');
    app(\App\Services\Creators\CommunityPlayService::class)->attempt($player, $work->fresh(), (string) ($work->fresh()->answer_raw ?? 'صح'));

    expect(CurrencyTransaction::query()->where('user_id', $player->id)->count())->toBe(0)->and(SponsorshipRewardBonus::query()->count())->toBe(0);
});

test('E32-102: creator series get zero boost even with verified funding', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);
    e32_verified(null, $program, '500');

    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    e30s_published($creator, $works);
    $player = e16User();
    $before = e28_world($player);

    foreach ($works as $work) {
        $this->actingAs($player)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertSessionHasNoErrors();
    }

    expect(SponsorshipRewardBonus::query()->count())->toBe(0)->and(e28_world($player))->toBe($before);
});

test('E32-103: a paying supporter (even a main sponsor) gets no personal reward advantage over an ordinary player', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);
    $supporterUser = e16User();
    $paid = e32_verified($supporterUser, $program, '40'); // 4%
    e30_role($program, SupporterProfile::query()->findOrFail($paid->supporter_profile_id), 'main_sponsor');

    [$a] = e32_play_as($supporterUser);
    [$b, $ordinary] = e32_play_as();

    expect($a['sponsor_bonus'])->toBe(8)->and($b['sponsor_bonus'])->toBe(8)->and((int) $supporterUser->fresh()->wallet->pending_balance)->toBe((int) $ordinary->fresh()->wallet->pending_balance);
});

test('E32-104: a locked competition reward plan is unchanged by later verified payments (lock behaviour untouched)', function () {
    e17Freeze();
    $event = e18Event();
    $currency = app(\App\Services\Economy\CurrencyRegistry::class)->defaultEarnedCurrency();
    e18Rule($event, ['min_rank' => 1, 'max_rank' => 2, 'reward_type' => 'currency', 'currency_id' => $currency->id, 'amount' => 100]);
    $program = e30_program(['target_type' => 'competitive_event', 'target_id' => $event->id]);
    e30_boost($program, '10', 100, 1000);
    e32_verified(null, $program, '20');

    $plan = app(EventRewardPlanService::class)->lock($event->fresh(), e30_admin());
    expect($plan->boost_bps)->toBe(200)->and($plan->funding_snapshot_minor)->toBe(2000);

    e32_verified(null, $program, '500');
    e32_payments()->refund(e32_finance(), e32_verified(null, $program, '100'), 'استرداد بعد القفل');

    $fresh = SponsorshipEventRewardPlan::query()->findOrFail($plan->id);
    expect($fresh->boost_bps)->toBe(200)->and($fresh->funding_snapshot_minor)->toBe(2000);
});
`````

### `tests/Feature/PaymentsE32/E32OwnershipManualTest.php`

`sha256: a8871aabaf0f1065b6fd444406fe3ba7b57a8ecb1d218e0dcf2bbd3a5e8bfe1e`

`````php
<?php

use App\Models\SponsorshipPayment;
use App\Services\Payments\PaymentException;
use App\Services\PlatformSettingsService;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/_support.php';

/*
| E32 (AK2 + AK3) - ملكية الطلب (لا IDOR) والمزوّد اليدوي: مرجع فريد، تعليمات، «حوّلت المبلغ»، وإثبات خاص بقواعد MIME صارمة.
*/

beforeEach(fn () => e32_seed());

function e32_settings(array $values): void
{
    app(PlatformSettingsService::class)->setMany('payments', $values, e30_admin());
}

test('E32-17: a user sees their own payment page (reference and status, never "paid" before verification)', function () {
    $user = e16User();
    $payment = e32_request($user);

    $this->actingAs($user)->get(route('support.payments.show', $payment->public_id))->assertOk()->assertSee($payment->reference)->assertDontSee('تم الدفع');
    $this->actingAs($user)->get(route('support.history'))->assertOk()->assertSee($payment->reference);

    e32_payments()->markTransferred($user, $payment, 'حوّلت');
    $this->get(route('support.payments.show', $payment->public_id))->assertOk()->assertSee('طلب الدعم بانتظار التحقق')->assertDontSee('تم الدفع')->assertDontSee('تم التحقق وتأكيد دعمك');
});

test('E32-18: another user (even finance staff) cannot see, submit, upload to or cancel someone else\'s payment - always 404', function () {
    $owner = e16User();
    $payment = e32_request($owner);
    $intruder = e16User();

    foreach ([$intruder, e32_finance()] as $who) {
        $this->actingAs($who);
        $this->get(route('support.payments.show', $payment->public_id))->assertNotFound();
        $this->get(route('support.payments.proof', $payment->public_id))->assertNotFound();
        $this->post(route('support.payments.transferred', $payment->public_id), ['payer_note' => 'اختراق'])->assertNotFound();
        $this->post(route('support.payments.proof.upload', $payment->public_id), ['proof' => e32_proof()])->assertNotFound();
        $this->post(route('support.payments.cancel', $payment->public_id))->assertNotFound();
    }

    expect($payment->fresh()->status)->toBe('awaiting_payment')->and(fn () => e32_payments()->markTransferred($intruder, $payment, 'x'))->toThrow(PaymentException::class);

    $this->actingAs($intruder)->get(route('support.history'))->assertOk()->assertDontSee($payment->reference);
});

test('E32-19: proof ownership is enforced - only the owner downloads their proof; others get 404', function () {
    $owner = e16User();
    $payment = e32_submitted($owner);

    $this->actingAs(e16User())->get(route('support.payments.proof', $payment->public_id))->assertNotFound();

    $response = $this->actingAs($owner)->get(route('support.payments.proof', $payment->public_id))->assertOk();
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
});

test('E32-20: payments are addressed by a public ULID', function () {
    $payment = e32_request();

    expect($payment->public_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/i');
    $this->actingAs($payment->user)->get('/support/payments/'.$payment->public_id)->assertOk();
});

test('E32-21: the sequential internal id is not publicly routable (owner, staff and proof routes)', function () {
    $owner = e16User();
    $payment = e32_submitted($owner);
    $this->actingAs($owner);

    $this->get('/support/payments/'.$payment->id)->assertNotFound();
    $this->get('/support/payments/'.$payment->id.'/proof')->assertNotFound();
    $this->post('/support/payments/'.$payment->id.'/cancel')->assertNotFound();
    $this->actingAs(e32_finance())->get('/payments/staff/'.$payment->id.'/proof')->assertNotFound();
    $this->get('/support/payments/not-a-ulid')->assertNotFound();
});

test('E32-22: every request gets a unique, human-safe transfer reference', function () {
    $program = e32_program();
    $refs = [];

    for ($i = 0; $i < 25; $i++) {
        $refs[] = e32_request(e16User(), $program)->reference;
    }

    expect(array_unique($refs))->toHaveCount(25);

    foreach ($refs as $ref) {
        expect($ref)->toMatch('/^SUP-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/');
    }
});

test('E32-23: manual transfer instructions render for an open request only, with escaped text and no secrets', function () {
    e32_settings(['manual_beneficiary' => 'جمعية أحجيات', 'manual_bank_name' => 'بنك تجريبي', 'manual_account' => 'TEST-IBAN-0000-0000', 'manual_extra_note' => 'ملاحظة <b>غير مهرّبة</b>']);
    $user = e16User();
    $payment = e32_request($user);

    $html = $this->actingAs($user)->get(route('support.payments.show', $payment->public_id))->assertOk()->assertSee('جمعية أحجيات')->assertSee('بنك تجريبي')->assertSee('TEST-IBAN-0000-0000')->assertSee($payment->reference)->getContent();
    expect($html)->not->toContain('<b>غير مهرّبة</b>')->toContain('&lt;b&gt;');

    e32_payments()->cancelByOwner($user, $payment);
    $this->get(route('support.payments.show', $payment->public_id))->assertOk()->assertDontSee('TEST-IBAN-0000-0000');
});

test('E32-24: a disabled manual provider is rejected (deployment flag and admin switch) and checkout shows it is unavailable', function () {
    $user = e16User();
    $program = e32_program();

    config(['payments.providers.manual.enabled' => false]);
    expect(fn () => e32_request($user, $program))->toThrow(PaymentException::class);
    $this->actingAs($user)->get(route('support.checkout', $program->slug))->assertOk()->assertSee('الدفع غير متاح حاليًا');

    config(['payments.providers.manual.enabled' => true]);
    e32_settings(['manual_enabled' => false]);
    expect(fn () => e32_request($user, $program))->toThrow(PaymentException::class)->and(SponsorshipPayment::query()->count())->toBe(0);

    e32_settings(['manual_enabled' => true]);
    expect(e32_request($user, $program)->status)->toBe('awaiting_payment');
});

test('E32-25: the user marks the transfer as sent; the request becomes submitted and never expires; a double submit has no second effect', function () {
    $user = e16User();
    $payment = e32_request($user);

    $this->actingAs($user)->post(route('support.payments.transferred', $payment->public_id), ['payer_note' => 'من حساب أحمد'])->assertRedirect(route('support.payments.show', $payment->public_id))->assertSessionHas('success');
    $this->post(route('support.payments.transferred', $payment->public_id), ['payer_note' => 'من حساب أحمد'])->assertRedirect();

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe('submitted')->and($fresh->payer_note)->toBe('من حساب أحمد')->and($fresh->submitted_at)->not->toBeNull()->and($fresh->expires_at)->toBeNull()
        ->and(e32_audit('payment_proof_submitted'))->toBe(1);
});

test('E32-25b: the payer note rejects markup and an expired awaiting request cannot be submitted', function () {
    $user = e16User();
    $payment = e32_request($user);

    $this->actingAs($user)->post(route('support.payments.transferred', $payment->public_id), ['payer_note' => '<script>alert(1)</script>'])->assertSessionHas('error');
    expect($payment->fresh()->status)->toBe('awaiting_payment');

    $this->travel(49)->hours();
    expect(fn () => e32_payments()->markTransferred($user, $payment, 'متأخر'))->toThrow(PaymentException::class);
});

test('E32-25c: when the admin requires proof, submitting without it fails', function () {
    e32_settings(['proof_required' => true]);
    $user = e16User();
    $payment = e32_request($user);

    $this->actingAs($user)->post(route('support.payments.transferred', $payment->public_id), [])->assertSessionHas('error');
    expect($payment->fresh()->status)->toBe('awaiting_payment');

    $this->post(route('support.payments.transferred', $payment->public_id), ['proof' => e32_proof('png')])->assertSessionHas('success');
    expect($payment->fresh()->status)->toBe('submitted');
});

test('E32-26/27/28/29: JPEG, PNG, WEBP and PDF proofs are accepted and stored with the detected type', function (string $kind, string $mime, string $ext) {
    $user = e16User();
    $payment = e32_request($user);

    $this->actingAs($user)->post(route('support.payments.transferred', $payment->public_id), ['proof' => e32_proof($kind)])->assertSessionHas('success');

    $fresh = $payment->fresh();
    expect($fresh->proof_mime)->toBe($mime)->and($fresh->proof_path)->toEndWith('.'.$ext)->and($fresh->proof_sha256)->toHaveLength(64)->and(Storage::disk('local')->exists($fresh->proof_path))->toBeTrue();
})->with([
    'jpeg' => ['jpg', 'image/jpeg', 'jpg'],
    'png' => ['png', 'image/png', 'png'],
    'webp' => ['webp', 'image/webp', 'webp'],
    'pdf' => ['pdf', 'application/pdf', 'pdf'],
]);

test('E32-30: an executable disguised as an image or an HTML page disguised as a PDF is rejected and nothing is stored', function () {
    $user = e16User();
    $payment = e32_request($user);
    $this->actingAs($user);

    foreach (['exe', 'html'] as $kind) {
        $this->post(route('support.payments.transferred', $payment->public_id), ['proof' => e32_proof($kind)])->assertSessionHas('error');
    }

    expect($payment->fresh()->status)->toBe('awaiting_payment')->and($payment->fresh()->proof_path)->toBeNull()->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('E32-31: SVG is rejected as proof (active content)', function () {
    $user = e16User();
    $payment = e32_request($user);

    $this->actingAs($user)->post(route('support.payments.proof.upload', $payment->public_id), ['proof' => e32_proof('svg')])->assertSessionHas('error');
    expect($payment->fresh()->proof_path)->toBeNull()->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('E32-32: an oversized proof is rejected', function () {
    $user = e16User();
    $payment = e32_request($user);

    $this->actingAs($user)->post(route('support.payments.transferred', $payment->public_id), ['proof' => e32_proof('big')]);
    expect(session()->has('error') || session()->has('errors'))->toBeTrue();
    expect($payment->fresh()->status)->toBe('awaiting_payment')->and($payment->fresh()->proof_path)->toBeNull();

    config(['payments.proof.max_kb' => 1]);
    expect(fn () => e32_payments()->attachProof($user, $payment, \Illuminate\Http\UploadedFile::fake()->image('big.png', 400, 400)->size(10)))->toThrow(PaymentException::class);
});

test('E32-33: the proof is stored on a private disk under a random server-generated name', function () {
    $user = e16User();
    $payment = e32_submitted($user);
    $path = $payment->fresh()->proof_path;

    expect($path)->toStartWith('payment-proofs/')->and($path)->not->toContain('proof.png')->and(basename($path))->toMatch('/^[0-9a-z]{26}\.png$/')
        ->and(Storage::disk('local')->exists($path))->toBeTrue()->and(Storage::disk('public')->exists($path))->toBeFalse();

    config(['payments.proof.disk' => 'public']);
    expect(fn () => e32_payments()->attachProof($user, e32_request($user), e32_proof('png')))->toThrow(PaymentException::class);
});

test('E32-34: no direct public URL reaches the proof', function () {
    $payment = e32_submitted();
    $path = $payment->fresh()->proof_path;

    // القرص الخاص: لا symlink عام. مسار خدمة القرص المحلي (إن وُجد) يشترط توقيعًا ولا نولّد روابط موقّعة للإثبات أبدًا ⇒ 403/404.
    foreach (['/storage/'.$path, '/'.$path, '/storage/payment-proofs/'.basename($path), '/public/'.$path] as $url) {
        expect($this->get($url)->getStatusCode())->toBeIn([403, 404]);
    }

    expect(public_path($path))->not->toBeFile();
});

test('E32-35: unauthorized staff proof download is denied (guest, ordinary user, content manager) and finance succeeds with an audit row', function () {
    $payment = e32_submitted();
    $url = route('payments.staff.proof', $payment->public_id);

    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(e16User())->get($url)->assertForbidden();
    $this->actingAs(e32_manager())->get($url)->assertForbidden();
    expect(e32_audit('payment_proof_downloaded'))->toBe(0);

    $response = $this->actingAs(e32_finance())->get($url)->assertOk();
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')->and(e32_audit('payment_proof_downloaded'))->toBe(1);
});

test('E32-35b: replacing a proof removes the old private file and a final request freezes the proof', function () {
    $user = e16User();
    $payment = e32_submitted($user);
    $old = $payment->fresh()->proof_path;

    e32_payments()->attachProof($user, $payment, e32_proof('jpg'));
    $new = $payment->fresh()->proof_path;
    expect($new)->not->toBe($old)->and(Storage::disk('local')->exists($old))->toBeFalse()->and(Storage::disk('local')->exists($new))->toBeTrue();

    e32_payments()->reject(e32_finance(), $payment->fresh(), 'not_received');
    expect(fn () => e32_payments()->attachProof($user, $payment->fresh(), e32_proof('png')))->toThrow(PaymentException::class);
});
`````

### `tests/Feature/PaymentsE32/E32PrivacyPerformanceTest.php`

`sha256: 26645ecbd81c1d0913a500fa864430813996796501cf169b7688f4943fd87dfe`

`````php
<?php

use App\Filament\Resources\SponsorshipPaymentResource\Pages\ListSponsorshipPayments;
use App\Models\SponsorshipPayment;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Services\Payments\PaymentAnalytics;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipProgramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E32 (AK14 + AK15) - الخصوصية (لا بريد/إثبات/ملاحظة إدارية/مرجع خارجي علنًا) والأداء (استعلامات ثابتة، ترقيم صفحات، لا N+1).
*/

beforeEach(fn () => e32_seed());

/** عدد استعلامات قاعدة البيانات أثناء تنفيذ $fn. */
function e32_queries(Closure $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

// ------------------------------------------------------------------ AK14: الخصوصية

test('E32-105: a contact email is never public - no email column exists, and pages never print any user email', function () {
    expect(Schema::hasColumn('sponsorship_payments', 'email'))->toBeFalse()->and(Schema::hasColumn('sponsorship_payments', 'guest_email'))->toBeFalse();

    $program = e32_program(['title' => 'برنامج الإيميل']);
    $user = e16User(['email' => 'private-contact-e32@example.test']);
    $payment = e32_verified($user, $program, '33', false);

    $pages = [route('supporters.index'), route('support.index'), route('support.program', $program->slug), route('supporters.show', SupporterProfile::query()->findOrFail($payment->supporter_profile_id)->slug)];
    foreach ($pages as $url) {
        expect($this->get($url)->assertOk()->getContent())->not->toContain('private-contact-e32@example.test');
    }

    foreach ([route('support.payments.show', $payment->public_id), route('support.history')] as $url) {
        expect($this->actingAs($user)->get($url)->assertOk()->getContent())->not->toContain('private-contact-e32@example.test');
    }
});

test('E32-106: the proof is never public - not on pages, not in serialization, not in notifications', function () {
    $user = e16User();
    $payment = e32_submitted($user);
    $fresh = $payment->fresh();
    $secrets = [$fresh->proof_path, $fresh->proof_sha256, basename((string) $fresh->proof_path)];

    $pages = [route('supporters.index'), route('support.index'), route('support.program', $payment->program->slug)];
    foreach ($pages as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        foreach ($secrets as $secret) {
            expect(str_contains($html, (string) $secret))->toBeFalse("public page leaked proof detail at {$url}");
        }
    }

    $own = $this->actingAs($user)->get(route('support.payments.show', $payment->public_id))->assertOk()->getContent();
    foreach ($secrets as $secret) {
        expect(str_contains($own, (string) $secret))->toBeFalse('owner page exposes the stored proof path/hash');
    }

    $json = json_encode($fresh->toArray());
    foreach (['proof_path', 'proof_sha256', 'review_note', 'request_key', 'provider_reference', 'failure_code'] as $hidden) {
        expect(str_contains((string) $json, $hidden))->toBeFalse("model serialization exposes {$hidden}");
    }

    e32_payments()->verify(e32_finance(), $payment);
    expect(json_encode(e32_notes($user)->all(), JSON_UNESCAPED_UNICODE))->not->toContain((string) $fresh->proof_path);
});

test('E32-107: the internal reviewer note is never shown to the owner, the public, or in notifications', function () {
    $user = e16User();
    $payment = e32_submitted($user);
    e32_payments()->reject(e32_finance(), $payment, 'suspected_fraud', 'تعذّر قبول الطلب', 'ملاحظة-مراجع-داخلية-فريدة');

    $own = $this->actingAs($user)->get(route('support.payments.show', $payment->public_id))->assertOk()->getContent();
    $history = $this->get(route('support.history'))->assertOk()->getContent();
    $public = $this->get(route('support.program', $payment->program->slug))->assertOk()->getContent();

    foreach ([$own, $history, $public, json_encode(e32_notes($user)->all(), JSON_UNESCAPED_UNICODE)] as $surface) {
        expect(str_contains((string) $surface, 'ملاحظة-مراجع-داخلية-فريدة'))->toBeFalse()->and(str_contains((string) $surface, 'suspected_fraud'))->toBeFalse();
    }
});

test('E32-108: the external/transfer reference is not exposed publicly even for a named supporter', function () {
    $program = e32_program(['title' => 'برنامج المرجع', 'show_public_amounts' => true]);
    $payment = e32_verified(e16User(['name' => 'داعم باسمه']), $program, '45', false);
    $contribution = e32_contribution($payment);
    $profile = SupporterProfile::query()->findOrFail($payment->supporter_profile_id);

    foreach ([route('supporters.index'), route('supporters.index', ['scope' => 'program', 'program' => $program->slug]), route('support.index'), route('support.program', $program->slug), route('supporters.show', $profile->slug)] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        foreach ([$payment->reference, $contribution->external_reference, $payment->public_id, $contribution->public_id] as $needle) {
            expect(str_contains($html, (string) $needle))->toBeFalse("reference leak '{$needle}' at {$url}");
        }
    }

    $this->get(route('supporters.show', $profile->slug))->assertSee('داعم باسمه');
});

test('E32-109: another user cannot learn that a payment exists - ids are not enumerable and responses are identical', function () {
    $payment = e32_request();
    $this->actingAs(e16User());

    $real = $this->get(route('support.payments.show', $payment->public_id))->getStatusCode();
    $fake = $this->get(route('support.payments.show', '01ARZ3NDEKTSV4RRFFQ69G5FAV'))->getStatusCode();
    $bad = $this->get('/support/payments/zzz')->getStatusCode();

    expect($real)->toBe(404)->and($fake)->toBe(404)->and($bad)->toBe(404);
});

test('E32-101b: community puzzles and creator series are not sponsorship targets - there is no checkout for them', function () {
    $work = e28_published(e28_creator(), 'text');

    foreach (['community_puzzle', 'creator_series', 'community'] as $target) {
        expect(fn () => app(SponsorshipProgramService::class)->create(e30_admin(), ['title' => 'برنامج '.$target, 'target_type' => $target, 'target_id' => $work->id]))->toThrow(SponsorshipException::class);
    }

    expect(\App\Models\SponsorshipProgram::query()->count())->toBe(0);
});

test('E32-110: sensitive settings are refused - credentials and card numbers cannot be stored as payment instructions', function () {
    foreach (['كلمة المرور: abc123', 'password=hunter2', 'PIN 4321', 'CVV 123', 'secret key sk_live_abcdef', '4111 1111 1111 1111', 'private key -----BEGIN'] as $bad) {
        expect(\App\Services\Payments\PaymentSettingsGuard::violations(['manual_account' => $bad]))->not->toBe([], "accepted: {$bad}");
    }

    expect(\App\Services\Payments\PaymentSettingsGuard::violations(['manual_account' => 'TR00 0000 0000 0000 0000 0000 00', 'manual_beneficiary' => 'جمعية أحجيات']))->toBe([]);
});

// ------------------------------------------------------------------ AK15: الأداء

test('E32-111: the support page is bounded - constant query count and at most 24 program cards', function () {
    $few = e32_program();
    e32_verified(null, $few, '10');
    $this->get(route('support.index'))->assertOk();   // تسخين: كاش الإعدادات/الأدوار يُحمَّل مرة واحدة
    $small = e32_queries(fn () => $this->get(route('support.index'))->assertOk());

    for ($i = 0; $i < 30; $i++) {
        e32_program(['title' => 'برنامج '.$i]);
    }
    $big = e32_queries(fn () => $this->get(route('support.index'))->assertOk());
    $html = $this->get(route('support.index'))->getContent();

    expect($big)->toBe($small)->and(substr_count($html, 'href="'.url('/support/').'/'))->toBeLessThanOrEqual(24 * 3);
});

test('E32-112: the checkout page is bounded - query count does not grow with existing payments', function () {
    $user = e16User();
    $program = e32_program();
    $this->actingAs($user);
    $this->get(route('support.checkout', $program->slug))->assertOk();   // تسخين
    $before = e32_queries(fn () => $this->get(route('support.checkout', $program->slug))->assertOk());

    config(['payments.checkout.max_open_requests_per_user' => 100]);
    for ($i = 0; $i < 25; $i++) {
        e32_request($user, $program, (string) (5 + $i));
    }
    $after = e32_queries(fn () => $this->get(route('support.checkout', $program->slug))->assertOk());

    expect($after)->toBe($before);
});

test('E32-113: the history is paginated (15 per page) and has no N+1 on the program relation', function () {
    config(['payments.checkout.max_open_requests_per_user' => 100]);
    $user = e16User();
    $program = e32_program();
    for ($i = 0; $i < 3; $i++) {
        e32_request($user, e32_program(['title' => 'برنامج '.$i]), (string) (5 + $i));
    }
    $this->actingAs($user);
    $this->get(route('support.history'))->assertOk();   // تسخين
    $small = e32_queries(fn () => $this->get(route('support.history'))->assertOk());

    for ($i = 0; $i < 37; $i++) {
        e32_request($user, e32_program(['title' => 'برنامج إضافي '.$i]), (string) (5 + $i));
    }
    $big = e32_queries(fn () => $this->get(route('support.history'))->assertOk());
    $page1 = $this->get(route('support.history'))->getContent();
    $page3 = $this->get(route('support.history', ['page' => 3]))->assertOk()->getContent();

    expect($big)->toBe($small)->and(preg_match_all('/SUP-[A-Z2-9]{8}/', $page1))->toBeLessThanOrEqual(15 * 2)->and(preg_match_all('/SUP-[A-Z2-9]{8}/', $page3))->toBeGreaterThan(0);
    expect(SponsorshipPayment::query()->where('user_id', $user->id)->count())->toBe(40);
});

test('E32-114: the admin queue is paginated and the finance dashboard uses a bounded number of queries', function () {
    config(['payments.checkout.max_open_requests_per_user' => 100]);
    $program = e32_program();
    for ($i = 0; $i < 3; $i++) {
        e32_submitted(null, $program, (string) (5 + $i));
    }
    $analytics = app(PaymentAnalytics::class);
    $small = e32_queries(fn () => $analytics->summary(true));

    for ($i = 0; $i < 45; $i++) {
        e32_request(null, $program, (string) (5 + $i));
    }
    $big = e32_queries(fn () => $analytics->summary(true));
    expect($big)->toBe($small)->and($big)->toBeLessThanOrEqual(6);

    $summary = $analytics->summary(true);
    expect($summary['awaiting_verification'])->toBe(3)->and($summary['pending'])->toBe(45)->and($summary['confirmed_total_minor'])->toBe(0);

    $component = Livewire::actingAs(e32_finance())->test(ListSponsorshipPayments::class)->assertSee('طلبات الدعم');
    expect($component->instance()->getAllTableRecordsCount())->toBe(48)->and($component->instance()->getTableRecords())->toHaveCount(20);
});

test('E32-115: no N+1 for the supporter/program/user relations in the admin queue (query count is flat in rows)', function () {
    $finance = e32_finance();
    $program = e32_program();
    for ($i = 0; $i < 3; $i++) {
        e32_verified(null, $program, (string) (5 + $i), $i % 2 === 0);
    }
    Livewire::actingAs($finance)->test(ListSponsorshipPayments::class)->assertSuccessful();   // تسخين
    $small = e32_queries(fn () => Livewire::actingAs($finance)->test(ListSponsorshipPayments::class)->assertSuccessful());

    config(['payments.checkout.max_open_requests_per_user' => 100]);
    for ($i = 0; $i < 15; $i++) {
        e32_verified(null, e32_program(['title' => 'برنامج '.$i]), (string) (5 + $i), $i % 2 === 0);
    }
    $big = e32_queries(fn () => Livewire::actingAs($finance)->test(ListSponsorshipPayments::class)->assertSuccessful());

    expect($big)->toBeLessThanOrEqual($small + 3);
});
`````

### `tests/Feature/PaymentsE32/E32ProviderReconcileExpiryTest.php`

`sha256: 653b13d22ee180d1d3200baee4c5bba68e791801a19505f1a04e07094123d340`

`````php
<?php

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use App\Models\SponsorshipProgram;
use App\Models\User;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentInit;
use App\Services\Payments\PaymentProvider;
use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Payments\PaymentReconciliationService;
use App\Services\Payments\ProviderCallback;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E32 (AK9 + AK10 + AK11) - عقد المزوّد (مزوّد وهمي داخل الاختبار فقط - لا بوابة حقيقية ولا شبكة)، المطابقة المالية للقراءة فقط، والانتهاء المجدول.
*/

if (! class_exists('E32FakeGateway')) {
    /** مزوّد إلكتروني وهمي يبرهن أن العقد كافٍ: توقيع header بسيط يحاكي HMAC المزوّد الحقيقي. */
    class E32FakeGateway implements PaymentProvider
    {
        public static bool $enabled = true;

        public function key(): string
        {
            return 'fakepay';
        }

        public function label(): string
        {
            return 'بوابة وهمية';
        }

        public function isEnabled(): bool
        {
            return self::$enabled;
        }

        public function supportsRedirect(): bool
        {
            return true;
        }

        public function supportsWebhook(): bool
        {
            return true;
        }

        public function supportsRefund(): bool
        {
            return true;
        }

        public function currencies(): ?array
        {
            return null;
        }

        public function createPayment(SponsorshipPayment $payment): PaymentInit
        {
            return new PaymentInit('ext-'.$payment->reference, 'https://gateway.invalid/pay/'.$payment->reference);
        }

        public function verifyCallback(Request $request): ProviderCallback
        {
            if ($request->header('X-Fake-Signature') !== 'valid-signature') {
                throw new PaymentException('invalid signature');
            }

            return new ProviderCallback((string) $request->input('event'), (string) $request->input('ref'), (string) $request->input('state'), $request->input('amount') === null ? null : (int) $request->input('amount'), $request->input('currency'));
        }

        public function refundReference(SponsorshipPayment $payment): ?string
        {
            return 'refund-'.$payment->provider_reference;
        }

        public function status(SponsorshipPayment $payment): string
        {
            return (string) $payment->status;
        }
    }
}

beforeEach(function () {
    e32_seed();
    E32FakeGateway::$enabled = true;
    config(['payments.providers.fakepay' => ['enabled' => true, 'class' => E32FakeGateway::class], 'payments.webhooks.enabled' => true]);
    app(PaymentProviderRegistry::class)->forget();
});

function e32_gateway_payment(string $amount = '25', string $currency = 'USD'): SponsorshipPayment
{
    return e32_payments()->createRequest(e16User(), e32_program(), $amount, $currency, false, null, 'fakepay');
}

function e32_hook(string $ref, string $state = 'paid', string $event = 'evt-1', ?int $amount = null, ?string $currency = null, string $signature = 'valid-signature', string $provider = 'fakepay')
{
    return test()->withHeaders(['X-Fake-Signature' => $signature])->postJson('/api/payments/webhooks/'.$provider, array_filter(['event' => $event, 'ref' => $ref, 'state' => $state, 'amount' => $amount, 'currency' => $currency, 'secret_token' => 'SUPERSECRET-XYZ-123'], fn ($v) => $v !== null));
}

// ------------------------------------------------------------------ AK9: عقد المزوّد

test('E32-77: an unknown or malformed provider key is rejected before anything is created', function () {
    $user = e16User();
    $program = e32_program();

    foreach (['nope', '../etc/passwd', 'FAKE PAY', '', 'stripe'] as $key) {
        expect(fn () => e32_payments()->createRequest($user, $program, '10', 'USD', false, null, $key === '' ? 'zzz' : $key))->toThrow(PaymentException::class);
    }

    expect(SponsorshipPayment::query()->count())->toBe(0);
});

test('E32-78: a disabled provider is rejected and hidden from checkout', function () {
    E32FakeGateway::$enabled = false;
    $registry = app(PaymentProviderRegistry::class);

    expect(fn () => e32_payments()->createRequest(e16User(), e32_program(), '10', 'USD', false, null, 'fakepay'))->toThrow(PaymentException::class)
        ->and($registry->enabledKeys())->toBe(['manual'])->and(SponsorshipPayment::query()->count())->toBe(0);

    E32FakeGateway::$enabled = true;
    expect($registry->enabledKeys())->toContain('fakepay');

    config(['payments.enabled' => false]);
    expect(fn () => e32_gateway_payment())->toThrow(PaymentException::class);
});

test('E32-79/80: webhooks are closed by default; the manual provider and unknown providers have no webhook; a bad signature changes nothing', function () {
    $payment = e32_gateway_payment();

    config(['payments.webhooks.enabled' => false]);
    e32_hook($payment->provider_reference)->assertNotFound();

    config(['payments.webhooks.enabled' => true]);
    e32_hook($payment->provider_reference, provider: 'manual')->assertNotFound();
    e32_hook($payment->provider_reference, provider: 'unknown')->assertNotFound();
    e32_hook($payment->provider_reference, provider: '..%2Fetc')->assertNotFound();
    e32_hook($payment->provider_reference, signature: 'forged')->assertStatus(400);

    expect($payment->fresh()->status)->toBe('awaiting_payment')->and(SponsorshipContribution::query()->count())->toBe(0);
    $this->get('/api/payments/webhooks/fakepay')->assertStatus(405);
});

test('E32-80b: an oversized webhook body is refused and the endpoint needs no CSRF token (signature is the authentication)', function () {
    $payment = e32_gateway_payment();
    $original = $this->app['env'];
    $this->app['env'] = 'local';

    try {
        $this->withHeaders(['X-Fake-Signature' => 'valid-signature'])->postJson('/api/payments/webhooks/fakepay', ['event' => 'e', 'ref' => $payment->provider_reference, 'state' => 'paid', 'pad' => str_repeat('x', 70000)])->assertStatus(413);
        e32_hook($payment->provider_reference, 'paid', 'evt-csrf')->assertOk();
    } finally {
        $this->app['env'] = $original;
    }

    expect($payment->fresh()->status)->toBe('verified');
});

test('E32-81: provider references are unique per provider (service and database)', function () {
    $a = e32_gateway_payment();
    $b = e32_gateway_payment();

    expect($a->provider_reference)->not->toBe($b->provider_reference);
    expect(fn () => DB::table('sponsorship_payments')->where('id', $b->id)->update(['provider_reference' => $a->provider_reference]))->toThrow(QueryException::class);

    // مزوّد يُرجع المرجع نفسه لطلبين: الثاني يُلغى بأمان ولا يكرّر المرجع.
    $same = new class extends E32FakeGateway
    {
        public function createPayment(SponsorshipPayment $payment): PaymentInit
        {
            return new PaymentInit('ext-fixed-1');
        }
    };
    app(PaymentProviderRegistry::class)->swap('fakepay', $same);
    $first = e32_payments()->createRequest(e16User(), e32_program(), '10', 'USD', false, null, 'fakepay');
    expect($first->provider_reference)->toBe('ext-fixed-1');
    expect(fn () => e32_payments()->createRequest(e16User(), e32_program(), '10', 'USD', false, null, 'fakepay'))->toThrow(PaymentException::class);
    expect(SponsorshipPayment::query()->where('provider_reference', 'ext-fixed-1')->count())->toBe(1)->and(SponsorshipPayment::query()->where('status', 'cancelled')->count())->toBe(1);
});

test('E32-82: a replayed paid callback is idempotent end to end (one contribution, one audit, one notification) and the raw body/secret is never stored', function () {
    $payment = e32_gateway_payment('25');
    $owner = $payment->user;

    e32_hook($payment->provider_reference, 'paid', 'evt-9', 2500, 'USD')->assertOk()->assertJson(['ok' => true, 'result' => 'applied']);
    e32_hook($payment->provider_reference, 'paid', 'evt-9', 2500, 'USD')->assertOk()->assertJson(['result' => 'duplicate']);
    e32_hook($payment->provider_reference, 'paid', 'evt-10', 2500, 'USD')->assertOk()->assertJson(['result' => 'duplicate']);

    expect($payment->fresh()->status)->toBe('verified')->and($payment->fresh()->verified_by)->toBeNull()->and(SponsorshipContribution::query()->count())->toBe(1)
        ->and(e32_audit('payment_verified'))->toBe(1)->and(e32_notes($owner, 'support_confirmed'))->toHaveCount(1)->and(e32_funding($payment->program))->toBe(2500);

    $dump = json_encode([DB::table('operational_audit_logs')->get(), DB::table('sponsorship_payments')->get(), DB::table('notifications')->get()]);
    expect(str_contains((string) $dump, 'SUPERSECRET-XYZ-123'))->toBeFalse();
});

test('E32-82b: callbacks that do not match (amount, currency, unknown reference) never auto-verify; failed and refunded callbacks apply once', function () {
    $mismatch = e32_gateway_payment('25');
    e32_hook($mismatch->provider_reference, 'paid', 'evt-a', 999, 'USD')->assertOk()->assertJson(['result' => 'needs_review']);
    expect($mismatch->fresh()->status)->toBe('awaiting_payment')->and(e32_audit('payment_callback_needs_review'))->toBe(1);

    $foreign = e32_payments()->createRequest(e16User(), e32_program(), '10', 'EUR', false, null, 'fakepay');
    e32_hook($foreign->provider_reference, 'paid', 'evt-b', 1000, 'EUR')->assertOk()->assertJson(['result' => 'needs_review']);
    expect($foreign->fresh()->status)->toBe('awaiting_payment');

    e32_hook('ext-does-not-exist', 'paid', 'evt-c')->assertOk()->assertJson(['result' => 'ignored']);

    $failed = e32_gateway_payment('30');
    e32_hook($failed->provider_reference, 'failed', 'evt-d')->assertOk()->assertJson(['result' => 'applied']);
    e32_hook($failed->provider_reference, 'failed', 'evt-d')->assertOk()->assertJson(['result' => 'duplicate']);
    expect($failed->fresh()->status)->toBe('rejected');

    $paid = e32_gateway_payment('40');
    e32_hook($paid->provider_reference, 'paid', 'evt-e', 4000, 'USD')->assertOk();
    e32_hook($paid->provider_reference, 'refunded', 'evt-f')->assertOk()->assertJson(['result' => 'applied']);
    e32_hook($paid->provider_reference, 'refunded', 'evt-f')->assertOk()->assertJson(['result' => 'duplicate']);
    expect($paid->fresh()->status)->toBe('refunded')->and(e32_contribution($paid)->status)->toBe('refunded')->and(e32_funding($paid->program))->toBe(0);
});

test('E32-79b: the manual provider contract - no redirect, no webhook, no automatic refund, no callback', function () {
    $manual = app(PaymentProviderRegistry::class)->get('manual');

    expect($manual->supportsWebhook())->toBeFalse()->and($manual->supportsRedirect())->toBeFalse()->and($manual->supportsRefund())->toBeFalse()->and($manual->key())->toBe('manual')
        ->and(fn () => $manual->verifyCallback(Request::create('/x')))->toThrow(PaymentException::class)
        ->and($manual->createPayment(e32_request())->providerReference)->toBeNull();
});

// ------------------------------------------------------------------ AK10: المطابقة المالية

test('E32-83: a clean system reports zero mismatches (service and command)', function () {
    e32_verified();
    e32_submitted();
    e32_payments()->refund(e32_finance(), e32_verified(), 'استرداد نظيف');

    expect(app(PaymentReconciliationService::class)->run())->toBe([]);
    $this->artisan('sponsorship:reconcile-payments')->expectsOutputToContain('mismatches=0')->assertExitCode(0);
});

test('E32-84: a verified payment without a contribution is detected', function () {
    $payment = e32_submitted();
    DB::table('sponsorship_payments')->where('id', $payment->id)->update(['status' => 'verified']);

    $codes = array_column(app(PaymentReconciliationService::class)->run(), 'code');
    expect($codes)->toContain('verified_without_contribution');
    $this->artisan('sponsorship:reconcile-payments')->assertExitCode(1);
});

test('E32-85: an amount or currency mismatch between payment and contribution is detected', function () {
    $a = e32_verified();
    DB::table('sponsorship_payments')->where('id', $a->id)->update(['amount_minor' => 1]);
    $b = e32_verified();
    DB::table('sponsorship_payments')->where('id', $b->id)->update(['currency' => 'EUR']);

    $codes = array_column(app(PaymentReconciliationService::class)->run(), 'code');
    expect($codes)->toContain('amount_mismatch')->toContain('currency_mismatch');
});

test('E32-86: refund-state and confirmation mismatches are detected, and an orphan materialized contribution is detected', function () {
    $a = e32_verified();
    DB::table('sponsorship_payments')->where('id', $a->id)->update(['status' => 'refunded']);   // الطلب مسترد والمساهمة ما زالت مؤكَّدة

    $b = e32_verified();
    DB::table('sponsorship_contributions')->where('id', $b->sponsorship_contribution_id)->update(['status' => 'cancelled']);   // طلب محقَّق ومساهمته ملغاة

    $c = e32_verified();
    DB::table('sponsorship_payments')->where('id', $c->id)->update(['sponsorship_contribution_id' => null, 'status' => 'rejected']);   // مساهمة يتيمة

    $codes = array_column(app(PaymentReconciliationService::class)->run(), 'code');
    expect($codes)->toContain('refund_state_mismatch')->toContain('contribution_not_confirmed')->toContain('orphan_materialized_contribution');
});

test('E32-87: the reconciliation output exposes no secrets, names, emails, notes or proof paths', function () {
    $user = e16User(['name' => 'اسم-حساس-للمطابقة', 'email' => 'recon-secret@example.test']);
    $payment = e32_submitted($user);
    e32_payments()->reject(e32_finance(), e32_submitted(), 'other', null, 'ملاحظة-داخلية-حساسة');
    DB::table('sponsorship_payments')->where('id', $payment->id)->update(['status' => 'verified']);
    $path = $payment->fresh()->proof_path;

    $output = '';
    \Illuminate\Support\Facades\Artisan::call('sponsorship:reconcile-payments', ['--json' => true]);
    $output = \Illuminate\Support\Facades\Artisan::output();
    $json = json_decode($output, true);

    expect($json['mismatches'])->toBeGreaterThan(0)->and(array_keys($json['findings'][0]))->toBe(['code', 'payment', 'reference', 'contribution']);
    foreach (['اسم-حساس-للمطابقة', 'recon-secret@example.test', 'ملاحظة-داخلية-حساسة', $path, 'proof', 'payer_note', 'حوّلت من حسابي'] as $secret) {
        expect(str_contains($output, (string) $secret))->toBeFalse("reconcile leaked {$secret}");
    }
});

test('E32-88: reconciliation is read-only by default and with --dry-run (nothing is changed or repaired)', function () {
    $payment = e32_submitted();
    DB::table('sponsorship_payments')->where('id', $payment->id)->update(['status' => 'verified']);
    $snapshot = fn () => json_encode([DB::table('sponsorship_payments')->orderBy('id')->get(), DB::table('sponsorship_contributions')->orderBy('id')->get(), DB::table('operational_audit_logs')->count()]);
    $before = $snapshot();

    $this->artisan('sponsorship:reconcile-payments')->assertExitCode(1);
    $this->artisan('sponsorship:reconcile-payments', ['--dry-run' => true])->assertExitCode(1);
    $this->artisan('sponsorship:reconcile-payments', ['--json' => true])->assertExitCode(1);

    expect($snapshot())->toBe($before);
    $source = (string) file_get_contents(app_path('Services/Payments/PaymentReconciliationService.php'));
    expect($source)->not->toMatch('/->(update|delete|insert|forceFill|save)\(/');
});

// ------------------------------------------------------------------ AK11: الانتهاء

test('E32-89: a stale awaiting request expires after its window, audited, and stays final', function () {
    $payment = e32_request();
    $this->travel(49)->hours();

    expect(e32_payments()->expireStale())->toBe(1);

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe('expired')->and($fresh->expired_at)->not->toBeNull()->and(e32_audit('payment_expired'))->toBe(1)->and(e32_funding($payment->program))->toBe(0);
    expect(fn () => e32_payments()->markTransferred($payment->user, $fresh, 'متأخر'))->toThrow(PaymentException::class);
});

test('E32-90: a fresh awaiting request stays open', function () {
    $payment = e32_request();
    $this->travel(47)->hours();

    expect(e32_payments()->expireStale())->toBe(0)->and($payment->fresh()->status)->toBe('awaiting_payment');
});

test('E32-91: submitted, verified and refunded payments never expire', function () {
    $submitted = e32_submitted();
    $verified = e32_verified();
    $refunded = e32_verified();
    e32_payments()->refund(e32_finance(), $refunded, 'استرداد قبل الانتهاء');
    $this->travel(400)->days();

    expect(e32_payments()->expireStale())->toBe(0)
        ->and($submitted->fresh()->status)->toBe('submitted')->and($verified->fresh()->status)->toBe('verified')->and($refunded->fresh()->status)->toBe('refunded');
});

test('E32-92: the scheduled command is idempotent, batch-bounded and registered on the scheduler', function () {
    $a = e32_request();
    $b = e32_request();
    $this->travel(49)->hours();

    $this->artisan('payments:expire-stale')->assertExitCode(0);
    expect(SponsorshipPayment::query()->where('status', 'expired')->count())->toBe(2);
    $this->artisan('payments:expire-stale')->assertExitCode(0);
    expect(SponsorshipPayment::query()->where('status', 'expired')->count())->toBe(2)->and(e32_audit('payment_expired'))->toBe(2);

    $events = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->description)->all();
    expect($events)->toContain('payments-expire-stale');

    config(['payments.request.expire_batch' => 1]);
    e32_request();
    e32_request();
    $this->travel(49)->hours();
    expect(e32_payments()->expireStale())->toBe(1)->and(e32_payments()->expireStale())->toBe(1);
});
`````

### `tests/Feature/PaymentsE32/E32RejectRefundAnonymousTest.php`

`sha256: 2ef13667c95a4f561032204011d3c37db7ce8a56ac49eef4a0f100c5af9595e6`

`````php
<?php

use App\Models\Puzzle;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use App\Models\SponsorshipRewardBonus;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Services\Payments\PaymentException;
use App\Services\PuzzleAttemptService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SupporterLeaderboardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_support.php';

/*
| E32 (AK6 + AK7 + AK8) - الرفض والإلغاء، الاسترداد (بلا سحب مكافآت تاريخية)، وخصوصية الدعم المجهول.
*/

beforeEach(function () {
    e32_seed();
    config(['gems.daily_earn_cap' => 100000]);
});

function e32_gems(User $user): int
{
    return (int) $user->fresh()->wallet->pending_balance;
}

function e32_play(?User $user = null): array
{
    $user ??= User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 200]);

    return [app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح'), $user];
}

test('E32-56/57: finance rejects with a whitelisted code; the visible message is shown to the owner, the internal note never is', function () {
    $user = e16User();
    $payment = e32_submitted($user);

    e32_payments()->reject(e32_finance(), $payment, 'not_received', 'لم يصلنا تحويل بهذا المرجع', 'ملاحظة داخلية سرّية للمراجع');

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe('rejected')->and($fresh->failure_code)->toBe('not_received')->and($fresh->rejected_by)->not->toBeNull()->and(e32_audit('payment_rejected'))->toBe(1);

    $this->actingAs($user)->get(route('support.payments.show', $payment->public_id))->assertOk()->assertSee('لم يصلنا تحويل بهذا المرجع')->assertDontSee('ملاحظة داخلية سرّية للمراجع')->assertDontSee('not_received');
    expect(json_encode($fresh->toArray()))->not->toContain('ملاحظة داخلية')->not->toContain('failure_code');
});

test('E32-57b: an unknown rejection code and markup in messages are refused; a second reject is idempotent', function () {
    $payment = e32_submitted();

    expect(fn () => e32_payments()->reject(e32_finance(), $payment, 'made-up'))->toThrow(PaymentException::class)
        ->and(fn () => e32_payments()->reject(e32_finance(), $payment, 'other', '<script>alert(1)</script>'))->toThrow(PaymentException::class)
        ->and($payment->fresh()->status)->toBe('submitted');

    e32_payments()->reject(e32_finance(), $payment, 'duplicate');
    e32_payments()->reject(e32_finance(), $payment->fresh(), 'duplicate');
    expect(e32_audit('payment_rejected'))->toBe(1)->and(e32_notes($payment->user, 'support_rejected'))->toHaveCount(1);
});

test('E32-58: the owner is notified of a rejection once, without amount or reference text', function () {
    $user = e16User();
    $payment = e32_submitted($user, null, '77.25');

    e32_payments()->reject(e32_finance(), $payment, 'proof_unreadable');

    $notes = e32_notes($user, 'support_rejected');
    expect($notes)->toHaveCount(1);
    $raw = json_encode($notes->first(), JSON_UNESCAPED_UNICODE);
    foreach (['77.25', '7725', $payment->reference, 'proof_unreadable'] as $secret) {
        expect(str_contains($raw, $secret))->toBeFalse("leaked: {$secret}");
    }
});

test('E32-59: rejected, cancelled and expired payments never fund, tier or boost anything', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);

    e32_payments()->reject(e32_finance(), e32_submitted(null, $program, '900'), 'not_received');
    e32_payments()->cancel(e32_finance(), e32_submitted(null, $program, '900'), 'طلب مكرر');
    e32_request(null, $program, '900');
    $this->travel(49)->hours();
    e32_payments()->expireStale();

    expect(e32_funding($program))->toBe(0)->and(SponsorshipContribution::query()->count())->toBe(0)->and(SupporterProfile::query()->count())->toBe(0)
        ->and(SponsorshipPayment::query()->pluck('status')->sort()->values()->all())->toBe(['cancelled', 'expired', 'rejected']);
    [$result] = e32_play();
    expect($result['sponsor_bonus'])->toBe(0);
});

test('E32-60: the owner can cancel an awaiting request but not after sending the transfer', function () {
    $user = e16User();
    $awaiting = e32_request($user);

    $this->actingAs($user)->post(route('support.payments.cancel', $awaiting->public_id))->assertRedirect(route('support.history'));
    expect($awaiting->fresh()->status)->toBe('cancelled')->and($awaiting->fresh()->cancelled_by)->toBe($user->id);

    $this->post(route('support.payments.cancel', $awaiting->public_id))->assertRedirect(); // مكرر: لا أثر
    expect(e32_audit('payment_cancelled'))->toBe(1);

    $sent = e32_submitted($user);
    $this->post(route('support.payments.cancel', $sent->public_id))->assertSessionHas('error');
    expect($sent->fresh()->status)->toBe('submitted');
});

test('E32-61: a verified payment cannot be cancelled or rejected arbitrarily - only refunded', function () {
    $user = e16User();
    $verified = e32_verified($user);

    $this->actingAs($user)->post(route('support.payments.cancel', $verified->public_id))->assertSessionHas('error');
    expect(fn () => e32_payments()->cancel(e32_finance(), $verified->fresh(), 'محاولة إلغاء'))->toThrow(PaymentException::class)
        ->and(fn () => e32_payments()->reject(e32_finance(), $verified->fresh(), 'other'))->toThrow(PaymentException::class)
        ->and(fn () => e32_payments()->requestClarification(e32_finance(), $verified->fresh(), 'توضيح مطلوب'))->toThrow(PaymentException::class);

    expect($verified->fresh()->status)->toBe('verified')->and(e32_contribution($verified)->status)->toBe('confirmed');
});

test('E32-61b: clarification returns a submitted request to the owner with a visible message, a fresh deadline and a notification; the owner can resubmit', function () {
    $user = e16User();
    $payment = e32_submitted($user);

    e32_payments()->requestClarification(e32_finance(), $payment, 'أرسل صورة أوضح للإيصال');

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe('awaiting_payment')->and($fresh->user_message)->toBe('أرسل صورة أوضح للإيصال')->and($fresh->expires_at)->not->toBeNull()->and(e32_notes($user, 'support_action_required'))->toHaveCount(1);
    $this->actingAs($user)->get(route('support.payments.show', $payment->public_id))->assertOk()->assertSee('أرسل صورة أوضح للإيصال');

    $again = e32_payments()->markTransferred($user, $fresh, 'صورة جديدة', e32_proof('jpg'));
    expect($again->status)->toBe('submitted')->and(e32_funding($payment->program))->toBe(0);
});

test('E32-62/63: finance refunds a verified payment and the linked contribution becomes refunded in the same step', function () {
    $program = e32_program();
    $payment = e32_verified(null, $program, '40');
    $contribution = e32_contribution($payment);

    $refunded = e32_payments()->refund(e32_finance(), $payment, 'استرداد بطلب الداعم');

    expect($refunded->status)->toBe('refunded')->and($refunded->refunded_at)->not->toBeNull()->and($refunded->refunded_by)->not->toBeNull()
        ->and($contribution->fresh()->status)->toBe('refunded')->and($contribution->fresh()->refunded_at)->not->toBeNull();
    expect(fn () => e32_payments()->refund(e32_finance(), e32_submitted(), 'غير محقق'))->toThrow(PaymentException::class)
        ->and(fn () => e32_payments()->refund(e32_finance(), $payment->fresh(), 'x'))->toThrow(SponsorshipException::class);
});

test('E32-64/69/70: a duplicate refund is safe, audited once and notifies the owner once', function () {
    $user = e16User();
    $payment = e32_verified($user);

    e32_payments()->refund(e32_finance(), $payment, 'استرداد أول');
    e32_payments()->refund(e32_finance(), $payment->fresh(), 'استرداد ثانٍ');

    expect(e32_audit('payment_refunded'))->toBe(1)->and(DB::table('operational_audit_logs')->where('action', 'like', '%contribution%refund%')->count())->toBeGreaterThanOrEqual(1)
        ->and(e32_notes($user, 'support_refunded'))->toHaveCount(1)->and(SponsorshipContribution::query()->where('status', 'refunded')->count())->toBe(1);
    $row = DB::table('operational_audit_logs')->where('action', 'payment_refunded')->first();
    expect($row->actor_user_id)->not->toBeNull();
});

test('E32-65/66/67: a refund lowers the program funding, recalculates the tier and drops the supporter from the leaderboard', function () {
    $program = e32_program();
    $user = e16User();
    $big = e32_verified($user, $program, '300');
    $small = e32_verified($user, $program, '10');
    $profileId = $big->supporter_profile_id;

    $tier = fn () => app(\App\Services\Sponsorship\SupporterTierService::class)->forSupporter($profileId)?->slug;
    expect(e32_funding($program))->toBe(31000)->and($tier())->toBe('gold');

    e32_payments()->refund(e32_finance(), $big, 'استرداد كبير');

    expect(e32_funding($program))->toBe(1000)->and($tier())->toBe('supporter');
    e32_payments()->refund(e32_finance(), $small->fresh(), 'استرداد صغير');
    expect(e32_funding($program))->toBe(0)->and($tier())->toBeNull()
        ->and(app(SupporterLeaderboardService::class)->top('all')['entries'])->toBe([]);
});

test('E32-68: a refund never claws back rewards already granted to players; later solves just lose the boost', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);
    $payment = e32_verified(null, $program, '50'); // 5%

    [$before, $player] = e32_play();
    expect($before['sponsor_bonus'])->toBe(10)->and(e32_gems($player))->toBe(210);
    $bonusRows = SponsorshipRewardBonus::query()->count();

    e32_payments()->refund(e32_finance(), $payment, 'استرداد بعد المكافأة');

    expect(e32_gems($player))->toBe(210)->and(SponsorshipRewardBonus::query()->count())->toBe($bonusRows)->and(SponsorshipRewardBonus::query()->first()->bonus_amount ?? 10)->toBe(10);
    [$after] = e32_play();
    expect($after['sponsor_bonus'])->toBe(0);
});

test('E32-62b: refunding through the E30 contribution screen syncs the payment to refunded and notifies once; a later payment refund is a no-op', function () {
    $user = e16User();
    $payment = e32_verified($user);

    e30_refund(e32_contribution($payment), 'استرداد من شاشة المساهمات');

    expect($payment->fresh()->status)->toBe('refunded')->and(e32_notes($user, 'support_refunded'))->toHaveCount(1)->and(e32_audit('payment_refunded'))->toBe(1);
    e32_payments()->refund(e32_finance(), $payment->fresh(), 'مكرر');
    expect(e32_notes($user, 'support_refunded'))->toHaveCount(1)->and(e32_audit('payment_refunded'))->toBe(1);
});

test('E32-71/72: an anonymous verified payment funds the program and counts for the reward boost', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);

    $payment = e32_verified(null, $program, '30', true);

    expect(e32_funding($program))->toBe(3000)->and(e32_contribution($payment)->is_anonymous)->toBeTrue();
    [$result] = e32_play();
    expect($result['sponsor_bonus'])->toBe(6); // 3% من 200
});

test('E32-73/74/75/109: an anonymous supporter is not identifiable on any public page, search or API route', function () {
    $program = e32_program(['title' => 'برنامج الخصوصية', 'show_public_amounts' => true]);
    $user = e16User(['name' => 'غامض-سري-فريد', 'email' => 'secret-anon-e32@example.test']);
    $payment = e32_verified($user, $program, '77', true);
    $profile = SupporterProfile::query()->findOrFail($payment->supporter_profile_id);

    // صفحات الرعاية والدعم لا يجوز أن تحمل هوية الداعم المجهول. (البحث يجد اللاعب العام باسمه كلاعب - هذا سلوك E27 المستقل - فنفحص فيه ما يخص الدعم فقط.)
    $support = [route('supporters.index'), route('supporters.index', ['scope' => 'program', 'program' => $program->slug]), route('support.index'), route('support.program', $program->slug)];
    $search = [route('search.index', ['q' => 'غامض']), route('search.suggest', ['q' => 'غامض'])];
    $supportNeedles = ['غامض-سري-فريد', 'secret-anon-e32@example.test', $profile->slug, $profile->public_id, $payment->public_id, $payment->reference, 'data-supporter-recognition'];
    $searchNeedles = ['secret-anon-e32@example.test', $profile->slug, $profile->public_id, $payment->public_id, $payment->reference, 'data-supporter-recognition', 'برنامج الخصوصية'];

    foreach ([[$support, $supportNeedles], [$search, $searchNeedles]] as [$urls, $needles]) {
        foreach ($urls as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            foreach ($needles as $needle) {
                expect(str_contains($html, (string) $needle))->toBeFalse("anonymous leak '{$needle}' at {$url}");
            }
        }
    }

    $this->get(route('supporters.show', $profile->slug))->assertNotFound();
    $viewer = e16User();
    $this->actingAs($viewer)->get(route('players.show', $user))->assertOk()->assertDontSee('data-supporter-recognition', false);

    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/')) {
            expect($route->uri())->not->toContain('support')->and($route->uri())->not->toContain('payment');
        }
    }
});

test('E32-76: finance can still resolve the internal request behind an anonymous contribution', function () {
    $user = e16User(['name' => 'صاحب الدعم المجهول']);
    $payment = e32_verified($user, null, '25', true);
    $contribution = e32_contribution($payment);

    $resolved = SponsorshipPayment::query()->where('sponsorship_contribution_id', $contribution->id)->with('user')->first();
    expect($resolved->user->id)->toBe($user->id)->and($resolved->reference)->toBe($contribution->external_reference);

    \Livewire\Livewire::actingAs(e32_finance())->test(\App\Filament\Resources\SponsorshipPaymentResource\Pages\ListSponsorshipPayments::class)
        ->assertCanSeeTableRecords([$payment])->assertSee('صاحب الدعم المجهول');
});
`````

### `tests/Feature/PaymentsE32/E32StaticSecurityTest.php`

`sha256: d9e27c638ba02bc7139e5828d45ca2f53fbcc7ba1f486b703d5c08c2fe88d821`

`````php
<?php

use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_support.php';

/*
| E32 - حراس ثابتة (لا تحتاج بيانات): الوثائق، البيئة، الحزم، المسارات، الواجهات، المخطط، وقواعد التسجيل. أي كسر هنا يعني انحرافًا عن عقد E32.
*/

function e32_file(string $relative): string
{
    return (string) file_get_contents(base_path($relative));
}

/** @return list<string> */
function e32_php_files(string $relativeDir): array
{
    $out = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($relativeDir), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $out[] = $file->getPathname();
        }
    }

    return $out;
}

test('E32-S1: .env.example documents every PAYMENTS_* variable the config reads, with placeholders only and no bank details or secrets', function () {
    $env = e32_file('.env.example');
    $config = e32_file('config/payments.php');
    preg_match_all("/env\\('(PAYMENTS_[A-Z_]+)'/", $config, $m);
    $keys = array_unique($m[1]);

    expect(count($keys))->toBeGreaterThanOrEqual(14);
    foreach ($keys as $key) {
        expect($env)->toContain($key)->and(e32_file('docs/environment-variables.md'))->toContain('`'.$key.'`');
    }

    foreach (['/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/', '/sk_live_|pk_live_|whsec_/', '/\b(?:\d[ -]?){15,16}\b/'] as $pattern) {
        preg_match_all('/^#?\s*PAYMENTS_[A-Z_]+=.*$/m', $env, $lines);
        expect(preg_match($pattern, implode("\n", $lines[0])))->toBe(0, "PAYMENTS_ lines match {$pattern}");
    }

    expect($env)->not->toMatch('/^PAYMENTS_[A-Z_]*(SECRET|KEY|TOKEN|PASSWORD)[A-Z_]*=/m');
});

test('E32-S2: the E32 documents exist with their core sections and claim nothing that was not done', function () {
    $doc = e32_file('docs/sponsorship-payments-e32.md');
    $runbook = e32_file('docs/payment-operations-runbook.md');

    foreach (['المبادئ الصلبة', 'آلة الحالات', 'بنية المزوّدين', 'مسار Webhook المستقبلي', 'إضافة مزوّد إلكتروني لاحقًا', 'التحقق وتجسيد المساهمة', 'الإثبات', 'المطابقة المالية', 'ما لم يُنفَّذ عمدًا', 'MySQL', 'USER-LOCAL MYSQL VALIDATION REQUIRED', 'رحلة الداعم'] as $needle) {
        expect($doc)->toContain($needle);
    }

    foreach (['التحقق (Verify)', 'الرفض (Reject)', 'الاسترداد (Refund)', 'المطابقة المالية', 'تحويل مكرر', 'إثبات مكرر', 'إثبات مفقود', 'اشتباه احتيال', 'حادثة بوابة دفع', 'مال وصل لطلب منتهٍ'] as $needle) {
        expect($runbook)->toContain($needle);
    }

    expect($doc)->not->toContain('تم اختبار MySQL')->and($doc)->toContain('لا بوابة دفع حيّة');
});

test('E32-S3: no payment SDK or card library was added to composer or npm', function () {
    $composer = strtolower(e32_file('composer.json'));
    $package = strtolower(e32_file('package.json'));

    foreach (['stripe', 'paypal', 'paytabs', 'hyperpay', 'myfatoorah', 'braintree', 'mollie', 'razorpay', 'square', 'adyen', 'checkout.com', 'tap-payments', 'cashier'] as $sdk) {
        expect($composer)->not->toContain($sdk)->and($package)->not->toContain($sdk);
    }
});

test('E32-S4: every mutating support route is inside the web group (session + CSRF) behind auth; the webhook is POST-only, throttled, in the api group and not CSRF-excluded', function () {
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();
        $mutating = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [];

        if (str_starts_with($name, 'support.') && $mutating) {
            $checked++;
            expect($route->gatherMiddleware())->toContain('web')->toContain('auth')->and(collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))->toBeTrue("{$name} is not throttled");
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(4);

    $webhook = Route::getRoutes()->getByName('payments.webhook');
    expect($webhook)->not->toBeNull()->and($webhook->uri())->toStartWith('api/')->and($webhook->methods())->toBe(['POST'])->and($webhook->gatherMiddleware())->toContain('api')->and($webhook->gatherMiddleware())->toContain('throttle:payment-webhook')
        ->and($webhook->gatherMiddleware())->not->toContain('web');

    $bootstrap = e32_file('bootstrap/app.php');
    expect($bootstrap)->not->toMatch('/validateCsrfTokens\s*\(\s*except/');
    expect(file_exists(base_path('app/Http/Middleware/VerifyCsrfToken.php')) ? e32_file('app/Http/Middleware/VerifyCsrfToken.php') : '')->not->toContain('payments');
});

test('E32-S5: the support views use escaped output only and never promise payment before verification', function () {
    foreach (glob(base_path('resources/views/support/*.blade.php')) as $view) {
        $code = (string) file_get_contents($view);
        expect($code)->not->toContain('{!!')->and($code)->not->toContain('@include(\'partials.raw');
    }

    $payment = (string) preg_replace('/\{\{--.*?--\}\}/s', '', e32_file('resources/views/support/payment.blade.php'));
    expect($payment)->toContain('بانتظار التحقق')->and($payment)->not->toContain('تم الدفع')->and($payment)->not->toContain('نجحت عملية الدفع');
});

test('E32-S6: the payments schema stores no card data, secrets or floats; money is unsigned integers; index names fit MySQL', function () {
    $migration = e32_file('database/migrations/2026_10_24_000001_create_sponsorship_payments_table.php');

    foreach (['float(', 'double(', 'decimal(', '->json(', 'pan', 'cvv', 'cvc', 'card_number', 'password', 'secret', 'token', 'webhook_body', 'raw_payload', 'email'] as $forbidden) {
        // التعليقات العربية مستثناة: نفحص أسطر الكود فقط
        $code = implode("\n", array_filter(explode("\n", $migration), fn ($l) => str_contains($l, '$table->')));
        expect(str_contains(strtolower($code), '$table->'.$forbidden) || preg_match('/\$table->\w+\(\''.preg_quote($forbidden, '/').'/', $code) === 1)->toBeFalse("column pattern {$forbidden}");
    }

    expect($migration)->toContain("unsignedBigInteger('amount_minor')")->and($migration)->toContain("unsignedBigInteger('accounting_amount_minor')");
    preg_match_all("/,\\s*'([a-z0-9_]+)'\\)/", $migration, $names);
    foreach ($names[1] as $name) {
        expect(strlen($name))->toBeLessThanOrEqual(64);
    }
});

test('E32-S7: payment code never reads whole files, echoes raw requests, logs sensitive words or interpolates SQL', function () {
    $files = array_merge(e32_php_files('app/Services/Payments'), e32_php_files('app/Http/Controllers/Support'), e32_php_files('app/Http/Requests/Support'), e32_php_files('app/Console/Commands'), e32_php_files('app/Filament/Resources/SponsorshipPaymentResource'));
    $files[] = base_path('app/Filament/Resources/SponsorshipPaymentResource.php');
    $files[] = base_path('app/Filament/Pages/PaymentSettingsPage.php');

    foreach ($files as $file) {
        $code = (string) file_get_contents($file);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $code);

        expect($stripped)->not->toMatch('/file_get_contents\s*\(|fopen\s*\(\s*\$|readfile\s*\(/')
            ->and($stripped)->not->toMatch('/\$request->all\(\)|request\(\)->all\(\)|Request::all\(/')
            ->and($stripped)->not->toMatch('/DB::raw\([^)]*\$|whereRaw\([^)]*\{\$|selectRaw\([^)]*\{\$/')
            ->and($stripped)->not->toMatch('/(Log::\w+|logger)\([^;]*(password|secret|token|proof|cvv|iban|email)/i')
            ->and($stripped)->not->toMatch('/\b(dd|dump|var_dump|print_r)\s*\(/');
    }
});

test('E32-S8: only confirmed contributions feed funding, tiers, the leaderboard and the boost - the E30 read side never touches payment requests', function () {
    foreach (array_merge(e32_php_files('app/Services/Sponsorship')) as $file) {
        $code = (string) file_get_contents($file);
        $name = basename($file);

        if ($name === 'ContributionService.php') {
            // الاستثناء الوحيد: خطاف مزامنة الاسترداد (وجهة الطلب بعد استرداد المساهمة) داخل معاملة الاسترداد.
            expect(substr_count($code, 'SponsorshipPaymentService'))->toBeLessThanOrEqual(3);

            continue;
        }

        if ($name === 'SponsorshipNotifier.php') {
            continue;   // إشعارات صاحب الطلب (E32) تقرأ كائن الطلب الممرَّر فقط
        }

        expect($code)->not->toContain('sponsorship_payments')->and($code)->not->toContain('SponsorshipPayment::');
    }

    foreach (['SupportTotals', 'SupporterTierService', 'SupporterLeaderboardService', 'SponsorshipRewardService'] as $class) {
        expect(e32_file("app/Services/Sponsorship/{$class}.php"))->not->toContain('SponsorshipPayment');
    }
});

test('E32-S9: only the manual provider exists, webhooks are closed by default and the proof disk default is private', function () {
    $providers = array_keys((array) config('payments.providers'));
    expect($providers)->toBe(['manual']);

    $source = e32_file('config/payments.php');
    expect($source)->toContain("env('PAYMENTS_WEBHOOKS_ENABLED', false)")->and($source)->toContain("env('PAYMENTS_PROOF_DISK', 'local')")->and($source)->toContain("env('PAYMENTS_DEFAULT_PROVIDER', 'manual')");

    foreach (['PAYMENTS_ENABLED', 'PAYMENTS_WEBHOOKS_ENABLED', 'PAYMENTS_PROOF_DISK', 'PAYMENTS_PROOF_MAX_KB'] as $key) {
        expect($source)->toContain($key);
    }

    expect(config('filesystems.disks.local.visibility'))->not->toBe('public');
});

test('E32-S10: community puzzles and creator series are not part of the payment surface', function () {
    foreach (array_merge(e32_php_files('app/Services/Payments'), e32_php_files('app/Http/Controllers/Support')) as $file) {
        $code = (string) file_get_contents($file);
        expect($code)->not->toContain('CommunityPuzzle')->and($code)->not->toContain('CreatorSeries')->and($code)->not->toContain('creator_series');
    }

    foreach (array_keys(Route::getRoutes()->getRoutesByName()) as $name) {
        if (str_starts_with((string) $name, 'support.')) {
            expect($name)->not->toContain('series')->and($name)->not->toContain('community');
        }
    }
});
`````

### `tests/Feature/PaymentsE32/E32VerificationTest.php`

`sha256: a2e37322d56213f6ebb14ec39b63d03cd397595180f2d1dea2bbec302368ebc1`

`````php
<?php

use App\Models\Puzzle;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\PuzzleAttemptService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SupporterLeaderboardService;
use App\Services\Sponsorship\SupporterService;
use App\Services\Sponsorship\SupporterTierService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/_support.php';

/*
| E32 (AK4 + AK5) - التحقق المالي الذرّي (طلب → مساهمة مؤكَّدة) وعزل غير المحقَّق: لا تمويل/مستوى/صدارة/تعزيز قبل التحقق.
*/

beforeEach(function () {
    e32_seed();
    config(['gems.daily_earn_cap' => 100000]);
});

function e32_tier(?int $supporterId): ?string
{
    return $supporterId === null ? null : app(SupporterTierService::class)->forSupporter($supporterId)?->slug;
}

function e32_solve(?User $user = null, int $reward = 200): array
{
    $user ??= User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => $reward]);

    return [app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح'), $user];
}

test('E32-36: an ordinary user and a content manager (no sponsorships.confirm) cannot verify, reject, refund or cancel', function () {
    $payment = e32_submitted();

    foreach ([e16User(), e32_manager()] as $who) {
        expect(fn () => e32_payments()->verify($who, $payment))->toThrow(HttpException::class)
            ->and(fn () => e32_payments()->reject($who, $payment, 'other'))->toThrow(HttpException::class)
            ->and(fn () => e32_payments()->cancel($who, $payment, 'سبب كافٍ'))->toThrow(HttpException::class)
            ->and(fn () => e32_payments()->requestClarification($who, $payment, 'سبب كافٍ'))->toThrow(HttpException::class)
            ->and(fn () => e32_payments()->refund($who, $payment, 'سبب كافٍ'))->toThrow(HttpException::class);
    }

    expect($payment->fresh()->status)->toBe('submitted')->and(SponsorshipContribution::query()->count())->toBe(0);
});

test('E32-37: finance (sponsorships.confirm) can verify, and a public user cannot confirm their own payment by any route', function () {
    $user = e16User();
    $payment = e32_submitted($user);

    $this->actingAs($user);
    foreach (['verify', 'confirm', 'approve'] as $action) {
        $this->post('/support/payments/'.$payment->public_id.'/'.$action)->assertNotFound();
    }
    $this->post(route('support.payments.transferred', $payment->public_id), ['status' => 'verified'])->assertRedirect();
    expect($payment->fresh()->status)->toBe('submitted');

    $verified = e32_payments()->verify(e32_finance(), $payment);
    expect($verified->status)->toBe('verified')->and($verified->verified_by)->not->toBeNull();
});

test('E32-38/39: verify creates one confirmed contribution linked to the payment with the same reference', function () {
    $program = e32_program();
    $payment = e32_submitted(null, $program, '37.50');

    $verified = e32_payments()->verify(e32_finance(), $payment);
    $contribution = e32_contribution($verified);

    expect($contribution)->not->toBeNull()->and($contribution->status)->toBe('confirmed')->and($contribution->sponsorship_program_id)->toBe($program->id)
        ->and($contribution->provider)->toBe('manual')->and($contribution->external_reference)->toBe($payment->reference)->and($contribution->reference)->toBe($payment->reference)
        ->and($verified->sponsorship_contribution_id)->toBe($contribution->id)->and($verified->verified_at)->not->toBeNull()
        ->and(SponsorshipContribution::query()->count())->toBe(1);
});

test('E32-40: the supporter profile is created safely once, reused when it exists, and an anonymous-first profile stays private', function () {
    $program = e32_program();
    $user = e16User();
    expect(SupporterProfile::query()->where('user_id', $user->id)->count())->toBe(0);

    $first = e32_verified($user, $program, '10', false);
    $profile = SupporterProfile::query()->where('user_id', $user->id)->firstOrFail();
    expect($first->supporter_profile_id)->toBe($profile->id)->and($profile->type)->toBe('individual')->and((bool) $profile->is_public)->toBeTrue();

    $second = e32_verified($user, $program, '5', false);
    expect(SupporterProfile::query()->where('user_id', $user->id)->count())->toBe(1)->and($second->supporter_profile_id)->toBe($profile->id);

    // مستخدم مرتبط مسبقًا بملف أنشأته الإدارة: يُعاد استعماله ولا يُنشأ ثانٍ.
    $linkedUser = e16User();
    $adminProfile = e30_supporter(['user_id' => $linkedUser->id, 'display_name' => 'ملف الإدارة']);
    $third = e32_verified($linkedUser, $program, '8', false);
    expect(SupporterProfile::query()->where('user_id', $linkedUser->id)->count())->toBe(1)->and($third->supporter_profile_id)->toBe($adminProfile->id);

    // دعم مجهول أولًا: ملف خاص، ولا يتحول علنيًا إلا بقرار علني لاحق.
    $anon = e16User();
    $a = e32_verified($anon, $program, '12', true);
    $anonProfile = SupporterProfile::query()->findOrFail($a->supporter_profile_id);
    expect((bool) $anonProfile->is_public)->toBeFalse();

    e32_verified($anon, $program, '12', false);
    expect((bool) $anonProfile->fresh()->is_public)->toBeTrue()->and(SupporterProfile::query()->where('user_id', $anon->id)->count())->toBe(1);
});

test('E32-41: verification is audited (payment_verified and contribution_materialized) with the actor', function () {
    $finance = e32_finance();
    $payment = e32_submitted();

    e32_payments()->verify($finance, $payment);

    expect(e32_audit('payment_verified'))->toBe(1)->and(e32_audit('contribution_materialized'))->toBe(1);
    $row = DB::table('operational_audit_logs')->where('action', 'payment_verified')->first();
    expect($row->actor_user_id)->toBe($finance->id);
    $raw = (string) $row->metadata;
    foreach (['proof_path', 'proof_sha256', 'payer_note', 'review_note'] as $forbidden) {
        expect(str_contains($raw, $forbidden))->toBeFalse("audit leaked {$forbidden}");
    }
});

test('E32-42: a second verify (double click / retry) does not duplicate anything', function () {
    $program = e32_program();
    $payment = e32_submitted(null, $program, '20');

    e32_payments()->verify(e32_finance(), $payment);
    $again = e32_payments()->verify(e32_finance(), $payment->fresh());

    expect($again->status)->toBe('verified')->and(SponsorshipContribution::query()->count())->toBe(1)->and(e32_funding($program))->toBe(2000)
        ->and(e32_audit('payment_verified'))->toBe(1)->and(e32_notes($payment->user, 'support_confirmed'))->toHaveCount(1);
});

test('E32-43: the confirmation notification is dispatched strictly after the verification transaction commits', function () {
    $user = e16User();
    $payment = e32_submitted($user);
    $baseline = DB::transactionLevel();
    $levels = new ArrayObject;

    app()->extend(NotificationDispatcher::class, fn ($real) => new class($real, $levels) extends NotificationDispatcher
    {
        public function __construct(protected $real, protected ArrayObject $levels) {}

        public function dispatch(User $user, \App\Services\Notifications\NotificationType $type, array $params, string $idempotencyKey, array $refs = [], array $actionParams = [], ?string $actionRoute = null): \App\Services\Notifications\DispatchResult
        {
            $this->levels[] = DB::transactionLevel();

            return $this->real->dispatch($user, $type, $params, $idempotencyKey, $refs, $actionParams, $actionRoute);
        }
    });
    app()->forgetInstance(\App\Services\Sponsorship\SponsorshipNotifier::class);
    app()->forgetInstance(\App\Services\Sponsorship\ContributionService::class);
    app()->forgetInstance(\App\Services\Payments\SponsorshipPaymentService::class);

    e32_payments()->verify(e32_finance(), $payment);

    expect(e32_notes($user, 'support_confirmed'))->toHaveCount(1)->and(count($levels))->toBeGreaterThan(0);
    foreach ($levels as $level) {
        expect($level)->toBe($baseline);
    }
});

test('E32-44: a notification outage never rolls back the verified payment, the contribution or the funding', function () {
    $program = e32_program();
    $user = e16User();
    $payment = e32_submitted($user, $program, '30');

    app()->bind(NotificationDispatcher::class, fn () => new class extends NotificationDispatcher
    {
        public function __construct() {}

        public function dispatch(User $user, \App\Services\Notifications\NotificationType $type, array $params, string $idempotencyKey, array $refs = [], array $actionParams = [], ?string $actionRoute = null): \App\Services\Notifications\DispatchResult
        {
            throw new RuntimeException('simulated notification outage');
        }
    });
    app()->forgetInstance(\App\Services\Sponsorship\SponsorshipNotifier::class);
    app()->forgetInstance(\App\Services\Sponsorship\ContributionService::class);
    app()->forgetInstance(\App\Services\Payments\SponsorshipPaymentService::class);

    $verified = e32_payments()->verify(e32_finance(), $payment);

    expect($verified->status)->toBe('verified')->and($payment->fresh()->status)->toBe('verified')->and(e32_funding($program))->toBe(3000)->and(e32_audit('payment_verified'))->toBe(1)
        ->and(e32_notes($user, 'support_confirmed'))->toHaveCount(0);
});

test('E32-45: a same-currency payment keeps the accounting currency; a foreign currency needs an explicit accounting equivalent that is preserved', function () {
    $program = e32_program();

    $usd = e32_verified(null, $program, '15');
    $usdContribution = e32_contribution($usd);
    expect($usd->accounting_currency)->toBe('USD')->and($usd->accounting_amount_minor)->toBe(1500)->and($usdContribution->accounting_amount_minor)->toBe(1500);

    $eurUser = e16User();
    $eur = e32_request($eurUser, $program, '10', false, 'EUR');
    $eur = e32_payments()->markTransferred($eurUser, $eur, 'يورو');

    expect(fn () => e32_payments()->verify(e32_finance(), $eur))->toThrow(SponsorshipException::class);
    expect($eur->fresh()->status)->toBe('submitted')->and(SponsorshipContribution::query()->count())->toBe(1);

    $done = e32_payments()->verify(e32_finance(), $eur->fresh(), '11.50');
    $contribution = e32_contribution($done);
    expect($done->currency)->toBe('EUR')->and($done->amount_minor)->toBe(1000)->and($done->accounting_currency)->toBe('USD')->and($done->accounting_amount_minor)->toBe(1150)
        ->and($contribution->currency)->toBe('EUR')->and($contribution->original_amount_minor)->toBe(1000)->and($contribution->accounting_amount_minor)->toBe(1150)
        ->and(e32_funding($program))->toBe(2650);
});

test('E32-46: amounts are exact integers end to end (no float drift)', function () {
    $payment = e32_verified(null, null, '1.07');
    $contribution = e32_contribution($payment);

    expect($payment->amount_minor)->toBeInt()->toBe(107)->and($contribution->original_amount_minor)->toBe(107)->and($contribution->accounting_amount_minor)->toBe(107);

    $big = e32_verified(null, null, '1234567.89');
    expect(e32_contribution($big)->accounting_amount_minor)->toBe(123456789);
});

test('E32-47: a verified payment appears in the program funding and on the public program page', function () {
    $program = e32_program(['title' => 'برنامج التمويل', 'goal_amount' => '1000', 'show_goal_progress' => true]);
    expect(e32_funding($program))->toBe(0);

    e32_verified(null, $program, '250');

    expect(e32_funding($program))->toBe(25000);
    $this->get(route('support.program', $program->slug))->assertOk()->assertSee('برنامج التمويل');
});

test('E32-48: the supporter tier updates only on verification', function () {
    $program = e32_program();
    $user = e16User();
    $payment = e32_submitted($user, $program, '60');
    expect(SupporterProfile::query()->where('user_id', $user->id)->exists())->toBeFalse();

    $verified = e32_payments()->verify(e32_finance(), $payment);
    expect(e32_tier($verified->supporter_profile_id))->toBe('silver');

    e32_verified($user, $program, '300');
    expect(e32_tier($verified->supporter_profile_id))->toBe('gold');
});

test('E32-49: the supporter leaderboard updates only on verification', function () {
    $program = e32_program();
    $small = e32_verified(e16User(['name' => 'داعم صغير']), $program, '5', false);
    $payment = e32_submitted(e16User(['name' => 'داعم كبير']), $program, '900', false);

    $ids = fn () => array_map(fn ($e) => $e['supporter']->id, app(SupporterLeaderboardService::class)->top('all')['entries']);
    expect($ids())->toBe([$small->supporter_profile_id]);

    $verified = e32_payments()->verify(e32_finance(), $payment);
    expect($ids())->toBe([$verified->supporter_profile_id, $small->supporter_profile_id]);
});

test('E32-50: the official reward boost sees verified funding and adds exactly the planned bonus', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000); // 1% لكل 10$ بسقف 10%
    [$before] = e32_solve();
    expect($before['sponsor_bonus'])->toBe(0);

    e32_verified(null, $program, '20');
    [$after] = e32_solve();

    expect($after['sponsor_bonus'])->toBe(4); // 2% من 200
});

test('E32-51/52: awaiting and submitted payments are not funding', function () {
    $program = e32_program(['goal_amount' => '1000', 'show_goal_progress' => true]);

    e32_request(null, $program, '500');
    e32_submitted(null, $program, '700');

    expect(e32_funding($program))->toBe(0)->and(app(\App\Services\Sponsorship\SupportTotals::class)->confirmedCount($program->id))->toBe(0)->and(SponsorshipContribution::query()->count())->toBe(0);
});

test('E32-53: awaiting and submitted payments do not change the supporter tier', function () {
    $program = e32_program();
    $user = e16User();
    $profile = e30_supporter(['user_id' => $user->id]);
    e30_confirmed($profile, $program, '5');
    expect(e32_tier($profile->id))->toBe('supporter');

    e32_request($user, $program, '5000');
    e32_submitted($user, $program, '5000');

    expect(e32_tier($profile->id))->toBe('supporter');
});

test('E32-54: awaiting and submitted payments do not enter or move the leaderboard', function () {
    $program = e32_program();
    $leader = e30_supporter(['display_name' => 'المتصدر']);
    e30_confirmed($leader, $program, '50');

    $user = e16User();
    $mine = e30_supporter(['user_id' => $user->id, 'display_name' => 'أنا']);
    e30_confirmed($mine, $program, '10');
    e32_submitted($user, $program, '9999');

    $entries = app(SupporterLeaderboardService::class)->top('all')['entries'];
    expect(array_map(fn ($e) => $e['supporter']->id, $entries))->toBe([$leader->id, $mine->id]);
});

test('E32-55: awaiting and submitted payments add no reward boost', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);

    e32_request(null, $program, '5000');
    e32_submitted(null, $program, '5000');
    [$result] = e32_solve();

    expect($result['sponsor_bonus'])->toBe(0);
});

test('E32-97: a failure while materializing the contribution rolls EVERYTHING back (no profile, no contribution, same status)', function () {
    $program = e32_program();
    $user = e16User();
    $payment = e32_submitted($user, $program, '40');
    app(SponsorshipProgramService::class)->close(e30_admin(), $program->fresh()); // confirmLocked يرفض البرنامج المغلق بعد إنشاء المساهمة داخل المعاملة

    expect(fn () => e32_payments()->verify(e32_finance(), $payment))->toThrow(SponsorshipException::class);

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe('submitted')->and($fresh->sponsorship_contribution_id)->toBeNull()->and($fresh->verified_at)->toBeNull()
        ->and(SponsorshipContribution::query()->count())->toBe(0)->and(SupporterProfile::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(e32_audit('payment_verified'))->toBe(0)->and(e32_notes($user, 'support_confirmed'))->toHaveCount(0);
});

test('E32-40b: a suspended supporter profile blocks new requests and verification', function () {
    $program = e32_program();
    $user = e16User();
    $profile = e30_supporter(['user_id' => $user->id]);
    $payment = e32_submitted($user, $program, '10');

    app(SupporterService::class)->setStatus(e30_admin(), $profile, SupporterProfile::STATUS_SUSPENDED);

    expect(fn () => e32_payments()->verify(e32_finance(), $payment))->toThrow(SponsorshipException::class)
        ->and(fn () => e32_request($user, $program, '10'))->toThrow(SponsorshipException::class)
        ->and($payment->fresh()->status)->toBe('submitted')->and(SponsorshipContribution::query()->count())->toBe(0);
});

test('E32-36b: an expired request is final and finance cannot verify it (a new request or an E30 manual record is the path)', function () {
    $payment = e32_request();
    $this->travel(49)->hours();
    expect(e32_payments()->expireStale())->toBe(1);

    expect(fn () => e32_payments()->verify(e32_finance(), $payment->fresh()))->toThrow(SponsorshipException::class)->and(SponsorshipContribution::query()->count())->toBe(0);
});

test('E32-37b: finance may verify straight from awaiting_payment (money already seen) and the state machine allows it', function () {
    $payment = e32_request(null, null, '10');

    $verified = e32_payments()->verify(e32_finance(), $payment);

    expect($verified->status)->toBe('verified')->and(SponsorshipContribution::query()->count())->toBe(1);
});
`````

### `tests/Feature/PaymentsE32/_support.php`

`sha256: 28fd602ed227bef9234ed826ae10c0e7a08a73512428bacb902a86364b221c59`

`````php
<?php

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use App\Models\SponsorshipProgram;
use App\Models\User;
use App\Services\Payments\SponsorshipPaymentService;
use App\Services\Sponsorship\SupportTotals;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| E32 (الدفع) - مساعدات مشتركة. كل شيء على SQLite وكاش array وقرص تخزين وهمي؛ لا شبكة ولا بوابة دفع ولا بيانات بنكية.
| كل تهيئة تمرّ بالخدمات الرسمية (SponsorshipPaymentService)؛ لا كتابة مباشرة بحالة أو مبلغ إلا عند اختبار الحراسة نفسها.
| تعتمد على مساعدات E30 (e30_*) و E28 (e28_*) و E16 (e16User).
*/

require_once __DIR__.'/../SponsorshipE30/_support.php';

if (! function_exists('e32_seed')) {
    function e32_seed(): void
    {
        e30_seed();
        Storage::fake('local');
        Cache::flush();
        config(['payments.enabled' => true, 'payments.notifications.enabled' => true]);
    }

    function e32_payments(): SponsorshipPaymentService
    {
        return app(SponsorshipPaymentService::class);
    }

    /** مدير مالي (sponsorships.confirm). */
    function e32_finance(): User
    {
        return e30_admin();
    }

    /** مدير محتوى: يرى ويدير الرعاية بلا تأكيد مالي. */
    function e32_manager(): User
    {
        return e30_manager();
    }

    function e32_program(array $over = [], bool $activate = true): SponsorshipProgram
    {
        return e30_program($over, $activate);
    }

    /** طلب دعم بانتظار الدفع بالخدمة الرسمية. */
    function e32_request(?User $user = null, ?SponsorshipProgram $program = null, string|int $amount = '10', bool $anonymous = false, string $currency = 'USD', ?string $key = null, ?string $name = null): SponsorshipPayment
    {
        $user ??= e16User();
        $program ??= e32_program();

        return e32_payments()->createRequest($user, $program, $amount, $currency, $anonymous, $name, 'manual', $key);
    }

    /** طلب مُرسَل (حوّلت المبلغ) مع إثبات اختياري. */
    function e32_submitted(?User $user = null, ?SponsorshipProgram $program = null, string|int $amount = '10', bool $anonymous = false, bool $withProof = true): SponsorshipPayment
    {
        $user ??= e16User();
        $payment = e32_request($user, $program, $amount, $anonymous);

        return e32_payments()->markTransferred($user, $payment, 'حوّلت من حسابي', $withProof ? e32_proof() : null);
    }

    /** طلب محقَّق (مساهمة مؤكَّدة مجسَّدة). */
    function e32_verified(?User $user = null, ?SponsorshipProgram $program = null, string|int $amount = '10', bool $anonymous = false): SponsorshipPayment
    {
        $payment = e32_submitted($user, $program, $amount, $anonymous);

        return e32_payments()->verify(e32_finance(), $payment);
    }

    /** إثبات وهمي: png | jpg | webp | pdf | svg | exe | html | big. */
    function e32_proof(string $kind = 'png'): UploadedFile
    {
        return match ($kind) {
            'png' => UploadedFile::fake()->image('proof.png', 20, 20),
            'jpg' => UploadedFile::fake()->image('proof.jpg', 20, 20),
            'webp' => e32_webp(),
            'pdf' => UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"),
            'svg' => UploadedFile::fake()->createWithContent('proof.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'exe' => UploadedFile::fake()->createWithContent('proof.png', "MZ\x90\x00\x03\x00\x00\x00 fake executable payload"),
            'html' => UploadedFile::fake()->createWithContent('proof.pdf', '<html><script>alert(1)</script></html>'),
            'big' => UploadedFile::fake()->create('proof.pdf', 6000, 'application/pdf'),
            default => throw new InvalidArgumentException($kind),
        };
    }

    function e32_webp(): UploadedFile
    {
        $image = imagecreatetruecolor(16, 16);
        $path = tempnam(sys_get_temp_dir(), 'e32w');
        imagewebp($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'proof.webp', 'image/webp', null, true);
    }

    function e32_funding(SponsorshipProgram $program): int
    {
        return app(SupportTotals::class)->programFunding($program->id);
    }

    function e32_contribution(SponsorshipPayment $payment): ?SponsorshipContribution
    {
        return SponsorshipContribution::query()->find($payment->fresh()->sponsorship_contribution_id);
    }

    function e32_audit(string $action): int
    {
        return DB::table('operational_audit_logs')->where('action', $action)->count();
    }

    /** إشعارات E15 لمستخدم (اختياريًا بنوع). */
    function e32_notes(User $user, ?string $type = null): \Illuminate\Support\Collection
    {
        return DB::table('notifications')->where('notifiable_id', $user->id)->when($type, fn ($q) => $q->where('type_key', $type))->orderBy('created_at')->get();
    }
}
`````

---

## B) MODIFIED FILES — ملفات كاملة (13 ملفًا)

كلها نسخ نهائية كاملة تُستبدل بها ملفاتك (لا باتشات: مرجع v31 محليّ غير متاح لي لإنتاج diff دقيق). كل موضع تعديل موسوم بتعليق `E32`؛ أدناه خريطة المواضع ثم الملف الكامل.

#### خريطة تعديلات `.env.example`

  - السطر 216: `# E32 - دفع الرعاية والعمليات المالية (كلها اختيارية؛ الافتراضيات آمنة). لا أسرار ولا بيانات بنكية هنا: تفاصيل التحويل تُدار من لوحة الإدارة (إعدادات `

### `.env.example`

`sha256: 4bd7701a3d4e01ae835306fb7cb6de11e62c23b24090457faed4d7e3f876e732`

`````dotenv
APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file
# APP_MAINTENANCE_STORE=database

PHP_CLI_SERVER_WORKERS=4

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

# E31: MySQL 8.0.16+ هي قاعدة الإنتاج المعتمدة (docs/mysql-production-e31.md). SQLite تبقى لاختبارات سريعة وCI (phpunit.xml.dist) وللتطوير الخفيف.
# للتطوير الخفيف على SQLite بدل MySQL: DB_CONNECTION=sqlite واحذف الأسطر التالية حتى DB_TIMEZONE (يُنشأ database/database.sqlite بـphp artisan migrate).
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ahjiyat_platform
DB_USERNAME=ahjiyat_app
DB_PASSWORD=
DB_TIMEZONE=+00:00
# DB_ENGINE=InnoDB

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=reverb

# E21: Laravel Reverb (خادم WebSocket من الطرف الأول). عبّئ القيم محليًا/بالإنتاج: لا أسرار حقيقية هنا.
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database
# CACHE_PREFIX=

MEMCACHED_HOST=127.0.0.1

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

VITE_APP_NAME="${APP_NAME}"

# ==============================================================================
# E24: الأداء / التوسّع / الموثوقية / المراقبة  (كلها اختيارية؛ الافتراضات تحفظ سلوك المنتج كما هو)
# ==============================================================================
# CACHE_STORE=database يتطلب جدولَي cache وcache_locks (تنشئهما migration 2026_10_18_000001 بعد php artisan migrate).
# توصية الإنتاج متعدد الخوادم (توصية بنية تحتية، غير مفروضة محليًا): CACHE_STORE=redis و SESSION_DRIVER=redis و QUEUE_CONNECTION=redis
# مع REDIS_CLIENT=phpredis؛ أبقِ الجلسات/الكاش/الطوابير على قواعد Redis (DB) منفصلة. المجدول onOneServer يحتاج كاشًا مشتركًا بين الخوادم.
# DB_QUEUE_RETRY_AFTER=90          # يجب أن يظل أكبر من timeout أي Job (80ث) كي لا تُلتقط مهمة ما زالت تعمل
# REDIS_QUEUE_RETRY_AFTER=90

# خلف موازن حِمل/وكيل عكسي: بدونها يرى التطبيق IP الوكيل بدل IP العميل (يفسد حدود المعدّل وبصمات الاحتيال).
# فارغ/غير معرَّف = لا وكلاء موثوقة (آمن افتراضيًا)؛ "*" = كل الوكلاء (فقط إن كان التطبيق غير قابل للوصول إلا عبر الموازن)؛ أو قائمة IP/CIDR مفصولة بفواصل.
# TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12

# المجدول: نفّذ كل مهمة على خادم واحد فقط عند تعدّد الخوادم (يحتاج كاشًا مشتركًا). على خادم واحد لا أثر له.
# PERF_SCHEDULER_ONE_SERVER=true

# تتبّع الجهاز (الاحتيال): مرة كل N ثانية لكل (مستخدم، IP، جهاز) بدل كل طلب. 0 = السلوك القديم (كل طلب).
# PERF_DEVICE_SIGHTING_THROTTLE=60

# مدد الكاش للعرض فقط (ثوانٍ، محدودة دائمًا). لا تُخزَّن بيانات صلاحيات أو أرصدة أو مكافآت.
# PERF_LEVEL_CATALOG_TTL=3600
# PERF_BRANDING_URLS_TTL=600
# PERF_LEADERBOARD_TTL=60

# المراقبة: معرّف الطلب X-Request-ID، وسجل الطلبات البطيئة (لا أجسام/كوكيز/رؤوس/سلسلة استعلام). 0 = تعطيل السجل البطيء.
# PERF_REQUEST_ID=true
# PERF_SLOW_REQUEST_MS=1500
# PERF_SLOW_REQUEST_SAMPLE=1.0
# فحص الجاهزية GET /ready (قاعدة البيانات + الكاش) ومهلته؛ الرمز اختياري (الرأس X-Health-Token). GET /up الأصلي للحياة فقط.
# PERF_READINESS_TIMEOUT_MS=1500
# PERF_READINESS_TOKEN=

# مهل اتصال PHP -> Reverb للبثّ المتزامن (ثوانٍ). قصيرة كي لا يحجز Reverb المعلّق عامل PHP-FPM ثلاثين ثانية.
# REVERB_HTTP_CONNECT_TIMEOUT=1
# REVERB_HTTP_TIMEOUT=3

# ==============================================================================
# E25: التشغيل بالإنتاج / الأمان / النشر / الاستعادة  (كلها اختيارية؛ الافتراضات تحفظ سلوك المنتج كما هو)
# القيم هنا placeholders فقط: لا أسرار حقيقية بهذا الملف ولا بأي ملف بالمستودع. أسرار الإنتاج من مدير أسرار المزوّد أو متغيرات بيئة محميّة (docs/environment-variables.md).
# ==============================================================================
# ملخص عقد الإنتاج (php artisan production:check يفحصه ويعطي PASS/WARN/FAIL بلا طباعة أي قيمة سرية):
#   APP_ENV=production  APP_DEBUG=false  APP_URL=https://<نطاقك>  SESSION_SECURE_COOKIE=true
#   DB_CONNECTION=mysql (أو pgsql)  QUEUE_CONNECTION=database|redis  MAIL_MAILER=smtp  LOG_STACK=daily
#   BROADCAST_CONNECTION=reverb مع REVERB_SCHEME=https و REVERB_HOST=<نطاق> و REVERB_ALLOWED_ORIGINS=<نطاقك>

# كوكي الجلسة: إلزامي true بالإنتاج على HTTPS (يمنع إرسالها عبر HTTP). محليًا اتركه غير مضبوط.
# SESSION_SECURE_COOKIE=true
# SESSION_HTTP_ONLY=true
# SESSION_SAME_SITE=lax

# هوية الإصدار (اختيارية): تُضبط عند كل نشر (SHA مختصر أو وسم). تظهر في /ready ومركز العمليات وكل سطر سجل (release). الصيغة [A-Za-z0-9._-] حتى 64 خانة.
# APP_RELEASE=
# RELEASE_SHA=

# السجلات: بالإنتاج استعمل daily مع احتفاظ محدود (القناة single بلا تدوير ولا حد حجم). LOG_LEVEL=info أو warning بدل debug.
# LOG_STACK=daily
# LOG_DAILY_DAYS=14

# Reverb: قيّد الأصول المسموحة لاتصالات WebSocket (أسماء مضيفين مفصولة بفواصل، بلا http://). الافتراضي * (يسمح للجميع).
# REVERB_ALLOWED_ORIGINS=ahjiyat.example.com

# أول مدير بالإنتاج فقط (AdminUserSeeder): لا كلمة مرور افتراضية بالإنتاج. ضعهما مؤقتًا بالبيئة لأول تشغيل ثم احذفهما فورًا. ≥ 12 خانة.
# ADMIN_BOOTSTRAP_EMAIL=
# ADMIN_BOOTSTRAP_PASSWORD=

# نبض المجدول وتنبيهات الطابور (ثوانٍ؛ كلها اختيارية).
# OPS_SCHEDULER_STALE_SECONDS=300
# OPS_SCHEDULER_DOWN_SECONDS=900
# OPS_READY_FAILS_ON_SCHEDULER=false     # true: /ready يفشل فقط عند حالة down (لا عند التأخر البسيط ولا بعد نشر جديد)
# OPS_QUEUE_BACKLOG_WARN_SECONDS=900
# OPS_FAILED_JOBS_WARN=1

# الأوامر الهدّامة (db:wipe, migrate:fresh|refresh|reset|rollback) ممنوعة بالإنتاج. استثناء واعٍ لعملية واحدة فقط (لا تضعه بملف .env الدائم):
# OPS_ALLOW_DESTRUCTIVE_COMMANDS=false

# E26 - أمان التطبيق (كلها اختيارية؛ الافتراضيات آمنة). انظر docs/security-e26.md.
# SECURITY_LOGIN_MAX_ATTEMPTS=5              # محاولات دخول فاشلة لكل (بريد+IP) قبل القفل المؤقت
# SECURITY_LOGIN_DECAY_SECONDS=300           # مدة القفل بالثواني
# SECURITY_LOGIN_IP_MAX_ATTEMPTS=30          # سقف الفشل لكل IP (رش كلمات المرور). أعلى بكثير كي لا يضرّ شبكة مشتركة
# SECURITY_API_EXPIRY_MINUTES=43200       # عمر رمز API (30 يومًا). 0 = بلا انتهاء (غير مستحسن)
# SECURITY_CSP_BASELINE=true                 # CSP أساسي (frame-ancestors/base-uri/object-src) لا يمسّ تنفيذ السكربتات
# REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM=none  # أحداث العميل مغلقة (التطبيق لا يستعملها)
# SECURITY_TRUST_HOSTS=true                  # يقيّد Host بمضيف APP_URL بالإنتاج (يمنع تسميم روابط إعادة التعيين). تأكد أن APP_URL هو النطاق الفعلي
# SECURITY_TRUSTED_HOSTS=www.example.com     # مضيفات إضافية مسموحة (مفصولة بفواصل)

# E27 - البحث العام والاكتشاف والرائج والتوصيات (كلها اختيارية؛ الافتراضيات آمنة ولا تتطلب شيئًا). انظر docs/search-discovery-e27.md.
# SEARCH_DRIVER=database                     # السائق الوحيد حاليًا. قيمة غير معروفة تسقط إلى database (لا تعطّل الموقع). محرك خارجي = تنفيذ جديد لـSearchBackend
# SEARCH_MIN_LENGTH=2                        # أقل طول للمصطلح (حروف بعد التطبيع)
# SEARCH_MIN_LENGTH_GUEST_PLAYERS=3          # الضيف يحتاج 3 أحرف للبحث عن لاعبين (يحدّ التخمين)
# SEARCH_PARTIAL_MAX_ID=10000                # أكبر معرّف بجدول يُسمح عنده بالمطابقة الجزئية (وسط الكلمة/الحروف العربية المتقاربة). فوقه: بادئة العمود فقط. هذا مؤشر الانتقال لمحرك بحث/FULLTEXT
# SEARCH_TRENDING_WINDOW_DAYS=7              # نافذة «الرائج»
# SEARCH_TRENDING_TTL=300                    # عمر لقطة الرائج بالثواني (معرّفات ودرجات فقط)
# SEARCH_TRENDING_MIN_PLAYERS=2              # أقل عدد لاعبين مميَّزين كي تظهر أحجية بالرائج

# E28 - منظومة المبدعين وأحجيات المجتمع (كلها اختيارية؛ الافتراضيات آمنة ولا تتطلب شيئًا). انظر docs/creator-ecosystem-e28.md.
# أحجيات المجتمع بلا مكافآت اقتصادية بأي إعداد (جواهر/XP = 0 ثابت بالكود، لا مفتاح بيئة له).
# CREATOR_PUZZLE_MAX_ATTEMPTS=3              # عدد محاولات اللاعب لكل أحجية مجتمع (سيرفري، لا يحدده المبدع)
# CREATOR_MAX_WORKS=100                      # سقف أعمال المبدع الواحد
# CREATOR_MAX_OPEN_DRAFTS=20                 # سقف المسودات المفتوحة لكل مبدع
# CREATOR_MAX_PENDING=5                      # سقف الأعمال قيد المراجعة معًا لكل مبدع
# CREATOR_IMAGE_MAX_KB=4096                  # أقصى حجم صورة أحجية (كيلوبايت). JPEG/PNG/WEBP فقط، لا SVG
# CREATOR_ANALYTICS_TTL=120                  # عمر لقطة تحليلات المبدع بالثواني (أرقام مجمَّعة فقط)
# CREATOR_TRENDING_MIN_PLAYERS=2             # أقل عدد لاعبين مميَّزين كي يظهر عمل بقسم «رائج من المجتمع»

# E29 - التفاعل المجتمعي: التقييمات والمفضلة ومتابعة المبدعين والمجموعات الشخصية والأعلى تقييمًا (كلها اختيارية؛ الافتراضيات آمنة). انظر docs/community-engagement-e29.md.
# التفاعل بلا أي سلطة اقتصادية بأي إعداد (لا جواهر/XP/مهام/سلسلة/إنجازات/صدارة): لا مفتاح بيئة لذلك.
# ENGAGEMENT_RATING_DISPLAY_MIN=3            # أقل عدد تقييمات لإظهار «المتوسط» علنًا (تحته يُعرض العدد فقط)
# ENGAGEMENT_TOP_MIN_RATINGS=5               # أقل عدد تقييمات كي يدخل العمل ترتيب «الأعلى تقييمًا»
# ENGAGEMENT_TOP_PRIOR_MEAN=3.5              # المتوسط الافتراضي للانكماش البايزي: score = (sum + mean*weight) / (count + weight)
# ENGAGEMENT_TOP_PRIOR_WEIGHT=5              # وزن المتوسط الافتراضي (كم «تقييمًا وهميًا»)؛ كلما كبر احتاج الوصول للقمة تقييمات أكثر
# ENGAGEMENT_TOP_CACHE_TTL=300               # عمر لقطة الأعلى تقييمًا بالثواني (معرّفات ودرجات فقط؛ الظهور يُفحص حيًّا)
# ENGAGEMENT_CREATOR_MIN_RATINGS=5           # أقل مجموع تقييمات لإظهار ملخّص جودة المبدع بصفحته العامة
# ENGAGEMENT_MAX_FOLLOWS=500                 # سقف من يتابعهم المستخدم الواحد
# ENGAGEMENT_MAX_COLLECTIONS=20              # سقف مجموعات المستخدم الواحد
# ENGAGEMENT_MAX_COLLECTION_ITEMS=100        # سقف عناصر المجموعة الواحدة
# ENGAGEMENT_NOTIFY_ON_REVISION=true         # إشعار المتابعين أيضًا عند اعتماد نسخة جديدة لعمل منشور سابقًا

# E30 - الرعاة والداعمون وتمويل المكافآت + سلاسل المبدعين (كلها اختيارية؛ الافتراضيات آمنة). انظر docs/sponsorship-supporters-e30.md و docs/creator-series-e30.md.
# سلاسل المبدعين بلا أي سلطة اقتصادية بأي إعداد ولا يطالها تعزيز الرعاية: لا مفتاح بيئة لذلك.
# SPONSORSHIP_ENABLED=true                   # تعطيل شامل لواجهات الرعاية والتعزيز (التاريخ المالي يبقى كما هو)
# SPONSORSHIP_ACCOUNTING_CURRENCY=USD        # عملة المحاسبة الموحَّدة للمجاميع والمستويات والتعزيز
# SPONSORSHIP_MAX_AMOUNT_MINOR=1000000000    # سقف المبلغ الواحد بالوحدة الصغرى (حماية من أخطاء الإدخال)
# SPONSORSHIP_LOGO_DISK=public               # قرص شعارات الداعمين (JPEG/PNG/WEBP فقط)
# SPONSORSHIP_LOGO_MAX_KB=1024               # أقصى حجم شعار بالكيلوبايت
# SPONSORSHIP_CACHE_TTL=120                  # عمر لقطات اللوحات العامة بالثواني (معرّفات ومجاميع فقط؛ الظهور يُفحص حيًّا)
# SPONSORSHIP_DISPLAY_TTL=60                 # عمر لقطة شريط الرعاة بالثواني
# SPONSORSHIP_REWARD_BOOST_ENABLED=true      # تعزيز مكافآت المحتوى الرسمي فقط (false = لا تعزيز إطلاقًا)
# SPONSORSHIP_HARD_MAX_BOOST_BPS=5000        # السقف الصلب للتعزيز بالنقاط الأساسية (5000 = 50%) لا يتجاوزه أي برنامج
# SPONSORSHIP_EVENT_LOCK_LEAD_MINUTES=60     # قفل خطة تعزيز المنافسة قبل بدئها بهذه المدة (دقائق)
# SPONSORSHIP_NOTIFICATIONS_ENABLED=true     # إشعارات الداعمين (تأكيد الدعم/تغيّر المستوى/بدء البرنامج)
# E32 - دفع الرعاية والعمليات المالية (كلها اختيارية؛ الافتراضيات آمنة). لا أسرار ولا بيانات بنكية هنا: تفاصيل التحويل تُدار من لوحة الإدارة (إعدادات الدفع). انظر docs/sponsorship-payments-e32.md.
# PAYMENTS_ENABLED=true                      # مفتاح إيقاف شامل: false = لا طلبات دعم جديدة (المراجعة والاسترداد للقائم يبقيان)
# PAYMENTS_DEFAULT_PROVIDER=manual           # المزوّد الافتراضي (manual = تحويل يدوي + إثبات + تحقق مالي)
# PAYMENTS_MANUAL_ENABLED=true               # تفعيل التحويل اليدوي بالنشر (يُشترط معه مفتاح الإدارة payments.manual_enabled)
# PAYMENTS_ALLOWED_CURRENCIES=USD,EUR,TRY    # عملات الدفع المسموحة (تُقاطَع مع عملات الرعاية)
# PAYMENTS_MIN_AMOUNT_MINOR=100              # أدنى مبلغ بالوحدة الصغرى (100 = 1.00)
# PAYMENTS_MAX_AMOUNT_MINOR=                 # أعلى مبلغ للطلب الواحد (فارغ = سقف الرعاية SPONSORSHIP_MAX_AMOUNT_MINOR)
# PAYMENTS_ALLOW_CUSTOM_AMOUNT=true          # false = المبالغ المقترحة فقط
# PAYMENTS_MAX_OPEN_REQUESTS=5               # سقف الطلبات المفتوحة لكل مستخدم (يمنع إغراق طابور المراجعة)
# PAYMENTS_REQUEST_EXPIRY_HOURS=48           # مهلة طلب «بانتظار الدفع» قبل الانتهاء الآلي (المرسَل/المحقَّق لا ينتهي أبدًا)
# PAYMENTS_PROOF_DISK=local                  # قرص إثبات التحويل: يجب أن يكون خاصًا (يُرفض public)
# PAYMENTS_PROOF_MAX_KB=5120                 # أقصى حجم إثبات بالكيلوبايت (JPEG/PNG/WEBP/PDF فقط)
# PAYMENTS_PROOF_RETENTION_DAYS=365          # مدة الاحتفاظ بملف الإثبات بعد الحالة النهائية (التقليم بأمر صريح؛ السجل لا يُحذف)
# PAYMENTS_DISPUTE_WINDOW_DAYS=90            # نافذة النزاع: لا يُقلَّم إثبات مرفوض/مسترد قبل انقضائها
# PAYMENTS_WEBHOOKS_ENABLED=false            # مسار Webhook المستقبلي للمزوّدين الإلكترونيين: مغلق افتراضيًا (لا مزوّد إلكتروني منفَّذ الآن)
# PAYMENTS_NOTIFICATIONS_ENABLED=true        # إشعارات صاحب الطلب (استلام/طلب توضيح/رفض/استرداد)
# CREATOR_SERIES_ENABLED=true                # تعطيل سلاسل المبدعين (404) دون مساس بأحجيات المجتمع
# CREATOR_SERIES_MIN_ITEMS=2                 # أقل عدد عناصر للسلسلة
# CREATOR_SERIES_MAX_ITEMS=25                # أقصى عدد عناصر للسلسلة
# CREATOR_SERIES_MAX_PER_CREATOR=50          # سقف سلاسل المبدع الواحد غير المؤرشفة
# CREATOR_SERIES_MAX_PENDING=5               # سقف السلاسل قيد المراجعة معًا لكل مبدع
# CREATOR_SERIES_DISCOVERY_TTL=120           # عمر كاش معرّفات «أحدث السلاسل» (الظهور يُفحص حيًّا بكل عرض)
# CREATOR_SERIES_ANALYTICS_TTL=600           # عمر لقطة تحليلات سلاسل المبدع (أرقام مجمَّعة بلا هويات)
# CREATOR_SERIES_NOTIFY_FOLLOWERS=true       # إشعار المتابعين عند نشر سلسلة جديدة (دفعات، idempotent)
# CREATOR_SERIES_NOTIFY_ON_REVISION=true     # إشعار المتابعين عند اعتماد نسخة جديدة لسلسلة منشورة سابقًا

# E31 - MySQL والنقل من SQLite (كلها اختيارية؛ الافتراضيات آمنة). انظر docs/mysql-production-e31.md.
# OPS_ALLOW_SQLITE_PRODUCTION=false          # true: يحوّل فشل production:check على SQLite إلى تحذير (غير مستحسن؛ MySQL هي المعتمدة)
# MIGRATION_TARGET_CONNECTION=mysql          # اتصال الهدف لأمر db:transfer-sqlite-to-mysql (محلي فقط، يرفض الإنتاج)
# MIGRATION_SQLITE_DATABASE=                 # مسار ملف SQLite المصدر (أو مرِّره بـ--source)
# MIGRATION_MYSQL_HOST=                      # تجاوزات الهدف إن أردت إبقاء DB_* على SQLite أثناء النقل
# MIGRATION_MYSQL_PORT=
# MIGRATION_MYSQL_DATABASE=
# MIGRATION_MYSQL_USERNAME=
# MIGRATION_MYSQL_PASSWORD=
# MIGRATION_BATCH_SIZE=1000                  # صفوف لكل دفعة (500..2000)
`````

#### خريطة تعديلات `app/Providers/AppServiceProvider.php`

  - السطر 27: `// E32: سجل مزوّدي الدفع نسخة واحدة (يسمح بحقن مزوّد بالاختبارات/المستقبل دون المساس بالمجال المالي).`
  - السطر 254: `// E32: دفع الرعاية. قراءة الصفحات العامة، إنشاء الطلب، إرسال/إلغاء التحويل، رفع الإثبات (أضيقها)، متابعة حالة الطلب، وWebhook المستقبلي بحدّ لكل عنوا`

### `app/Providers/AppServiceProvider.php`

`sha256: 97395cab43dd5e394b5b74d1f4657da658f27dbb1a0625d9b4e4aeb3e5b03c7e`

`````php
<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AuthorizationSafetyService;
use App\Services\Teams\TeamService;
use App\Support\Security\Fingerprint;
use Illuminate\Cache\RateLimiting\Limit;
use App\Support\ReleaseInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // E27: محرك البحث. السائق غير المعروف يسقط إلى database (لا يعطّل الموقع). محرك خارجي مستقبلي = تنفيذ جديد لـSearchBackend وفرع هنا.
        $this->app->singleton(\App\Services\Search\Contracts\SearchBackend::class, fn ($app) => match ((string) config('search.driver', 'database')) {
            default => $app->make(\App\Services\Search\DatabaseSearchBackend::class),
        });

        // E32: سجل مزوّدي الدفع نسخة واحدة (يسمح بحقن مزوّد بالاختبارات/المستقبل دون المساس بالمجال المالي).
        $this->app->singleton(\App\Services\Payments\PaymentProviderRegistry::class);

        // E31: أسماء الفهارس/المفاتيح المولَّدة تلقائيًا تُقتطع إلى 64 محرفًا على MySQL/MariaDB فقط (SQLite بلا تغيير). انظر MysqlSafeBlueprint.
        $this->app->bind(\Illuminate\Database\Schema\Blueprint::class, \App\Database\MysqlSafeBlueprint::class);
    }

    public function boot(): void
    {
        // E22: قائمة المحادثات الجانبية لصفحة الغرفة (قراءة فقط، بلا إنشاء غرف عند العرض).
        \Illuminate\Support\Facades\View::composer('chat.room', fn ($view) => $view->with('sidebar', app(\App\Services\Chat\ChatSidebar::class)->for(auth()->user())));

        // E19-E20: قبل حذف أي حساب يُعالَج ما يملكه من فرق (نقل الملكية لأقدم مشرف/عضو، وإلا أرشفة): لا فريق يتيم بلا مالك.
        // فشل المعالجة لا يمنع الحذف (owner_id يصير NULL بقيد القاعدة والفريق يبقى قابلًا لتدخل الإدارة).
        User::deleting(function (User $user) {
            try {
                app(TeamService::class)->releaseOwnershipFor($user);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // E29: قبل حذف أي حساب تُنقَص عدّادات ما تفاعل معه (مفضلة/متابعات) فتبقى المجمَّعات دقيقة؛ الصفوف نفسها تتتابع بقيد القاعدة، والتقييمات تبقى مجهولة الهوية.
        // فشل التنظيف لا يمنع الحذف؛ community:reconcile-engagement يصلح أي انحراف.
        User::deleting(function (User $user) {
            try {
                app(\App\Services\Engagement\EngagementCleanup::class)->purgeUser($user);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // Super Admin يتجاوز كل فحص can()/authorize() في التطبيق بالكامل -
        // Server-side حصراً. مصدر الفحص الوحيد AuthorizationSafetyService.
        Gate::before(function ($user, string $ability) {
            return app(AuthorizationSafetyService::class)->isSuperAdmin($user) ? true : null;
        });

        $this->registerRateLimiters();
        $this->registerOperationalGuards();
    }

    /**
     * E25: حواجز التشغيل بالإنتاج.
     *  - الأوامر الهدّامة (db:wipe, migrate:fresh|refresh|reset|rollback) ممنوعة بالإنتاج: الترحيلات بالإنتاج تتقدّم للأمام فقط (forward-fix) والتراجع عن القاعدة غير آمن بعد كتابات الإصدار الجديد.
     *    استثناء واعٍ ومؤقت بمتغير بيئة لتلك العملية وحدها: OPS_ALLOW_DESTRUCTIVE_COMMANDS=true (انظر docs/release-engineering.md). لا يُضاف إلى .env الدائم.
     *  - معرّف الإصدار (إن ضُبط) يُضاف لسياق كل سطر سجل (release) بجانب request_id من E24، لربط أي خطأ بالإصدار الذي أنتجه. لا أسرار فيه.
     */
    protected function registerOperationalGuards(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction() && ! config('operations.allow_destructive_commands', false));

        if (($release = ReleaseInfo::short()) !== null) {
            Log::shareContext(['release' => $release]);
        }
    }

    /**
     * E8: بنية Rate Limiting مركزية واحدة - بدل أرقام مبعثرة (throttle:20,1)
     * بكل Route مباشرة. المفتاح: user ID للمصادَق عليهم (بند 26 - نفس سلوك
     * Laravel الافتراضي أصلاً بمجموعات auth/verified)، أو IP للضيوف حصراً
     * (تسجيل/استرجاع كلمة سر - لا يوجد مستخدم مصادَق عليه بعد أصلاً).
     */
    protected function registerRateLimiters(): void
    {
        // E8: لم تكن محمية إطلاقاً سابقاً (بند 21) - تسجيل حساب جديد.
        RateLimiter::for('registration', function (Request $request) {
            return Limit::perMinutes(10, 5)->by($request->ip());
        });

        // E8: لم تكن محمية إطلاقاً سابقاً (بند 22) - منع email flooding.
        // E26: حدّ ثانٍ **لكل مستلم** (بريد مُجزَّأ، لا بريد خام بمفتاح المحدِّد) كي لا يُغرِق مهاجمٌ بعناوين IP متعددة صندوق ضحية واحدة.
        RateLimiter::for('password-reset', function (Request $request) {
            return [
                Limit::perMinutes(10, 5)->by('pr-ip:'.$request->ip()),
                Limit::perHour(3)->by('pr-rcpt:'.Fingerprint::email($request->input('email'))),
            ];
        });

        // E26: تنفيذ إعادة التعيين نفسه (POST /reset-password) لم يكن محدودًا. الرمز عشوائي طويل، لكن لا سبب لقبول طرق غير محدودة.
        RateLimiter::for('password-reset-submit', function (Request $request) {
            return Limit::perMinutes(10, 10)->by('prs-ip:'.$request->ip());
        });

        // E26: تأكيد كلمة المرور (confirm-password) كان قابلًا للتخمين بلا حدّ داخل جلسة مسروقة. المفتاح المستخدم.
        RateLimiter::for('password-confirm', function (Request $request) {
            return Limit::perMinute(6)->by('pc:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: تعديل بيانات الحساب الحسّاسة (الاسم/البريد/ظهور الملف) - أدنى بكثير من أي استخدام بشري.
        RateLimiter::for('account-sensitive', function (Request $request) {
            return Limit::perMinute(10)->by('acs:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: أفعال لعب خفيفة كانت بلا حدّ (شراء تلميح، انضمام تحدٍّ، إكمال/تأمل خطوة حملة).
        RateLimiter::for('game-actions', function (Request $request) {
            return Limit::perMinute(30)->by('ga:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('puzzle-hint', function (Request $request) {
            return Limit::perMinute(10)->by('ph:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: مصادقة قنوات البثّ الخاصة (/broadcasting/auth): اشتراك كل غرفة مرة واحدة عادةً؛ السقف يمنع استنزاف القاعدة باستعلامات التخويل.
        RateLimiter::for('broadcast-auth', function (Request $request) {
            return Limit::perMinute(90)->by('bca:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: دخول API (إصدار رمز Sanctum) - حدّ لكل IP وحدّ لكل (بريد مُجزَّأ+IP). لا يتشارك مع محدّد دخول الويب.
        RateLimiter::for('api-login', function (Request $request) {
            return [
                Limit::perMinute(20)->by('apil-ip:'.$request->ip()),
                Limit::perMinutes(5, 5)->by('apil-acct:'.Fingerprint::email($request->input('email')).'|'.$request->ip()),
            ];
        });

        RateLimiter::for('email-verification', function (Request $request) {
            return Limit::perMinute(6)->by($request->user()?->id ?: $request->ip());
        });

        // أفعال اللعب (بند 24) - نفس الأرقام الحالية بالضبط، فقط مُسمّاة
        // ومركزية الآن. لا نخفّضها - قد تكسر لعبًا طبيعيًا سريعًا.
        RateLimiter::for('puzzle-attempt', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('game-session-start', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('game-session-reveal', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // E14 (بند 606): تحويل نقر الإعلان - عام (قد يشمل زائرًا غير مسجَّل)، معدَّل معقول يمنع إساءة الاستخدام بلا إزعاج تصفُّح عادي.
        RateLimiter::for('ad-click', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // طلبات الاستبدال (بند 25) - نفس الحد الحالي: 5 كل ساعة.
        RateLimiter::for('redemption', function (Request $request) {
            return Limit::perMinutes(60, 5)->by($request->user()?->id ?: $request->ip());
        });

                // E10: مشتريات المتجر الافتراضي - مفتاح المستخدم (لا IP، دائماً
        // مُصادَق عليه بهذه المرحلة).
        RateLimiter::for('store-purchase', function (Request $request) {
            return Limit::perMinute(8)->by($request->user()?->id ?: $request->ip());
        });

        // E16: الأصدقاء. إرسال الطلبات بالدقيقة وبالساعة (مع cooldown إعادة الإرسال داخل FriendshipService)؛ بقية الإجراءات
        // والبحث أوسع. المفتاح المستخدم المصادَق (لا IP). لا يمنع الاستخدام الطبيعي.
        RateLimiter::for('friend-requests', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [Limit::perMinute(10)->by("friend-req-min:{$key}"), Limit::perHour(60)->by("friend-req-hour:{$key}")];
        });

        RateLimiter::for('friend-actions', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // E17: إنشاء تحدٍّ (حد ضيق: يولّد إشعارًا لشخص آخر)، تسجيل المنافسات، ولعب/إرسال نتيجة، وبقية إجراءات التحديات.
        RateLimiter::for('friend-challenges', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [Limit::perMinute(5)->by("fc-min:{$key}"), Limit::perHour(20)->by("fc-hour:{$key}")];
        });

        RateLimiter::for('competitive-register', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // E19: الفرق. إنشاء نادر؛ الدعوات/الطلبات تحت حد يمنع الإزعاج؛ إجراءات الإدارة والقبول معتدلة.
        RateLimiter::for('team-create', fn (Request $request) => [Limit::perMinute(2)->by('tc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(5)->by('tch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('team-invite', fn (Request $request) => [Limit::perMinute(10)->by('ti:'.($request->user()?->id ?: $request->ip())), Limit::perHour(40)->by('tih:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('team-join', fn (Request $request) => [Limit::perMinute(8)->by('tj:'.($request->user()?->id ?: $request->ip())), Limit::perHour(30)->by('tjh:'.($request->user()?->id ?: $request->ip()))]);
        // E20: تحدّيات الفرق (الإنشاء نادر نسبيًا؛ القبول/الرفض/الإلغاء/الروستر معتدلة؛ اللعب بحد competitive-play القائم).
        RateLimiter::for('team-challenge-create', fn (Request $request) => [Limit::perMinute(4)->by('tcc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('tcch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('team-challenge-actions', fn (Request $request) => Limit::perMinute(30)->by('tca:'.($request->user()?->id ?: $request->ip())));
        // E21: دردشة. الإرسال حسب النوع (العامة أشد + حماية دفعات)، والبلاغ والتعديل/الحذف والقراءة بحدود منفصلة. المفتاح: المستخدم لا IP.
        RateLimiter::for('chat-dm-send', fn (Request $request) => Limit::perMinute(30)->by('cdm:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-team-send', fn (Request $request) => Limit::perMinute(30)->by('ctm:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-global-send', fn (Request $request) => [
            Limit::perMinute(10)->by('cgm:'.($request->user()?->id ?: $request->ip())),
            Limit::perSecond(3, 10)->by('cgb:'.($request->user()?->id ?: $request->ip())),     // دفعة: 3 رسائل/10 ثوانٍ
        ]);
        RateLimiter::for('chat-report', fn (Request $request) => [Limit::perMinute(5)->by('crm:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('crh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('chat-edit', fn (Request $request) => Limit::perMinute(30)->by('ced:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-moderate', fn (Request $request) => Limit::perMinute(30)->by('cmo:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-read', fn (Request $request) => Limit::perMinute(120)->by('crd:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('team-actions', fn (Request $request) => Limit::perMinute(40)->by('ta:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('competitive-play', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('player-search', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
                // E27: البحث العام. الاقتراحات الفورية أوسع (تُطلَق أثناء الكتابة بعد تأخير 220ms) لكن الضيف أضيق بكثير من العضو (مفتاح IP) كي لا يُستعمل مسحًا لأسماء اللاعبين.
        // صفحة البحث الكاملة أضيق من الاقتراحات. الاكتشاف (القراءة فقط) بحدّ واسع. المفتاح المستخدم المصادَق لا IP (يمنع التفاف مفتاح IP المشترك).
        RateLimiter::for('global-search', fn (Request $request) => $request->user()
            ? Limit::perMinute(60)->by('gs:u:'.$request->user()->id)
            : Limit::perMinute(20)->by('gs:ip:'.$request->ip()));
        RateLimiter::for('search-suggest', fn (Request $request) => $request->user()
            ? [Limit::perMinute(120)->by('ss:u:'.$request->user()->id), Limit::perSecond(16, 2)->by('ssb:u:'.$request->user()->id)]
            : [Limit::perMinute(40)->by('ss:ip:'.$request->ip()), Limit::perSecond(10, 2)->by('ssb:ip:'.$request->ip())]);
        RateLimiter::for('discover', fn (Request $request) => Limit::perMinute(90)->by('dc:'.($request->user()?->id ?: $request->ip())));

        // E28: المبدعون. كلها بمفتاح المستخدم لا IP. الطلب/الإنشاء/الإرسال (ويشمل السحب وإنشاء نسخة جديدة كي لا يُغرَق الإشراف بدورات إرسال/إلغاء) والبلاغات بحدّين دقيقة/ساعة.
        RateLimiter::for('creator-apply', fn (Request $request) => [Limit::perMinute(2)->by('cap:'.($request->user()?->id ?: $request->ip())), Limit::perHour(5)->by('caph:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('creator-draft-create', fn (Request $request) => [Limit::perMinute(5)->by('cdc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(30)->by('cdch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('creator-submit', fn (Request $request) => [Limit::perMinute(5)->by('csb:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('csbh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('community-report', fn (Request $request) => [Limit::perMinute(5)->by('cpr:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('cprh:'.($request->user()?->id ?: $request->ip()))]);

        // E29: التفاعل المجتمعي. بمفتاح المستخدم لا IP (كلها خلف auth). حدّان: دقيقة (سرعة بشرية) + ساعة (سقف إغراق/نمط آلي).
        // التقييم/الحفظ يسمحان بتغيير الرأي والتنقل بين أعمال كثيرة، فالحدّ أوسع من المتابعة والمجموعات (أثرهما أثقل على بيانات الآخرين).
        RateLimiter::for('community-rating', fn (Request $request) => [Limit::perMinute(20)->by('crt:'.($request->user()?->id ?: $request->ip())), Limit::perHour(200)->by('crth:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('community-favorite', fn (Request $request) => [Limit::perMinute(30)->by('cfv:'.($request->user()?->id ?: $request->ip())), Limit::perHour(300)->by('cfvh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('creator-follow', fn (Request $request) => [Limit::perMinute(15)->by('cfl:'.($request->user()?->id ?: $request->ip())), Limit::perHour(100)->by('cflh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('collection-actions', fn (Request $request) => [Limit::perMinute(30)->by('cca:'.($request->user()?->id ?: $request->ip())), Limit::perHour(240)->by('ccah:'.($request->user()?->id ?: $request->ip()))]);

        // E30: قراءات الرعاة والسلاسل العامة (صفحات عرض فقط) بحدّ معتدل لكل مستخدم/عنوان؛ إجراءات استوديو السلاسل تُعيد استعمال محدّدات المبدعين (creator-draft-create / creator-submit).
        RateLimiter::for('supporters-read', fn (Request $request) => Limit::perMinute(120)->by('spr:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('series-read', fn (Request $request) => Limit::perMinute(120)->by('ser:'.($request->user()?->id ?: $request->ip())));

        // E32: دفع الرعاية. قراءة الصفحات العامة، إنشاء الطلب، إرسال/إلغاء التحويل، رفع الإثبات (أضيقها)، متابعة حالة الطلب، وWebhook المستقبلي بحدّ لكل عنوان.
        RateLimiter::for('support-read', fn (Request $request) => Limit::perMinute(120)->by('sur:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('support-checkout', fn (Request $request) => [Limit::perMinute(10)->by('suc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(40)->by('such:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('support-submit', fn (Request $request) => [Limit::perMinute(10)->by('sus:'.($request->user()?->id ?: $request->ip())), Limit::perHour(60)->by('sush:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('support-proof-upload', fn (Request $request) => [Limit::perMinute(5)->by('sup:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('suph:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('payment-status', fn (Request $request) => Limit::perMinute(60)->by('pst:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('payment-webhook', fn (Request $request) => Limit::perMinute(120)->by('pwh:'.$request->ip()));

        // E11: تجهيز/إزالة تجميليات - UX تتطلب تجربة سريعة (تبديل/معاينة)، لا حساسية مالية هنا.
        RateLimiter::for('cosmetic-equip', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
        
        // E12: فجوة سابقة على هذه المرحلة - مجموعة Middleware الافتراضية 'api'
        // (المُطبَّقة تلقائيًا على كل مسار بـroutes/api.php) تتضمَّن throttle:api،
        // ولم يكن هذا الاسم مُعرَّفًا إطلاقًا (لم يُختَبر أي مسار API قبل الآن).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

    }

}
`````

#### خريطة تعديلات `app/Services/Database/FinancialHistoryGuard.php`

  - (لا وسم E32 نصيّ؛ انظر الملف)

### `app/Services/Database/FinancialHistoryGuard.php`

`sha256: e658325d50d5fd34b81800813efdee0eb99cd52ca1e566cea4d494d6218a7eb9`

`````php
<?php

namespace App\Services\Database;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E31 (U2/U3): حارس "التاريخ المالي لا يُمحى عرَضًا".
 *
 * المشكلة (متطابقة على SQLite وMySQL): مفاتيح user_id على جداول السجلات المالية/المكافآت مُعرَّفة ON DELETE CASCADE، فحذف مستخدم من لوحة الإدارة
 * (مدير أعلى فقط) كان يمسح دفتر عملاته ومكافآته وطلبات استبداله بصمت. الترحيلات التاريخية لا تُعدَّل، فالحماية هنا على مستوى التطبيق:
 * لا يُعرض/لا يُسمح بحذف مستخدم له أي سجلّ مالي أو رصيد؛ البديل المقصود هو التجميد (users.freeze) الذي يحفظ التاريخ للتدقيق.
 *
 * يقرأ فقط (exists) بلا أي كتابة. الجداول ثابتة هنا عمدًا (قائمة بيضاء) ويثبّتها اختبار E31 مقابل DDL الفعلي، فإضافة جدول مالي جديد بـCASCADE على users تُظهر فشل الاختبار.
 */
final class FinancialHistoryGuard
{
    /**
     * جدول => [عمود المستخدم، وصف بالعربية]. كلها تُحذف بالتتالي عند حذف المستخدم (أو تمنعه بـRESTRICT كـstore_purchases).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const HISTORY_TABLES = [
        'currency_transactions' => ['user_id', 'دفتر العملات'],
        'redemption_requests' => ['user_id', 'طلبات الاستبدال'],
        'store_purchases' => ['user_id', 'مشتريات المتجر'],
        'inventory_transactions' => ['user_id', 'حركات المخزون'],
        'xp_transactions' => ['user_id', 'حركات الخبرة'],
        'competitive_reward_grants' => ['user_id', 'جوائز الأحداث التنافسية'],
        'sponsorship_reward_bonuses' => ['user_id', 'مكافآت الرعاية'],
        'sponsorship_payments' => ['user_id', 'طلبات دعم ودفع الرعاية'],
    ];

    /** @return list<string> أوصاف الأقسام التي فيها سجلّ لهذا المستخدم (فارغة = لا تاريخ مالي). */
    public function historyFor(User $user): array
    {
        $found = [];

        foreach (self::HISTORY_TABLES as $table => [$column, $label]) {
            if (DB::table($table)->where($column, $user->getKey())->exists()) {
                $found[] = $label;
            }
        }

        $hasBalance = DB::table('wallets')->where('user_id', $user->getKey())
            ->where(fn ($q) => $q->where('available_balance', '>', 0)->orWhere('pending_balance', '>', 0))->exists();

        if ($hasBalance) {
            $found[] = 'رصيد المحفظة';
        }

        return $found;
    }

    public function hasHistory(User $user): bool
    {
        return $this->historyFor($user) !== [];
    }
}
`````

#### خريطة تعديلات `app/Services/Notifications/NotificationType.php`

  - السطر 28: `*  - E32 دفع الرعاية: SponsorshipPaymentService بعد الـcommit → support_request_received / support_action_required / support_rejected / support_refund`

### `app/Services/Notifications/NotificationType.php`

`sha256: c42b35e820b998c21e1a1dd56b5dfbe176305c964be08685ee3db292daef9162`

`````php
<?php

namespace App\Services\Notifications;

/**
 * سجل مغلق لأنواع الإشعارات المنفَّذة فعليًا (4). كل نوع يحدد فئته وأولويته وأيقونته ووجهته الداخلية
 * الوحيدة المسموحة ونصوصه - لا يأتي أي منها من الحدث أو من الإدارة أو من قاعدة البيانات.
 *
 * مصدر كل نوع (Event source → type):
 *  - AchievementUnlocked (حدث مجال جديد، يُطلَق بعد commit فتح الإنجاز) → achievement_unlocked
 *  - جدولة ساعية (ReEngagementService) على player_streaks → streak_at_risk
 *  - جدولة ساعية على player_streaks + مهام يومية قائمة → daily_quests_available
 *  - Illuminate\Auth\Events\PasswordReset (حدث موجود أصلًا) → security_password_reset
 *  - SeasonStarted (حدث مجال، SeasonLifecycleService: الموسم صار مباشرًا لأول مرة) → season_started
 *  - CampaignBecameAvailable (حدث مجال، CampaignLifecycleService: الحملة أُتيحت لأول مرة) → campaign_available
 *  - StageUnlockedForUser (حدث مجال لكل مستخدم، StageUnlockService بعد إكمال خطوة حقيقية) → stage_unlocked
 *  - CampaignCompletedForUser (حدث مجال لكل مستخدم، CampaignCompletionService بعد إكمال خطوة حقيقية) → campaign_completed،
 *    أو season_completed إن كانت الحملة مرتبطة بموسم منشور (القرار داخل المستمع: إشعار واحد فقط للإكمال نفسه)
 *  - LifecycleReminderService (مجدول ساعيًا، قراءة فقط) → season_ending_soon / campaign_ending_soon
 *  - FriendChallenge{Created,Accepted,Completed} (أحداث مجال بعد commit، FriendChallengeService) → friend_challenge_received/accepted/result_ready
 *  - CompetitiveRewardGranted (بعد منح فعلي ناجح بخدمة التوزيع) → competitive_reward_granted
 *  - TeamChallengeCreated/Accepted/Completed, TeamChampionshipStarted (الأمر الدوري)/Finalized (E20) → team_challenge_*, team_championship_* (التحدّيات: social؛ البطولات: competitive)
 *  - TeamInvitationCreated/Accepted, TeamJoinRequestCreated/Accepted, TeamMemberRemoved (أحداث مجال بعد commit بخدمات الفرق، E19) → team_* (فئة social؛ بلا إشعار للرفض)
 *  - CompetitiveLifecycleService (مجدول ساعيًا) → competitive_event_started/ending_soon؛ CompetitiveEventFinalized → competitive_event_result_ready
 *  - FriendRequestCreated / FriendshipAccepted (أحداث مجال بعد commit، FriendshipService) → friend_request_received / friend_request_accepted
 *  - اعتماد نشر (أول نشر أو نسخة جديدة) لمبدع له متابعون (E29، CreatorPublicationAnnouncer بعد الـcommit ← دفعات بالطابور) → creator_new_publication (فئة social؛ بلا إشعار على التقييم/المفضلة/اللعب)
 *  - E30 رعاية: ContributionService بعد الـcommit → support_confirmed / supporter_tier_changed (للداعم المرتبط بحساب فقط، بلا مبالغ ولا مراجع خاصة)؛ SponsorshipProgramService عند التفعيل → sponsorship_program_started (لداعمي البرنامج فقط)
 *  - E32 دفع الرعاية: SponsorshipPaymentService بعد الـcommit → support_request_received / support_action_required / support_rejected / support_refunded (لصاحب الطلب فقط؛ بلا مبالغ ولا مراجع تحويل ولا بيانات بنكية بالنص). تأكيد الدعم يستعمل support_confirmed القائم (لا إشعار تحقق مكرر)
 *  - E30 سلاسل: CreatorSeriesReviewService بعد الـcommit → series_approved / series_changes_requested / series_rejected / series_hidden (للمبدع)؛ اعتماد سلسلة لمبدع له متابعون (CreatorSeriesAnnouncer ← دفعات بالطابور) → creator_new_series (social)
 */
enum NotificationType: string
{
    case AchievementUnlocked = 'achievement_unlocked';
    case StreakAtRisk = 'streak_at_risk';
    case DailyQuestsAvailable = 'daily_quests_available';
    case SecurityPasswordReset = 'security_password_reset';
    case SeasonStarted = 'season_started';
    case CampaignAvailable = 'campaign_available';
    case StageUnlocked = 'stage_unlocked';
    case CampaignCompleted = 'campaign_completed';
    case SeasonCompleted = 'season_completed';
    case SeasonEndingSoon = 'season_ending_soon';
    case CampaignEndingSoon = 'campaign_ending_soon';
    case FriendRequestReceived = 'friend_request_received';
    case FriendRequestAccepted = 'friend_request_accepted';
    case FriendChallengeReceived = 'friend_challenge_received';
    case FriendChallengeAccepted = 'friend_challenge_accepted';
    case FriendChallengeResultReady = 'friend_challenge_result_ready';
    case CompetitiveEventStarted = 'competitive_event_started';
    case CompetitiveEventEndingSoon = 'competitive_event_ending_soon';
    case CompetitiveEventResultReady = 'competitive_event_result_ready';
    case CompetitiveRewardGranted = 'competitive_reward_granted';
    case TeamInvitationReceived = 'team_invitation_received';
    case TeamInvitationAccepted = 'team_invitation_accepted';
    case TeamJoinRequestReceived = 'team_join_request_received';
    case TeamJoinRequestAccepted = 'team_join_request_accepted';
    case TeamMemberRemoved = 'team_member_removed';
    case TeamChallengeReceived = 'team_challenge_received';
    case TeamChallengeAccepted = 'team_challenge_accepted';
    case TeamChallengeResultReady = 'team_challenge_result_ready';
    case TeamChampionshipStarted = 'team_championship_started';
    case TeamChampionshipResultReady = 'team_championship_result_ready';
    case CreatorApproved = 'creator_approved';
    case CreatorRejected = 'creator_rejected';
    case SubmissionApproved = 'submission_approved';
    case SubmissionChangesRequested = 'submission_changes_requested';
    case SubmissionRejected = 'submission_rejected';
    case PublicationHidden = 'publication_hidden';
    case CreatorNewPublication = 'creator_new_publication';
    case SupportConfirmed = 'support_confirmed';
    case SupporterTierChanged = 'supporter_tier_changed';
    case SponsorshipProgramStarted = 'sponsorship_program_started';
    case SeriesApproved = 'series_approved';
    case SeriesChangesRequested = 'series_changes_requested';
    case SeriesRejected = 'series_rejected';
    case SeriesHidden = 'series_hidden';
    case CreatorNewSeries = 'creator_new_series';
    case SupportRequestReceived = 'support_request_received';
    case SupportActionRequired = 'support_action_required';
    case SupportRejected = 'support_rejected';
    case SupportRefunded = 'support_refunded';

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::AchievementUnlocked => NotificationCategory::Achievement,
            self::StreakAtRisk => NotificationCategory::Streak,
            self::DailyQuestsAvailable => NotificationCategory::Quest,
            self::SecurityPasswordReset => NotificationCategory::Security,
            self::SeasonStarted => NotificationCategory::Season,
            self::CampaignAvailable => NotificationCategory::Campaign,
            self::StageUnlocked => NotificationCategory::Campaign,
            self::CampaignCompleted => NotificationCategory::Campaign,
            self::SeasonCompleted, self::SeasonEndingSoon => NotificationCategory::Season,
            self::CampaignEndingSoon => NotificationCategory::Campaign,
            self::FriendRequestReceived, self::FriendRequestAccepted, self::FriendChallengeReceived, self::FriendChallengeAccepted, self::FriendChallengeResultReady,
            self::TeamInvitationReceived, self::TeamInvitationAccepted, self::TeamJoinRequestReceived, self::TeamJoinRequestAccepted, self::TeamMemberRemoved,
            self::TeamChallengeReceived, self::TeamChallengeAccepted, self::TeamChallengeResultReady,
            self::CreatorApproved, self::CreatorRejected, self::SubmissionApproved, self::SubmissionChangesRequested, self::SubmissionRejected, self::PublicationHidden, self::CreatorNewPublication,
            self::SupportConfirmed, self::SupporterTierChanged, self::SponsorshipProgramStarted,
            self::SupportRequestReceived, self::SupportActionRequired, self::SupportRejected, self::SupportRefunded,
            self::SeriesApproved, self::SeriesChangesRequested, self::SeriesRejected, self::SeriesHidden, self::CreatorNewSeries => NotificationCategory::Social,
            self::CompetitiveEventStarted, self::CompetitiveEventEndingSoon, self::CompetitiveEventResultReady, self::CompetitiveRewardGranted,
            self::TeamChampionshipStarted, self::TeamChampionshipResultReady => NotificationCategory::Competitive,
        };
    }

    /** تذكير "عودة" (يخضع لميزانية اليوم) - مقابل المعاملاتي (إنجاز) والأمان (لا ميزانية). */
    public function isReEngagement(): bool
    {
        return in_array($this, [self::StreakAtRisk, self::DailyQuestsAvailable, self::SeasonEndingSoon, self::CampaignEndingSoon, self::CompetitiveEventEndingSoon], true);
    }

    /** أعلى = أهم. اختيار الأولوية يتم بتقسيم الجمهور + الميزانية (راجع ReEngagementService). */
    public function priority(): int
    {
        return match ($this) {
            self::SecurityPasswordReset => 1000,
            self::StreakAtRisk => 100,
            self::DailyQuestsAvailable => 50,
            self::SeasonStarted => 20,
            self::CampaignAvailable => 15,
            self::StageUnlocked => 14,
            self::CampaignCompleted => 16,
            self::SeasonCompleted => 17,
            self::SeasonEndingSoon, self::CampaignEndingSoon => 70, // بين تحذير السلسلة (100) وتذكير المهام (50)
            self::FriendRequestReceived => 25,
            self::FriendRequestAccepted => 24,
            self::FriendChallengeReceived => 26,
            self::FriendChallengeAccepted => 23,
            self::FriendChallengeResultReady => 22,
            self::CompetitiveEventStarted => 21,
            self::CompetitiveEventEndingSoon => 70, // مع تذكيري النهاية الآخرين: بين تحذير السلسلة وتذكير المهام
            self::CompetitiveEventResultReady => 20,
            self::CompetitiveRewardGranted => 19,
            self::TeamInvitationReceived => 28,
            self::TeamJoinRequestReceived => 29,
            self::TeamInvitationAccepted => 13,
            self::TeamJoinRequestAccepted => 12,
            self::TeamMemberRemoved => 11,
            self::TeamChallengeReceived => 30,
            self::TeamChallengeAccepted => 18,
            self::TeamChallengeResultReady => 9,
            self::TeamChampionshipStarted => 8,
            self::TeamChampionshipResultReady => 7,
            self::AchievementUnlocked => 10,
            self::CreatorApproved => 6,
            self::CreatorRejected => 5,
            self::SubmissionApproved => 4,
            self::SubmissionChangesRequested => 3,
            self::SubmissionRejected => 2,
            self::PublicationHidden => 1,
            self::CreatorNewPublication => 27,
            self::SupportConfirmed => 31,
            self::SupporterTierChanged => 32,
            self::SponsorshipProgramStarted => 33,
            self::SeriesApproved => 34,
            self::SeriesChangesRequested => 35,
            self::SeriesRejected => 36,
            self::SeriesHidden => 37,
            self::CreatorNewSeries => 38,
            self::SupportRequestReceived => 39,
            self::SupportActionRequired => 40,
            self::SupportRejected => 41,
            self::SupportRefunded => 42,
        };
    }

    /** أيقونة من سجل مغلق (رموز نصية) - لا أيقونة تأتي من قاعدة البيانات. */
    public function icon(): string
    {
        return match ($this) {
            self::AchievementUnlocked => '🏆',
            self::StreakAtRisk => '🔥',
            self::DailyQuestsAvailable => '🎯',
            self::SecurityPasswordReset => '🛡️',
            self::SeasonStarted => '🏁',
            self::CampaignAvailable => '🧭',
            self::StageUnlocked => '🔓',
            self::CampaignCompleted => '✅',
            self::SeasonCompleted => '🏅',
            self::SeasonEndingSoon, self::CampaignEndingSoon => '⏳',
            self::FriendRequestReceived, self::FriendRequestAccepted => '🤝',
            self::FriendChallengeReceived, self::FriendChallengeAccepted, self::FriendChallengeResultReady => '⚔️',
            self::CompetitiveEventStarted, self::CompetitiveEventResultReady => '🏆',
            self::CompetitiveRewardGranted => '🎁',
            self::TeamInvitationReceived, self::TeamInvitationAccepted, self::TeamJoinRequestReceived, self::TeamJoinRequestAccepted, self::TeamMemberRemoved => '👥',
            self::TeamChallengeReceived, self::TeamChallengeAccepted, self::TeamChallengeResultReady => '⚔️',
            self::TeamChampionshipStarted, self::TeamChampionshipResultReady => '🏆',
            self::CompetitiveEventEndingSoon => '⏳',
            self::CreatorApproved => '🎨',
            self::CreatorRejected => '📝',
            self::SubmissionApproved => '✅',
            self::SubmissionChangesRequested => '✍️',
            self::SubmissionRejected => '📝',
            self::PublicationHidden => '🚫',
            self::CreatorNewPublication => '🆕',
            self::SupportConfirmed => '💎',
            self::SupporterTierChanged => '🏅',
            self::SponsorshipProgramStarted => '📣',
            self::SeriesApproved => '📚',
            self::SeriesChangesRequested => '✍️',
            self::SeriesRejected => '📝',
            self::SeriesHidden => '🚫',
            self::CreatorNewSeries => '📚',
            self::SupportRequestReceived => '🧾',
            self::SupportActionRequired => '📎',
            self::SupportRejected => '⚠️',
            self::SupportRefunded => '↩️',
        };
    }

    /** أسماء المسارات الداخلية الوحيدة المسموحة لهذا النوع (الأولى = الافتراضية). لا URL خارجي أبدًا. */
    public function allowedRoutes(): array
    {
        return match ($this) {
            self::AchievementUnlocked => ['progress.show'],
            self::StreakAtRisk => ['puzzles.index'],
            self::DailyQuestsAvailable => ['quests.show'],
            self::SecurityPasswordReset => ['profile.edit'],
            self::SeasonStarted => ['seasons.show'],
            self::CampaignAvailable => ['campaigns.show'],
            self::StageUnlocked => ['campaigns.show', 'seasons.show'],
            self::CampaignCompleted => ['campaigns.show', 'seasons.show'],
            self::SeasonCompleted, self::SeasonEndingSoon => ['seasons.show'],
            self::CampaignEndingSoon => ['campaigns.show'],
            self::FriendRequestReceived, self::FriendRequestAccepted => ['friends.index'],
            self::FriendChallengeReceived, self::FriendChallengeAccepted, self::FriendChallengeResultReady => ['friends.challenges.show'],
            self::CompetitiveEventStarted, self::CompetitiveEventEndingSoon, self::CompetitiveEventResultReady, self::CompetitiveRewardGranted => ['competitions.show'],
            self::TeamInvitationReceived => ['teams.invitations'],
            self::TeamInvitationAccepted, self::TeamJoinRequestReceived => ['teams.manage', 'teams.show'],
            self::TeamJoinRequestAccepted => ['teams.show'],
            self::TeamMemberRemoved => ['teams.index'],
            self::TeamChallengeReceived, self::TeamChallengeAccepted, self::TeamChallengeResultReady => ['teams.challenges.show'],
            self::TeamChampionshipStarted, self::TeamChampionshipResultReady => ['team-championships.show'],
            self::CreatorApproved => ['creator.dashboard'],
            self::CreatorRejected => ['creator.apply'],
            self::SubmissionApproved, self::SubmissionChangesRequested, self::SubmissionRejected, self::PublicationHidden => ['creator.puzzles.index'],
            self::CreatorNewPublication => ['community.puzzles.show'],
            self::SupportConfirmed, self::SupporterTierChanged => ['supporters.index', 'supporters.show'],
            self::SponsorshipProgramStarted => ['supporters.index'],
            self::SeriesApproved, self::SeriesChangesRequested, self::SeriesRejected, self::SeriesHidden => ['creator.series.index'],
            self::CreatorNewSeries => ['series.show'],
            self::SupportRequestReceived, self::SupportActionRequired, self::SupportRejected, self::SupportRefunded => ['support.payments.show', 'support.history'],
        };
    }

    public function defaultRoute(): string
    {
        return $this->allowedRoutes()[0];
    }

    public function title(array $params = []): string
    {
        return match ($this) {
            self::AchievementUnlocked => 'إنجاز جديد: '.($params['name'] ?? ''),
            self::StreakAtRisk => 'حافظ على سلسلتك اليومية',
            self::DailyQuestsAvailable => 'مهام اليوم جاهزة',
            self::SecurityPasswordReset => 'أُعيد تعيين كلمة مرور حسابك',
            self::SeasonStarted => 'بدأ موسم: '.($params['name'] ?? ''),
            self::CampaignAvailable => 'حملة جديدة متاحة: '.($params['name'] ?? ''),
            self::StageUnlocked => 'تم فتح مرحلة جديدة لك: '.($params['stage'] ?? ''),
            self::CampaignCompleted => 'أكملت الحملة بنجاح: '.($params['campaign'] ?? ''),
            self::SeasonCompleted => 'أكملت الموسم بنجاح: '.($params['season'] ?? ''),
            self::SeasonEndingSoon => 'ينتهي الموسم قريبًا: '.($params['name'] ?? ''),
            self::CampaignEndingSoon => 'تنتهي الحملة قريبًا: '.($params['name'] ?? ''),
            self::FriendRequestReceived => 'أرسل لك '.($params['name'] ?? '').' طلب صداقة',
            self::FriendRequestAccepted => 'قبل '.($params['name'] ?? '').' طلب صداقتك',
            self::FriendChallengeReceived => 'تحدّاك '.($params['name'] ?? '').' في أحجية',
            self::FriendChallengeAccepted => 'قبل '.($params['name'] ?? '').' تحدّيك',
            self::FriendChallengeResultReady => 'نتيجة التحدي جاهزة',
            self::CompetitiveEventStarted => 'بدأت المنافسة: '.($params['title'] ?? ''),
            self::CompetitiveEventEndingSoon => 'تنتهي المنافسة قريبًا: '.($params['title'] ?? ''),
            self::CompetitiveEventResultReady => 'نتائج المنافسة جاهزة: '.($params['title'] ?? ''),
            self::CompetitiveRewardGranted => 'حصلت على جائزة '.($params['placement'] ?? '').' في '.($params['title'] ?? ''),
            self::TeamInvitationReceived => 'دعاك '.($params['name'] ?? '').' للانضمام إلى فريق «'.($params['team'] ?? '').'»',
            self::TeamInvitationAccepted => 'انضم '.($params['name'] ?? '').' إلى فريق «'.($params['team'] ?? '').'»',
            self::TeamJoinRequestReceived => 'طلب '.($params['name'] ?? '').' الانضمام إلى فريق «'.($params['team'] ?? '').'»',
            self::TeamJoinRequestAccepted => 'قُبل طلب انضمامك إلى فريق «'.($params['team'] ?? '').'»',
            self::TeamMemberRemoved => 'لم تعد عضوًا في فريق «'.($params['team'] ?? '').'»',
            self::TeamChallengeReceived => 'تحدّاكم فريق «'.($params['team'] ?? '').'» بمباراة',
            self::TeamChallengeAccepted => 'قَبِل فريق «'.($params['team'] ?? '').'» تحدّيكم',
            self::TeamChallengeResultReady => 'نتيجة مباراتكم ضد «'.($params['team'] ?? '').'» جاهزة',
            self::TeamChampionshipStarted => 'بدأت بطولة الفرق: '.($params['title'] ?? ''),
            self::TeamChampionshipResultReady => 'اعتُمدت نتائج بطولة الفرق: '.($params['title'] ?? ''),
            self::CreatorApproved => 'تمت الموافقة على انضمامك كمبدع',
            self::CreatorRejected => 'لم يُقبل طلب انضمامك كمبدع',
            self::SubmissionApproved => 'اعتُمدت أحجيتك: '.($params['title'] ?? ''),
            self::SubmissionChangesRequested => 'طُلبت تعديلات على أحجيتك: '.($params['title'] ?? ''),
            self::SubmissionRejected => 'لم تُعتمد أحجيتك: '.($params['title'] ?? ''),
            self::PublicationHidden => 'أُخفيت أحجيتك من المنصة: '.($params['title'] ?? ''),
            self::CreatorNewPublication => ! empty($params['updated'])
                ? 'حدّث '.($params['creator'] ?? 'مبدع تتابعه').' أحجيته: '.($params['title'] ?? '')
                : 'نشر '.($params['creator'] ?? 'مبدع تتابعه').' أحجية جديدة: '.($params['title'] ?? ''),
            self::SupportConfirmed => 'تم تأكيد دعمك للمنصة، شكرًا لك',
            self::SupportRequestReceived => 'تم إنشاء طلب دعمك: أتمم التحويل وأرسل الإثبات',
            self::SupportActionRequired => 'طلب دعمك يحتاج إلى توضيح منك',
            self::SupportRejected => 'لم يُقبل طلب دعمك',
            self::SupportRefunded => 'سُجّل استرداد دعمك',
            self::SupporterTierChanged => ! empty($params['up'])
                ? 'ارتقى مستوى دعمك إلى: '.($params['tier'] ?? '')
                : 'تغيّر مستوى دعمك'.(! empty($params['tier']) ? ' إلى: '.$params['tier'] : ''),
            self::SponsorshipProgramStarted => 'بدأ برنامج رعاية: '.($params['title'] ?? ''),
            self::SeriesApproved => 'اعتُمدت سلسلتك: '.($params['title'] ?? ''),
            self::SeriesChangesRequested => 'طُلبت تعديلات على سلسلتك: '.($params['title'] ?? ''),
            self::SeriesRejected => 'لم تُعتمد سلسلتك: '.($params['title'] ?? ''),
            self::SeriesHidden => 'أُخفيت سلسلتك من المنصة: '.($params['title'] ?? ''),
            self::CreatorNewSeries => ! empty($params['updated'])
                ? 'حدّث '.($params['creator'] ?? 'مبدع تتابعه').' سلسلته: '.($params['title'] ?? '')
                : 'نشر '.($params['creator'] ?? 'مبدع تتابعه').' سلسلة جديدة: '.($params['title'] ?? ''),
        };
    }

    public function body(array $params = []): string
    {
        return match ($this) {
            self::AchievementUnlocked => 'فتحتَ إنجازًا جديدًا. تصفّح تقدّمك لترى بقية الإنجازات.',
            self::StreakAtRisk => 'سلسلتك الحالية '.(int) ($params['streak'] ?? 0).' يومًا. حلّ أحجية واحدة قبل نهاية اليوم لتستمر.',
            self::DailyQuestsAvailable => 'مهام اليوم متاحة الآن. حلّ أحجية وتقدّم نحو أهدافك اليومية.',
            self::SecurityPasswordReset => 'إن لم تكن أنت من أعاد تعيينها، تواصل مع الدعم فورًا وراجع أمان حسابك.',
            self::SeasonStarted => 'الموسم متاح الآن ويمكنك الانضمام واللعب.',
            self::CampaignAvailable => 'الحملة متاحة الآن ويمكنك البدء بها.',
            self::StageUnlocked => 'مرحلة «'.($params['stage'] ?? '').'» من حملة «'.($params['campaign'] ?? '').'» أصبحت متاحة لك الآن.',
            self::CampaignCompleted => 'أنهيت جميع مراحل حملة «'.($params['campaign'] ?? '').'».',
            self::SeasonCompleted => 'أنهيت جميع مراحل موسم «'.($params['season'] ?? '').'».',
            self::SeasonEndingSoon => 'ينتهي موسم «'.($params['name'] ?? '').'» خلال '.($params['hours'] ?? '').' ساعة أو أقل، وما زال بإمكانك إكماله.',
            self::CampaignEndingSoon => 'تنتهي حملة «'.($params['name'] ?? '').'» خلال '.($params['hours'] ?? '').' ساعة أو أقل، وما زال بإمكانك إكمالها.',
            self::FriendRequestReceived => 'يمكنك قبول الطلب أو رفضه من صفحة الأصدقاء.',
            self::FriendRequestAccepted => 'أصبحتما صديقين.',
            self::FriendChallengeReceived => 'أحجية «'.($params['puzzle'] ?? '').'». يمكنك قبول التحدي أو رفضه من صفحة التحديات.',
            self::FriendChallengeAccepted => 'تحدّي أحجية «'.($params['puzzle'] ?? '').'» صار نشطًا، ويمكنك اللعب الآن.',
            self::FriendChallengeResultReady => 'تحدّي «'.($params['puzzle'] ?? '').'» ضد '.($params['name'] ?? '').': '.($params['outcome'] ?? '').'.',
            self::CompetitiveEventStarted => 'المنافسة التي سجّلت بها بدأت الآن، ويمكنك اللعب حتى انتهائها.',
            self::CompetitiveEventEndingSoon => 'لم تُرسل نتيجتك بعد. تنتهي المنافسة خلال '.($params['hours'] ?? '').' ساعة أو أقل.',
            self::CompetitiveEventResultReady => 'ترتيبك النهائي: '.($params['rank'] ?? '').'.',
            self::CompetitiveRewardGranted => 'مُنحت لك: '.($params['reward'] ?? '').'.',
            self::TeamInvitationReceived => 'يمكنك قبول الدعوة أو تجاهلها من صفحة دعواتك.',
            self::TeamInvitationAccepted => 'قَبِل دعوتك وأصبح عضوًا بالفريق.',
            self::TeamJoinRequestReceived => 'راجع الطلب من صفحة إدارة الفريق.',
            self::TeamJoinRequestAccepted => 'أهلًا بك في الفريق.',
            self::TeamMemberRemoved => 'يمكنك الانضمام إلى فريق آخر أو إنشاء فريقك من صفحة الفرق.',
            self::TeamChallengeReceived => 'اختر روستر فريقكم واقبل التحدّي قبل انتهاء المهلة.',
            self::TeamChallengeAccepted => 'قُفل الروستران، ويمكن للاعبي الروستر اللعب الآن.',
            self::TeamChallengeResultReady => ($params['outcome'] ?? 'اعتُمدت النتيجة').'.',
            self::TeamChampionshipStarted => 'تُحتسب نتائج أحداثها المعتمَدة بنقاط الترتيب.',
            self::TeamChampionshipResultReady => 'اطّلع على الترتيب النهائي وبطل البطولة.',
            self::CreatorApproved => 'يمكنك الآن إنشاء أحجيات المجتمع وإرسالها للمراجعة من استوديو المبدعين.',
            self::CreatorRejected => static::withNote('يمكنك مراجعة الملاحظة وإعادة التقديم لاحقًا.', $params),
            self::SubmissionApproved => 'أصبحت أحجيتك منشورة ضمن «من المجتمع» ويمكن للاعبين لعبها.',
            self::SubmissionChangesRequested => static::withNote('عدّل الأحجية وأعد إرسالها للمراجعة من استوديو المبدعين.', $params),
            self::SubmissionRejected => static::withNote('يمكنك إنشاء نسخة جديدة من استوديو المبدعين.', $params),
            self::PublicationHidden => 'أخفاها فريق الإشراف مؤقتًا. راجع حالتها في استوديو المبدعين.',
            self::CreatorNewPublication => ! empty($params['updated'])
                ? 'اعتُمدت نسخة جديدة من الأحجية ضمن «من المجتمع». أحجيات المجتمع بلا جوائز اقتصادية.'
                : 'أحجية جديدة ضمن «من المجتمع» من مبدع تتابعه. أحجيات المجتمع بلا جوائز اقتصادية.',
            self::SupportConfirmed => 'سُجّل دعمك وأُكّد من فريق المنصة. الدعم لا يمنحك أي أفضلية في اللعب أو المكافآت.',
            self::SupportRequestReceived => 'لن يُحتسب الدعم قبل تحقق الفريق من التحويل. اكتب رمز المرجع في التحويل وأرسل الإثبات من صفحة الطلب.',
            self::SupportActionRequired => static::withNote('راجع صفحة الطلب وأكمل البيانات المطلوبة ثم أعد الإرسال.', $params),
            self::SupportRejected => static::withNote('لم يُحتسب هذا الطلب كدعم. يمكنك إنشاء طلب جديد متى شئت.', $params),
            self::SupportRefunded => 'سُجّل استرداد دعمك ويُحتسب مستواك من صافي دعمك المؤكَّد. لا تُسحب مكافآت اللاعبين السابقة.',
            self::SupporterTierChanged => 'يظهر مستواك ضمن «الداعمون». المستوى يُحسب من صافي دعمك المؤكَّد.',
            self::SponsorshipProgramStarted => 'بدأ برنامج رعاية تشارك فيه كداعم. اطّلع على صفحة الداعمين.',
            self::SeriesApproved => 'أصبحت سلسلتك منشورة ضمن «من المجتمع» ويمكن للاعبين تتبّعها.',
            self::SeriesChangesRequested => static::withNote('عدّل السلسلة وأعد إرسالها للمراجعة من استوديو المبدعين.', $params),
            self::SeriesRejected => static::withNote('يمكنك إنشاء سلسلة جديدة من استوديو المبدعين.', $params),
            self::SeriesHidden => 'أخفاها فريق الإشراف مؤقتًا. راجع حالتها في استوديو المبدعين.',
            self::CreatorNewSeries => ! empty($params['updated'])
                ? 'اعتُمدت نسخة جديدة من السلسلة ضمن «من المجتمع». سلاسل المجتمع بلا جوائز اقتصادية.'
                : 'سلسلة أحجيات جديدة ضمن «من المجتمع» من مبدع تتابعه. سلاسل المجتمع بلا جوائز اقتصادية.',
        };
    }

    /** ملاحظة المراجع (نص عادي يُهرَّب عند العرض) تُلحق بنص الإشعار إن وُجدت؛ الغياب لا يكسر النص. */
    protected static function withNote(string $base, array $params): string
    {
        $note = trim((string) ($params['note'] ?? ''));

        return $note === '' ? $base : 'ملاحظة المراجع: '.$note.' — '.$base;
    }

    /** @return list<string> */
    public static function reEngagementKeys(?self $atLeast = null): array
    {
        return array_values(array_map(
            fn (self $t) => $t->value,
            array_filter(self::cases(), fn (self $t) => $t->isReEngagement() && ($atLeast === null || $t->priority() >= $atLeast->priority())),
        ));
    }
}
`````

#### خريطة تعديلات `app/Services/Sponsorship/ContributionService.php`

  - السطر 169: `* E32: قلب التأكيد القابل لإعادة الاستعمال **داخل معاملة يملكها المنادي** (الصف مقفول مسبقًا بـlockForUpdate). نفس قواعد E30 حرفيًا (موقوف/برنامج مغلق`
  - السطر 275: `// E32: مساهمة ناتجة عن طلب دفع ⇒ يُسجَّل الطلب مستردًّا في المعاملة نفسها (لا تعارض حالات بين الطلب والمساهمة).`
  - السطر 289: `* E32: قلب الاسترداد **داخل معاملة المنادي** (الصف مقفول مسبقًا). نفس قواعد E30 (المؤكَّد فقط، إسقاط دور الراعي عند انعدام المؤكَّد، تدقيق) بلا صلاحية`
  - السطر 336: `/** إشعارات ما بعد الـcommit: تأكيد الدعم (للتأكيد فقط) وتغيّر المستوى. فشلها لا يمسّ المعاملة. (عامة منذ E32: يستدعيها مسار التحقق من الدفع بعد commi`

### `app/Services/Sponsorship/ContributionService.php`

`sha256: aaff75f050f080a89652298df941397893be3c1b48f09eebe41d9e192ce2b0e4`

`````php
<?php

namespace App\Services\Sponsorship;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SponsorshipProgramRole;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Rules\NoMarkup;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * E30 - دورة حياة المساهمة. **لا بوابة دفع**: تُسجَّل معلّقة ثم يؤكّدها مدير مخوَّل فقط (sponsorships.confirm، منفصلة عن إدارة الملفات/البرامج).
 *
 * - المبلغ الأصلي + عملته (قائمة بيضاء) أعداد صحيحة بالوحدة الصغرى. عند التأكيد يُسجَّل المعادل المحاسبي: عملة المحاسبة نفسها ⇒ نفس المبلغ؛ عملة أخرى ⇒
 *   يدخله المدير (لا سعر صرف حيّ) ويُدقَّق قرار التحويل. بعد التأكيد تُحمى الحقول المالية بنيويًا (SponsorshipContribution): التصحيح = استرداد ثم مساهمة جديدة.
 * - التأكيد/الإلغاء/الاسترداد بقفل الصف + تحقق الحالة داخل المعاملة (تأكيدان متزامنان ⇒ واحد ينجح). الاسترداد لا يسحب مكافآت لاعبين مُنحت سابقًا (لقطات تاريخية)،
 *   لكنه يخفّض التمويل/المستوى/الصدارة فورًا ويُسقط دور الراعي إن لم يبقَ للداعم مساهمة مؤكَّدة بالبرنامج.
 * - الإشعارات والكاش بعد الـcommit؛ فشلها لا يُرجع شيئًا. قراءات الكاش لا تُستعمل هنا إطلاقًا (القرارات من قاعدة البيانات).
 */
class ContributionService
{
    public function __construct(
        protected OperationalAuditService $audit,
        protected SupportTotals $totals,
        protected SupporterTierService $tiers,
        protected SponsorshipNotifier $notifier,
    ) {}

    /**
     * @param  array{reference?: ?string, notes?: ?string, is_anonymous?: ?bool}  $options
     *
     * @throws SponsorshipException
     */
    public function record(User $actor, SupporterProfile $supporter, SponsorshipProgram $program, string|int $amount, string $currency, array $options = []): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.manage'), 403);

        $currency = strtoupper(trim($currency));
        $minor = SponsorshipMoney::parse($amount, $currency);
        $extra = $this->cleanText($options);

        if (! in_array($program->status, [SponsorshipProgram::STATUS_DRAFT, SponsorshipProgram::STATUS_ACTIVE], true)) {
            throw new SponsorshipException('لا تُسجَّل مساهمات على برنامج مغلق أو ملغى.');
        }

        if ($supporter->status === SupporterProfile::STATUS_SUSPENDED) {
            throw new SponsorshipException('ملف الداعم موقوف.');
        }

        $contribution = DB::transaction(function () use ($actor, $supporter, $program, $minor, $currency, $options, $extra) {
            $contribution = new SponsorshipContribution;
            $contribution->forceFill([
                'supporter_profile_id' => $supporter->id,
                'sponsorship_program_id' => $program->id,
                'original_amount_minor' => $minor,
                'currency' => $currency,
                'status' => SponsorshipContribution::STATUS_PENDING,
                'is_anonymous' => array_key_exists('is_anonymous', $options) && $options['is_anonymous'] !== null ? (bool) $options['is_anonymous'] : (bool) $supporter->anonymous_by_default,
                'reference' => $extra['reference'],
                'notes' => $extra['notes'],
                'created_by' => $actor->id,
            ])->save();

            $this->audit->log('sponsorship_contribution_recorded', $contribution, [
                'public_id' => $contribution->public_id, 'supporter' => $supporter->public_id, 'program' => $program->slug,
                'original_amount_minor' => $minor, 'currency' => $currency,
            ], $actor);

            return $contribution;
        });

        SponsorshipCache::bust();

        return $contribution;
    }

    /** تصحيح مساهمة **معلّقة** فقط (خطأ إدخال). تغيير البرنامج/المبلغ/العملة يُدقَّق بإجراء مستقل. @throws SponsorshipException */
    public function updatePending(User $actor, SponsorshipContribution $contribution, array $data): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.manage'), 403);

        return DB::transaction(function () use ($actor, $contribution, $data) {
            $locked = SponsorshipContribution::query()->lockForUpdate()->findOrFail($contribution->id);

            if (! $locked->isPending()) {
                throw new SponsorshipException('لا يُعدَّل إلا المعلّق؛ صحّح المؤكَّد بالاسترداد.');
            }

            $changes = [];

            if (isset($data['amount']) || isset($data['currency'])) {
                $currency = strtoupper(trim((string) ($data['currency'] ?? $locked->currency)));
                $minor = isset($data['amount']) ? SponsorshipMoney::parse($data['amount'], $currency) : SponsorshipMoney::assertMinor((int) $locked->original_amount_minor);

                if ($minor !== (int) $locked->original_amount_minor || $currency !== $locked->currency) {
                    $changes['original_amount_minor'] = $minor;
                    $changes['currency'] = $currency;
                }
            }

            if (isset($data['sponsorship_program_id']) && (int) $data['sponsorship_program_id'] !== (int) $locked->sponsorship_program_id) {
                $target = SponsorshipProgram::query()->findOrFail((int) $data['sponsorship_program_id']);

                if (! in_array($target->status, [SponsorshipProgram::STATUS_DRAFT, SponsorshipProgram::STATUS_ACTIVE], true)) {
                    throw new SponsorshipException('لا يمكن النقل إلى برنامج مغلق أو ملغى.');
                }

                $changes['sponsorship_program_id'] = $target->id;
            }

            $text = $this->cleanText($data);

            foreach (['reference', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $changes[$field] = $text[$field];
                }
            }

            if (array_key_exists('is_anonymous', $data) && $data['is_anonymous'] !== null) {
                $changes['is_anonymous'] = (bool) $data['is_anonymous'];
            }

            if ($changes === []) {
                return $locked;
            }

            $before = $locked->only(array_keys($changes));
            $locked->forceFill($changes)->save();

            $this->audit->log('sponsorship_contribution_updated', $locked, ['changed' => array_keys($changes)], $actor);

            if (isset($changes['sponsorship_program_id'])) {
                $this->audit->log('sponsorship_contribution_program_changed', $locked, ['from' => $before['sponsorship_program_id'] ?? null, 'to' => $changes['sponsorship_program_id']], $actor);
            }

            return $locked;
        });
    }

    /**
     * يؤكّد مساهمة معلّقة: يسجّل المعادل المحاسبي ويُدقَّق. فقط sponsorships.confirm.
     *
     * @throws SponsorshipException
     */
    public function confirm(User $actor, SponsorshipContribution $contribution, string|int|null $accountingAmount = null): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.confirm'), 403);

        $before = null;
        $supporterId = 0;

        $confirmed = DB::transaction(function () use ($actor, $contribution, $accountingAmount, &$before, &$supporterId) {
            $locked = SponsorshipContribution::query()->lockForUpdate()->findOrFail($contribution->id);

            return $this->confirmLocked($actor, $locked, $accountingAmount, $before, $supporterId);
        });

        SponsorshipCache::bust();
        $this->publishChange($confirmed, $supporterId, $before, true);

        return $confirmed;
    }

    /**
     * E32: قلب التأكيد القابل لإعادة الاستعمال **داخل معاملة يملكها المنادي** (الصف مقفول مسبقًا بـlockForUpdate). نفس قواعد E30 حرفيًا (موقوف/برنامج مغلق/خلط عملات/المعادل المحاسبي/تدقيق)،
     * بلا تحقق صلاحية (المنادي مسؤول عنها) وبلا كاش أو إشعارات: يقوم بها المنادي بعد الـcommit (publishChange).
     *
     * @throws SponsorshipException
     */
    public function confirmLocked(?User $actor, SponsorshipContribution $locked, string|int|null $accountingAmount, mixed &$before, int &$supporterId): SponsorshipContribution
    {
        $accounting = SponsorshipMoney::accountingCurrency();

        if (! $locked->isPending()) {
            throw new SponsorshipException('هذه المساهمة ليست معلّقة (ربما عولجت للتو).');
        }

        $supporter = SupporterProfile::query()->findOrFail($locked->supporter_profile_id);
        $program = SponsorshipProgram::query()->findOrFail($locked->sponsorship_program_id);

        if ($supporter->status === SupporterProfile::STATUS_SUSPENDED) {
            throw new SponsorshipException('ملف الداعم موقوف؛ لا يمكن تأكيد مساهمته.');
        }

        if (! in_array($program->status, [SponsorshipProgram::STATUS_DRAFT, SponsorshipProgram::STATUS_ACTIVE], true)) {
            throw new SponsorshipException('البرنامج مغلق أو ملغى؛ لا يمكن تأكيد مساهمة عليه.');
        }

        if (SponsorshipContribution::query()->where('status', SponsorshipContribution::STATUS_CONFIRMED)->where('accounting_currency', '!=', $accounting)->exists()) {
            throw new SponsorshipException('عملة المحاسبة تغيّرت بعد وجود مساهمات مؤكَّدة بعملة أخرى؛ لا يمكن خلط العملات (راجع التوثيق).');
        }

        if ($locked->currency === $accounting) {
            $accountingMinor = (int) $locked->original_amount_minor;

            if ($accountingAmount !== null && $accountingAmount !== '' && SponsorshipMoney::parse($accountingAmount, $accounting) !== $accountingMinor) {
                throw new SponsorshipException('بعملة المحاسبة نفسها يجب أن يساوي المعادل المبلغ الأصلي.');
            }
        } else {
            if ($accountingAmount === null || $accountingAmount === '') {
                throw new SponsorshipException('أدخل المعادل بعملة المحاسبة ('.$accounting.') لأن عملة المساهمة مختلفة.');
            }

            $accountingMinor = SponsorshipMoney::parse($accountingAmount, $accounting);
        }

        $supporterId = (int) $supporter->id;
        $before = $this->tiers->forSupporter($supporter);

        $locked->forceFill([
            'accounting_amount_minor' => $accountingMinor,
            'accounting_currency' => $accounting,
            'status' => SponsorshipContribution::STATUS_CONFIRMED,
            'confirmed_at' => now(),
            'confirmed_by' => $actor?->id,
        ])->save();

        $this->audit->log('sponsorship_contribution_confirmed', $locked, [
            'public_id' => $locked->public_id, 'supporter' => $supporter->public_id, 'program' => $program->slug,
            'original_amount_minor' => (int) $locked->original_amount_minor, 'currency' => $locked->currency,
            'accounting_amount_minor' => $accountingMinor, 'accounting_currency' => $accounting,
        ], $actor);

        if ($locked->currency !== $accounting) {
            $this->audit->log('sponsorship_contribution_normalized', $locked, [
                'from_currency' => $locked->currency, 'from_minor' => (int) $locked->original_amount_minor,
                'to_currency' => $accounting, 'to_minor' => $accountingMinor,
            ], $actor);
        }

        return $locked;
    }

    /** @throws SponsorshipException */
    public function cancel(User $actor, SponsorshipContribution $contribution, string $reason): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.confirm'), 403);
        $reason = $this->requireReason($reason);

        $cancelled = DB::transaction(function () use ($actor, $contribution, $reason) {
            $locked = SponsorshipContribution::query()->lockForUpdate()->findOrFail($contribution->id);

            if (! $locked->isPending()) {
                throw new SponsorshipException('يُلغى المعلّق فقط؛ المؤكَّد يُسترد.');
            }

            $locked->forceFill(['status' => SponsorshipContribution::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'status_note' => $reason])->save();
            $this->audit->log('sponsorship_contribution_cancelled', $locked, ['public_id' => $locked->public_id, 'reason' => $reason], $actor);

            return $locked;
        });

        SponsorshipCache::bust();

        return $cancelled;
    }

    /** @throws SponsorshipException */
    public function refund(User $actor, SponsorshipContribution $contribution, string $reason): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.confirm'), 403);
        $reason = $this->requireReason($reason);
        $before = null;
        $supporterId = 0;

        $refunded = DB::transaction(function () use ($actor, $contribution, $reason, &$before, &$supporterId) {
            $locked = SponsorshipContribution::query()->lockForUpdate()->findOrFail($contribution->id);

            $locked = $this->refundLocked($actor, $locked, $reason, $before, $supporterId);

            // E32: مساهمة ناتجة عن طلب دفع ⇒ يُسجَّل الطلب مستردًّا في المعاملة نفسها (لا تعارض حالات بين الطلب والمساهمة).
            app(\App\Services\Payments\SponsorshipPaymentService::class)->syncRefundFromContribution($locked, $actor, $reason);

            return $locked;
        });

        SponsorshipCache::bust();
        $this->publishChange($refunded, $supporterId, $before, false);
        app(\App\Services\Payments\SponsorshipPaymentService::class)->notifyLinkedRefund($refunded);

        return $refunded;
    }

    /**
     * E32: قلب الاسترداد **داخل معاملة المنادي** (الصف مقفول مسبقًا). نفس قواعد E30 (المؤكَّد فقط، إسقاط دور الراعي عند انعدام المؤكَّد، تدقيق) بلا صلاحية/كاش/إشعارات.
     * لا يسحب أي مكافأة لاعب سبق منحها (لقطات تاريخية).
     *
     * @throws SponsorshipException
     */
    public function refundLocked(?User $actor, SponsorshipContribution $locked, string $reason, mixed &$before, int &$supporterId): SponsorshipContribution
    {
        if (! $locked->isConfirmed()) {
            throw new SponsorshipException('يُسترد المؤكَّد فقط.');
        }

        $supporter = SupporterProfile::query()->findOrFail($locked->supporter_profile_id);
        $supporterId = (int) $supporter->id;
        $before = $this->tiers->forSupporter($supporter);

        $locked->forceFill(['status' => SponsorshipContribution::STATUS_REFUNDED, 'refunded_at' => now(), 'refunded_by' => $actor?->id, 'status_note' => $reason])->save();
        $this->audit->log('sponsorship_contribution_refunded', $locked, [
            'public_id' => $locked->public_id, 'reason' => $reason, 'accounting_amount_minor' => (int) $locked->accounting_amount_minor,
        ], $actor);

        // لا مساهمة مؤكَّدة متبقية للداعم بهذا البرنامج ⇒ يسقط دوره (راعٍ/راعٍ رئيسي) ويُدقَّق.
        if (! $this->totals->hasConfirmed($supporterId, (int) $locked->sponsorship_program_id)) {
            $roles = SponsorshipProgramRole::query()->where('sponsorship_program_id', $locked->sponsorship_program_id)->where('supporter_profile_id', $supporterId)->get();

            foreach ($roles as $role) {
                $this->audit->log($role->role === SponsorshipProgramRole::ROLE_MAIN_SPONSOR ? 'main_sponsor_removed' : 'sponsorship_role_removed', $locked->program, ['supporter' => $supporter->public_id, 'reason' => 'refund'], $actor);
                $role->delete();
            }
        }

        return $locked;
    }

    /** يبدّل إخفاء الهوية لمساهمة (قرار خصوصية الداعم) دون المساس بالمبالغ. مدقَّق. */
    public function setAnonymity(User $actor, SponsorshipContribution $contribution, bool $anonymous): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.manage'), 403);

        if ((bool) $contribution->is_anonymous !== $anonymous) {
            $contribution->forceFill(['is_anonymous' => $anonymous])->save();
            $this->audit->log('sponsorship_contribution_anonymity_changed', $contribution, ['public_id' => $contribution->public_id, 'anonymous' => $anonymous], $actor);
            SponsorshipCache::bust();
        }

        return $contribution;
    }

    /** إشعارات ما بعد الـcommit: تأكيد الدعم (للتأكيد فقط) وتغيّر المستوى. فشلها لا يمسّ المعاملة. (عامة منذ E32: يستدعيها مسار التحقق من الدفع بعد commit معاملته.) */
    public function publishChange(SponsorshipContribution $contribution, int $supporterId, mixed $tierBefore, bool $confirmed): void
    {
        try {
            $supporter = SupporterProfile::query()->with('user')->find($supporterId);

            if ($supporter === null) {
                return;
            }

            $contribution->setRelation('supporter', $supporter);

            if ($confirmed) {
                $this->notifier->supportConfirmed($contribution);
            }

            $this->notifier->tierChanged($supporter, $tierBefore, $this->tiers->forSupporter($supporter), (int) $contribution->id);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function requireReason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            throw new SponsorshipException('اكتب سببًا واضحًا (3–255 حرفًا).');
        }

        if (Validator::make(['r' => $reason], ['r' => [new NoMarkup]])->fails()) {
            throw new SponsorshipException('لا يُسمح بالوسوم أو الأكواد في السبب.');
        }

        return $reason;
    }

    /** @return array{reference: ?string, notes: ?string} */
    protected function cleanText(array $data): array
    {
        $max = (array) config('sponsorship.profile');
        $validator = Validator::make($data, [
            'reference' => ['nullable', 'string', 'max:'.$max['reference_max'], new NoMarkup],
            'notes' => ['nullable', 'string', 'max:'.$max['notes_max'], new NoMarkup],
        ]);

        if ($validator->fails()) {
            throw new SponsorshipException((string) $validator->errors()->first());
        }

        return [
            'reference' => filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null,
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
        ];
    }
}
`````

#### خريطة تعديلات `app/Services/Sponsorship/SponsorshipNotifier.php`

  - السطر 44: `// ---- E32: إشعارات طلب الدعم/الدفع (لصاحب الطلب مباشرة، لا عبر ملف الداعم: قد لا يوجد ملف قبل التحقق). بلا مبالغ ولا مراجع تحويل بالنص. ----`

### `app/Services/Sponsorship/SponsorshipNotifier.php`

`sha256: 125caaa852faacf7f26771b4a5cc26b9cd544f2579e010e32f8d53762cf690dc`

`````php
<?php

namespace App\Services\Sponsorship;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use App\Models\SupporterTier;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Throwable;

/**
 * E30 - إشعارات الرعاية (E15). تُستدعى **بعد** نجاح التغيير المالي (بعد الـcommit) وفشلها لا يُرجع أي حالة (try/catch + report). مفاتيح idempotency دلالية.
 * للداعم المرتبط بحساب فقط؛ **لا مبالغ ولا مراجع ولا ملاحظات خاصة** بنصوص الإشعارات أو معاملاتها أبدًا.
 */
class SponsorshipNotifier
{
    public function __construct(protected NotificationDispatcher $dispatcher) {}

    public function supportConfirmed(SponsorshipContribution $contribution): void
    {
        $this->send($contribution->supporter, NotificationType::SupportConfirmed, [], 'support_confirmed:'.$contribution->id, $this->actionParams($contribution->supporter));
    }

    public function tierChanged(SupporterProfile $supporter, ?SupporterTier $from, ?SupporterTier $to, int $contributionId): void
    {
        if (($from?->id) === ($to?->id)) {
            return;
        }

        $up = $to !== null && ($from === null || (int) $to->minimum_accounting_amount_minor > (int) $from->minimum_accounting_amount_minor);

        $this->send($supporter, NotificationType::SupporterTierChanged, ['tier' => $to?->name, 'up' => $up], 'supporter_tier:'.$supporter->id.':'.($to?->id ?? 0).':'.$contributionId, $this->actionParams($supporter));
    }

    public function programStarted(SupporterProfile $supporter, SponsorshipProgram $program): void
    {
        $this->send($supporter, NotificationType::SponsorshipProgramStarted, ['title' => $program->title], 'sponsorship_program_started:'.$program->id.':'.$supporter->id);
    }

    // ---- E32: إشعارات طلب الدعم/الدفع (لصاحب الطلب مباشرة، لا عبر ملف الداعم: قد لا يوجد ملف قبل التحقق). بلا مبالغ ولا مراجع تحويل بالنص. ----

    public function paymentReceived(SponsorshipPayment $payment): void
    {
        $this->sendToUser($payment, NotificationType::SupportRequestReceived, [], 'support_request_received:'.$payment->id);
    }

    public function paymentActionRequired(SponsorshipPayment $payment): void
    {
        $this->sendToUser($payment, NotificationType::SupportActionRequired, ['note' => (string) $payment->user_message], 'support_action_required:'.$payment->id.':'.($payment->clarification_requested_at?->timestamp ?? 0));
    }

    public function paymentRejected(SponsorshipPayment $payment): void
    {
        $this->sendToUser($payment, NotificationType::SupportRejected, ['note' => (string) $payment->user_message], 'support_rejected:'.$payment->id);
    }

    public function paymentRefunded(SponsorshipPayment $payment): void
    {
        $this->sendToUser($payment, NotificationType::SupportRefunded, [], 'support_refunded:'.$payment->id);
    }

    protected function sendToUser(SponsorshipPayment $payment, NotificationType $type, array $params, string $key): void
    {
        if (! (bool) config('sponsorship.notifications.enabled', true) || ! (bool) config('payments.notifications.enabled', true)) {
            return;
        }

        try {
            $user = $payment->relationLoaded('user') ? $payment->user : User::query()->find($payment->user_id);

            if ($user === null || $user->is_frozen) {
                return;
            }

            $this->dispatcher->dispatch($user, $type, $params, $key, ['sponsorship_payment_id' => $payment->id], ['payment' => (string) $payment->public_id], 'support.payments.show');
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return array<string, string> */
    protected function actionParams(SupporterProfile $supporter): array
    {
        return $supporter->isPublic() ? ['supporter' => (string) $supporter->slug] : [];
    }

    protected function send(SupporterProfile $supporter, NotificationType $type, array $params, string $key, array $actionParams = []): void
    {
        if (! (bool) config('sponsorship.notifications.enabled', true)) {
            return;
        }

        try {
            $user = $supporter->user;

            if ($user === null || $user->is_frozen) {
                return;
            }

            $route = $actionParams !== [] ? 'supporters.show' : null;
            $this->dispatcher->dispatch($user, $type, $params, $key, ['supporter_profile_id' => $supporter->id], $actionParams, $route);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
`````

#### خريطة تعديلات `app/Services/Sponsorship/SupporterService.php`

  - السطر 59: `* E32: ملف الداعم لمستخدم دفع حقيقي، يُستدعى **داخل معاملة تحقق الدفع** (لا يتطلب sponsorships.manage؛ المنادي SponsorshipPaymentService يتحقق من صلاح`

### `app/Services/Sponsorship/SupporterService.php`

`sha256: 4f2da2cf52ac9975ab0e78b681f78d2108c854869bca41a290d377866e415f39`

`````php
<?php

namespace App\Services\Sponsorship;

use App\Models\SupporterProfile;
use App\Models\User;
use App\Rules\NoMarkup;
use App\Services\OperationalAuditService;
use App\Support\SafeUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * E30 - ملفات الداعمين. الكتابة الإدارية فقط (sponsorships.manage) وبلا تعيين جماعي؛ كل تغيير يُدقَّق. لا تفاصيل دفع هنا ولا بأي مكان.
 * المدخلات نص عادي (NoMarkup)، الرابط يمرّ بـSafeUrl (http/https فقط)، الشعار JPEG/PNG/WEBP فقط. الـslug يُولَّد مرة ولا يتغير (روابط ثابتة).
 */
class SupporterService
{
    public function __construct(protected OperationalAuditService $audit, protected SupporterLogoService $logos) {}

    /**
     * @param  array{display_name?: string, type?: string, description?: ?string, website_url?: ?string, status?: string, is_public?: bool, anonymous_by_default?: bool, user_id?: ?int}  $data
     *
     * @throws SponsorshipException
     */
    public function create(User $actor, array $data, ?UploadedFile $logo = null): SupporterProfile
    {
        $this->authorize($actor);
        $clean = $this->clean($data, true);
        $logoPath = $logo !== null ? $this->logos->store($logo) : null;

        try {
            $profile = DB::transaction(function () use ($actor, $clean, $logoPath) {
                $profile = new SupporterProfile;
                $profile->forceFill($clean + [
                    'slug' => $this->uniqueSlug($clean['display_name']),
                    'logo_path' => $logoPath,
                    'created_by' => $actor->id,
                ])->save();

                $this->audit->log('supporter_created', $profile, ['public_id' => $profile->public_id, 'type' => $profile->type, 'name' => $profile->display_name], $actor);

                return $profile;
            });
        } catch (\Throwable $e) {
            $this->logos->delete($logoPath);

            throw $e;
        }

        SponsorshipCache::bust();

        return $profile;
    }

    /**
     * E32: ملف الداعم لمستخدم دفع حقيقي، يُستدعى **داخل معاملة تحقق الدفع** (لا يتطلب sponsorships.manage؛ المنادي SponsorshipPaymentService يتحقق من صلاحية التأكيد).
     * يقفل صف المستخدم أولًا فيتسلسل أي تحققَين متزامنَين لنفس المستخدم (لا ملف مكرر؛ والقيد UNIQUE(user_id) هو الحدّ الأخير).
     *  - ملف قائم ⇒ يُعاد كما هو (لا تغيير لاسمه أو حالته). استثناء مقصود: ملف أنشأه المستخدم بنفسه عبر الدفع (created_by = user_id) وكان خاصًا فقط لأن أول دعمه كان مجهولًا،
     *    ثم اختار الآن الظهور العلني ⇒ يصير علنيًا (قراره الصريح بهذا الطلب). ملفات الإدارة لا تُمسّ.
     *  - ملف جديد ⇒ علني فقط إن اختار الظهور باسمه؛ دعمٌ مجهول لا ينشئ صفحة علنية بل ملفًا خاصًا (الداعم المجهول لا يُعرَف من أي سطح عام).
     */
    public function ensureForUser(User $owner, ?string $displayName, bool $publicChoice, ?User $actor): SupporterProfile
    {
        User::query()->lockForUpdate()->findOrFail($owner->id);

        $existing = SupporterProfile::query()->where('user_id', $owner->id)->lockForUpdate()->first();

        if ($existing !== null) {
            if ($publicChoice && ! $existing->is_public && $existing->status === SupporterProfile::STATUS_ACTIVE && (int) $existing->created_by === (int) $owner->id) {
                $existing->forceFill(['is_public' => true])->save();
                $this->audit->log('supporter_visibility_changed', $existing, ['public_id' => $existing->public_id, 'public' => true, 'source' => 'checkout'], $actor);
            }

            return $existing;
        }

        $name = trim((string) $displayName);
        $max = (int) config('sponsorship.profile.name_max', 80);

        if (mb_strlen($name) < 2 || mb_strlen($name) > $max || Validator::make(['n' => $name], ['n' => [new NoMarkup]])->fails()) {
            $name = Str::limit(trim(strip_tags((string) $owner->name)), $max, '');
        }

        if (mb_strlen($name) < 2) {
            $name = 'داعم';
        }

        $profile = new SupporterProfile;
        $profile->forceFill([
            'user_id' => $owner->id,
            'type' => SupporterProfile::TYPE_INDIVIDUAL,
            'display_name' => $name,
            'slug' => $this->uniqueSlug($name),
            'status' => SupporterProfile::STATUS_ACTIVE,
            'is_public' => $publicChoice,
            'anonymous_by_default' => ! $publicChoice,
            'created_by' => $owner->id,
        ])->save();

        $this->audit->log('supporter_created', $profile, ['public_id' => $profile->public_id, 'type' => $profile->type, 'source' => 'checkout', 'public' => $publicChoice], $actor);

        return $profile;
    }

    /** @throws SponsorshipException */
    public function update(User $actor, SupporterProfile $profile, array $data, ?UploadedFile $logo = null, bool $removeLogo = false): SupporterProfile
    {
        $this->authorize($actor);
        $clean = $this->clean($data, false, $profile);
        $newLogo = $logo !== null ? $this->logos->store($logo) : null;
        $oldLogo = $profile->logo_path;

        try {
            DB::transaction(function () use ($actor, $profile, $clean, $newLogo, $removeLogo) {
                $profile->forceFill($clean);

                if ($newLogo !== null) {
                    $profile->logo_path = $newLogo;
                } elseif ($removeLogo) {
                    $profile->logo_path = null;
                }

                $changed = array_keys($profile->getDirty());
                $profile->save();

                if ($changed !== []) {
                    $this->audit->log('supporter_updated', $profile, ['changed' => $changed], $actor);
                }
            });
        } catch (\Throwable $e) {
            $this->logos->delete($newLogo);

            throw $e;
        }

        if (($newLogo !== null || $removeLogo) && $oldLogo !== $profile->logo_path) {
            $this->logos->delete($oldLogo);
        }

        SponsorshipCache::bust();

        return $profile->refresh();
    }

    /** @throws SponsorshipException */
    public function setStatus(User $actor, SupporterProfile $profile, string $status): SupporterProfile
    {
        $this->authorize($actor);

        if (! in_array($status, SupporterProfile::STATUSES, true)) {
            throw new SponsorshipException('حالة غير صالحة.');
        }

        $from = $profile->status;

        if ($from !== $status) {
            $profile->forceFill(['status' => $status])->save();
            $this->audit->log('supporter_status_changed', $profile, ['from' => $from, 'to' => $status], $actor);
            SponsorshipCache::bust();
        }

        return $profile;
    }

    protected function authorize(User $actor): void
    {
        abort_unless($actor->can('sponsorships.manage'), 403);
    }

    /** @return array<string, mixed> */
    protected function clean(array $data, bool $creating, ?SupporterProfile $existing = null): array
    {
        $max = (array) config('sponsorship.profile');

        $validator = Validator::make($data, [
            'display_name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:'.$max['name_max'], new NoMarkup],
            'type' => [$creating ? 'required' : 'sometimes', 'in:'.implode(',', SupporterProfile::TYPES)],
            'description' => ['nullable', 'string', 'max:'.$max['description_max'], new NoMarkup],
            'website_url' => ['nullable', 'string', 'max:'.$max['url_max']],
            'status' => ['sometimes', 'in:'.implode(',', SupporterProfile::STATUSES)],
            'is_public' => ['sometimes', 'boolean'],
            'anonymous_by_default' => ['sometimes', 'boolean'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ], [], ['display_name' => 'الاسم', 'type' => 'النوع', 'description' => 'الوصف', 'website_url' => 'الموقع', 'user_id' => 'المستخدم']);

        if ($validator->fails()) {
            throw new SponsorshipException((string) $validator->errors()->first());
        }

        $out = [];

        foreach (['display_name', 'type', 'status', 'is_public', 'anonymous_by_default'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = is_string($data[$key]) ? trim($data[$key]) : $data[$key];
            }
        }

        if (array_key_exists('description', $data)) {
            $out['description'] = filled($data['description']) ? trim((string) $data['description']) : null;
        }

        if (array_key_exists('website_url', $data)) {
            $url = filled($data['website_url']) ? SafeUrl::normalize((string) $data['website_url'], (int) $max['url_max']) : null;

            if (filled($data['website_url']) && $url === null) {
                throw new SponsorshipException('رابط الموقع غير آمن أو غير صالح (http/https فقط).');
            }

            $out['website_url'] = $url;
        }

        if (array_key_exists('user_id', $data)) {
            $userId = filled($data['user_id']) ? (int) $data['user_id'] : null;

            if ($userId !== null && SupporterProfile::query()->where('user_id', $userId)->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists()) {
                throw new SponsorshipException('هذا المستخدم مرتبط بملف داعم آخر.');
            }

            $out['user_id'] = $userId;
        }

        if ($creating) {
            $out += ['status' => SupporterProfile::STATUS_ACTIVE, 'is_public' => true, 'anonymous_by_default' => false];
        }

        return $out;
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug(Str::ascii($name)), 60, '') ?: 'supporter';

        do {
            $slug = $base.'-'.Str::lower(Str::random(5));
        } while (SupporterProfile::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
`````

#### خريطة تعديلات `bootstrap/app.php`

  - السطر 25: `// E32: webhook عام لمزوّدين إلكترونيين مستقبليين (مغلق افتراضيًا). مجموعة api = بلا جلسة/CSRF بطبيعتها، لا استثناء من أي وسيط.`

### `bootstrap/app.php`

`sha256: 6d7f5df6781fd345322b40fda66bc9c4b753c8d8c09f4cf43e355379abe9de57`

`````php
<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureAccountIsNotFrozen;
use App\Http\Middleware\RequestObservability;
use App\Http\Middleware\RestrictBroadcastChannels;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustApplicationHosts;
use App\Http\Middleware\TrackDeviceFingerprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // E24: جاهزية الموازن /ready خارج مجموعتَي web/api عمدًا (بلا جلسة ولا CSRF ولا تتبّع جهاز): فحص خفيف بلا آثار جانبية.
        then: function () {
            Route::get('/ready', [HealthController::class, 'ready'])->name('health.ready');

            // E32: webhook عام لمزوّدين إلكترونيين مستقبليين (مغلق افتراضيًا). مجموعة api = بلا جلسة/CSRF بطبيعتها، لا استثناء من أي وسيط.
            Route::middleware('api')->group(__DIR__.'/../routes/payments_webhooks.php');
        },
    )
    // E21: قنوات البثّ الخاصة + مسار /broadcasting/auth (وسيط web، يتطلب مستخدمًا مصادَقًا)
    // E26: حدّ معدّل لمسار /broadcasting/auth (كل اشتراك قناة خاصة يكلّف استعلامًا) + قصر الأسماء على private-chat.* (يغلق presence-chat.* الذي كان يسرّب user_id الداخلي).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'throttle:broadcast-auth', RestrictBroadcastChannels::class]])
    ->withMiddleware(function (Middleware $middleware) {
        // E24: معرّف طلب + سجل الطلبات البطيئة + Server-Timing محليًا (عالميّ، فيشمل web وapi وكل الاستثناءات).
        $middleware->prepend(RequestObservability::class);

        // E26: تقييد ترويسة Host (يمنع تسميم روابط إعادة التعيين/التوثيق). صامت محليًا وبالاختبارات؛ انظر TrustApplicationHosts.
        $middleware->prepend(TrustApplicationHosts::class);

        $middleware->web(append: [
            TrackDeviceFingerprint::class,
            SecurityHeaders::class,
        ]);

        $middleware->api(append: [
            TrackDeviceFingerprint::class,
            SecurityHeaders::class,
        ]);

        $middleware->throttleApi();

        $middleware->alias([
            'account.active' => EnsureAccountIsNotFrozen::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
`````

#### خريطة تعديلات `config/db_transfer.php`

  - (لا وسم E32 نصيّ؛ انظر الملف)

### `config/db_transfer.php`

`sha256: 190fbd76de620737b0d6ddda81eebb9155019c8e4fb0a3f85588b0b0a843043a`

`````php
<?php

/*
|--------------------------------------------------------------------------
| E31 - نقل البيانات من SQLite إلى MySQL (php artisan db:transfer-sqlite-to-mysql)
|--------------------------------------------------------------------------
| أداة محلية/تجريبية لمرة واحدة، تُرفض عند APP_ENV=production. كل القيم اختيارية؛ الأمر يعمل بلا أي منها (يستعمل اتصال mysql الحالي).
| لا أسرار هنا: كلمات المرور تأتي من البيئة فقط ولا تُطبع أبدًا.
|
| المصدر: ملف SQLite يُمرَّر بـ--source أو MIGRATION_SQLITE_DATABASE (مسار مطلق أو نسبي لجذر المشروع). الافتراضي: قاعدة اتصال sqlite الحالية.
|        تنبيه: إن بدّلت DB_CONNECTION=mysql وبقي DB_DATABASE باسم قاعدة MySQL فلن يجد الأمر الملف - مرِّره صراحةً.
| الهدف: اتصال mysql (DB_*) أو أي اتصال مسمّى mysql/mariadb عبر --target. تجاوزات اختيارية بلا تكرار الإعداد: MIGRATION_MYSQL_{HOST,PORT,DATABASE,USERNAME,PASSWORD}.
*/

return [

    'target_connection' => env('MIGRATION_TARGET_CONNECTION', 'mysql'),

    'sqlite_database' => env('MIGRATION_SQLITE_DATABASE'),

    /*
    | تجاوزات إعداد الهدف (تُهمَل القيمة الفارغة). تسمح ببقاء DB_* في .env على SQLite أثناء مرحلة النقل.
    */
    'target_overrides' => array_filter([
        'host' => env('MIGRATION_MYSQL_HOST'),
        'port' => env('MIGRATION_MYSQL_PORT'),
        'database' => env('MIGRATION_MYSQL_DATABASE'),
        'username' => env('MIGRATION_MYSQL_USERNAME'),
        'password' => env('MIGRATION_MYSQL_PASSWORD'),
    ], static fn ($value) => $value !== null && $value !== ''),

    /*
    | حجم الدفعة (صفوف لكل INSERT). يُقيَّد داخل [500, 2000]؛ ويُخفَّض تلقائيًا كي لا يتجاوز عدد المتغيرات 60000 (حد MySQL 65535)
    | ولا حجم الدفعة التقريبي max_batch_bytes (حماية من max_allowed_packet عند صفوف كبيرة).
    */
    'batch_size' => (int) env('MIGRATION_BATCH_SIZE', 1000),
    'max_batch_bytes' => (int) env('MIGRATION_MAX_BATCH_BYTES', 1_000_000),

    /*
    | الجداول المؤقتة التشغيلية: لا تُنقل (جلسات المستخدمين تُنهى عند التحويل؛ الكاش يُعاد بناؤه؛ طابور قاعدة البيانات الحيّ لا يُنقل
    | كي لا تُنفَّذ مهام قديمة مرتين؛ failed_jobs تُترك للنظافة؛ رموز إعادة تعيين كلمة المرور قصيرة العمر).
    | جدول migrations لا يُنقل أبدًا (هدفه يُبنى بـphp artisan migrate).
    */
    'exclude' => [
        'migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
    ],

    /*
    | جداول تُملأ بصفوف مرجعية بواسطة الترحيلات نفسها (currencies عبر seed_system_currencies، supporter_tiers عبر create_sponsorship_tables).
    | الهدف بعد migrate لا يكون «فارغًا» فيها. الأمر يقبل ذلك فقط إذا كان كل صف في الهدف موجودًا في المصدر بالمفتاح الطبيعي نفسه وبالمعرّف نفسه،
    | ثم يحذف تلك الصفوف المرجعية ويستبدلها بصفوف المصدر (المصدر هو الحقيقة). أي تعارض معرّف يوقف الأمر. الصيغة: جدول => عمود المفتاح الطبيعي.
    */
    'reference_tables' => [
        'currencies' => 'internal_key',
        'supporter_tiers' => 'slug',
    ],

    /*
    | فحوص المال التي تُقارَن مجاميعها بالأعداد الصحيحة بين المصدر والهدف (وحدات صغرى؛ لا float). جدول => أعمدة.
    */
    'financial_sums' => [
        'wallets' => ['pending_balance', 'available_balance', 'lifetime_earned', 'lifetime_redeemed'],
        'currency_transactions' => ['amount'],
        'redemption_requests' => ['gems_amount'],
        'store_purchases' => ['price_amount'],
        'currency_packs' => ['base_amount', 'bonus_amount', 'price_minor'],
        'sponsorship_contributions' => ['original_amount_minor', 'accounting_amount_minor'],
        'sponsorship_payments' => ['amount_minor', 'accounting_amount_minor'],
        'sponsorship_reward_bonuses' => ['base_amount', 'calculated_bonus_amount', 'bonus_amount', 'final_amount', 'funding_snapshot_minor'],
        'competitive_reward_grants' => ['amount'],
        'xp_transactions' => ['amount'],
    ],
];
`````

#### خريطة تعديلات `config/platform.php`

  - السطر 132: `* E32 - تعليمات التحويل اليدوي العلنية. **لا أسرار**: لا كلمات مرور بنكية ولا مفاتيح API ولا مفاتيح خاصة (تُرفض بالتحقق في صفحة الإعدادات).`

### `config/platform.php`

`sha256: faa14a2f32e5616ad26e4adc15d425ccdaf06f032c76df6dddf74947830adadb`

`````php
<?php

/**
 * القيم الافتراضية لكل إعدادات المنصة، منظَّمة حسب Group - هذا هو مصدر
 * الحقيقة الوحيد للـDefaults (لا Seeder صفوف لكل إعداد). قيمة قاعدة
 * البيانات (platform_settings) تتغلّب على القيمة هنا فقط إن وُجدت صراحة؛
 * غيابها = استخدم ما هنا. إضافة إعداد جديد مستقبلاً = سطر جديد هنا +
 * حقل بصفحة الإدارة - لا Migration. راجع docs/platform-settings.md.
 */
return [

    'general' => [
        'site_name' => ['type' => 'string', 'default' => 'أحجيات'],
        'short_name' => ['type' => 'string', 'default' => 'أحجيات'],
        'short_description' => ['type' => 'text', 'default' => 'منصة ألغاز وتحديات ذهنية بنظام الجواهر.'],
        'tagline' => ['type' => 'string', 'default' => 'حلّ الألغاز، اجمع الجواهر، وتصدّر لوحة الصدارة'],
        'public_email' => ['type' => 'string', 'default' => null],
        'support_email' => ['type' => 'string', 'default' => null],
        'contact_phone' => ['type' => 'string', 'default' => null],
        'display_timezone' => ['type' => 'string', 'default' => 'Asia/Damascus'],
    ],

    'branding' => [
        'logo_main' => ['type' => 'image', 'default' => null],
        'logo_light' => ['type' => 'image', 'default' => null],
        'logo_dark' => ['type' => 'image', 'default' => null],
        'logo_square' => ['type' => 'image', 'default' => null],
        'favicon' => ['type' => 'image', 'default' => null],
        'social_share_image' => ['type' => 'image', 'default' => null],
    ],

    'appearance' => [
        'default_theme' => ['type' => 'string', 'default' => 'dark'],
        'allow_theme_switch' => ['type' => 'boolean', 'default' => true],
        'color_preset' => ['type' => 'string', 'default' => 'amethyst'],
        'color_primary' => ['type' => 'color', 'default' => '#8b5cf6'],
        'color_secondary' => ['type' => 'color', 'default' => '#22d3ee'],
        'color_accent' => ['type' => 'color', 'default' => '#fcd34d'],
        'color_success' => ['type' => 'color', 'default' => '#34d399'],
        'color_warning' => ['type' => 'color', 'default' => '#fbbf24'],
        'color_danger' => ['type' => 'color', 'default' => '#fb7185'],
        'font_family' => ['type' => 'string', 'default' => 'cairo'],
        'base_font_size' => ['type' => 'string', 'default' => 'normal'],
        'ui_radius' => ['type' => 'string', 'default' => 'rounded'],
    ],

    'home' => [
        'hero_title' => ['type' => 'string', 'default' => null],
        'hero_subtitle' => ['type' => 'string', 'default' => null],
        'cta_text' => ['type' => 'string', 'default' => 'تصفّح الأحجيات'],
        'show_featured_season' => ['type' => 'boolean', 'default' => true],
        'show_categories' => ['type' => 'boolean', 'default' => true],
        'show_leaderboard' => ['type' => 'boolean', 'default' => true],
        'show_challenges' => ['type' => 'boolean', 'default' => true],
    ],

    'navigation' => [
        'show_seasons_link' => ['type' => 'boolean', 'default' => true],
        'show_puzzles_link' => ['type' => 'boolean', 'default' => true],
        'show_leaderboard_link' => ['type' => 'boolean', 'default' => true],
        'show_challenges_link' => ['type' => 'boolean', 'default' => true],
        'show_gem_balance' => ['type' => 'boolean', 'default' => true],
    ],

    'contact' => [
        'whatsapp' => ['type' => 'url', 'default' => null],
        'instagram' => ['type' => 'url', 'default' => null],
        'facebook' => ['type' => 'url', 'default' => null],
        'x_twitter' => ['type' => 'url', 'default' => null],
        'telegram' => ['type' => 'url', 'default' => null],
        'youtube' => ['type' => 'url', 'default' => null],
        'tiktok' => ['type' => 'url', 'default' => null],
    ],

    'seo' => [
        'meta_title' => ['type' => 'string', 'default' => null],
        'meta_description' => ['type' => 'text', 'default' => 'منصة ألغاز وتحديات ذهنية عربية بنظام مكافآت الجواهر.'],
        'meta_keywords' => ['type' => 'string', 'default' => null],
        'indexing_enabled' => ['type' => 'boolean', 'default' => true],
        'twitter_card_type' => ['type' => 'string', 'default' => 'summary_large_image'],
    ],

    'access' => [
        'allow_registration' => ['type' => 'boolean', 'default' => true],
        'show_registration_cta' => ['type' => 'boolean', 'default' => true],
    ],

    'announcement' => [
        'enabled' => ['type' => 'boolean', 'default' => false],
        'text' => ['type' => 'string', 'default' => null],
        'type' => ['type' => 'string', 'default' => 'info'],
        'url' => ['type' => 'url', 'default' => null],
        'cta_label' => ['type' => 'string', 'default' => null],
    ],

    'footer' => [
        'description' => ['type' => 'text', 'default' => null],
        'copyright_text' => ['type' => 'string', 'default' => null],
        'show_social_links' => ['type' => 'boolean', 'default' => true],
        'show_legal_links' => ['type' => 'boolean', 'default' => true],
    ],

    /**
     * E14: مفتاح الإيقاف الشامل + مفاتيح كل مصدر مستقلة. افتراضيًا الكل
     * مُعطَّل (بند 548/600) - تثبيت جديد يعمل بلا أي إعلان حتى يُفعِّلها
     * المالك صراحةً من لوحة الإدارة. تُقرَأ عبر PlatformSettingsService
     * فقط - تغييرها يُبطِل الـCache فورًا (بند 602: لا إعلان عالق بالذاكرة).
     */
    'advertising' => [
        'ads_enabled' => ['type' => 'boolean', 'default' => false],
        'direct_sponsors_enabled' => ['type' => 'boolean', 'default' => false],
        'external_ads_enabled' => ['type' => 'boolean', 'default' => false],
        'max_ads_desktop' => ['type' => 'integer', 'default' => 2],
        'max_ads_mobile' => ['type' => 'integer', 'default' => 1],
    ],

    /**
     * E15 - إعدادات الإشعارات العامة (مجموعة قليلة عمدًا، بلا محرّك أعلام). الساعات بالمنطقة الزمنية للمنصة
     * (config('app.timezone') = UTC) - نفس تعريف "اليوم" بمهام E13 والسلسلة.
     */
    'notifications' => [
        'notifications_enabled' => ['type' => 'boolean', 'default' => true],
        'streak_warning_enabled' => ['type' => 'boolean', 'default' => true],
        'streak_warning_hour' => ['type' => 'integer', 'default' => 18],
        'min_streak_for_warning' => ['type' => 'integer', 'default' => 2],
        'daily_reminder_enabled' => ['type' => 'boolean', 'default' => true],
        'daily_reminder_hour' => ['type' => 'integer', 'default' => 10],
        'max_reengagement_per_day' => ['type' => 'integer', 'default' => 1],
    ],

    /**
     * E32 - تعليمات التحويل اليدوي العلنية. **لا أسرار**: لا كلمات مرور بنكية ولا مفاتيح API ولا مفاتيح خاصة (تُرفض بالتحقق في صفحة الإعدادات).
     * تُعرض فقط لصاحب طلب الدعم داخل صفحة طلبه (لا في الصفحات العامة). مفتاح تفعيل المزوّد اليدوي هنا يعمل مع PAYMENTS_MANUAL_ENABLED بالبيئة (كلاهما شرط).
     */
    'payments' => [
        'manual_enabled' => ['type' => 'boolean', 'default' => true],
        'manual_title' => ['type' => 'string', 'default' => 'تحويل بنكي'],
        'manual_beneficiary' => ['type' => 'string', 'default' => null],
        'manual_bank_name' => ['type' => 'string', 'default' => null],
        'manual_account' => ['type' => 'text', 'default' => null],
        'manual_reference_note' => ['type' => 'text', 'default' => 'اكتب رمز المرجع الظاهر أعلاه في خانة الملاحظات/الغرض من التحويل.'],
        'manual_extra_note' => ['type' => 'text', 'default' => null],
        'allowed_currencies' => ['type' => 'string', 'default' => null],
        'proof_required' => ['type' => 'boolean', 'default' => false],
    ],

];
`````

#### خريطة تعديلات `docs/environment-variables.md`

  - السطر 74: `| 'PAYMENTS_ENABLED' / 'PAYMENTS_DEFAULT_PROVIDER' / 'PAYMENTS_MANUAL_ENABLED' | 'true' / 'manual' / 'true' | دفع الرعاية (E32): مفتاح إيقاف شامل، الم`

### `docs/environment-variables.md`

`sha256: 4ba00bbf58457fff2f4c90efd1eb765758959a48fd00c7a603c166c7fc7af53d`

`````markdown
# متغيرات البيئة: التصنيف وعقد الإنتاج (E25)

> المرجع الوحيد لكل متغير بيئة يقرؤه التطبيق، مصنَّفًا إلى **REQUIRED / SECRET / OPTIONAL / INFRASTRUCTURE-SPECIFIC**.
> يفحص هذا العقد الأمرُ `php artisan production:check` (قراءة فقط، يعطي `PASS/WARN/FAIL` + **اسم** الإعداد ولا يطبع أي قيمة سرية أبدًا).
> القيم الحقيقية لا توجد بأي ملف بالمستودع: `.env.example` يحوي placeholders فقط.

## قواعد عامة

1. **لا أسرار في Git ولا في الوثائق ولا في CI.** `.env` ملف خارج المستودع (مُستبعَد بـ`.gitignore`). أسرار الإنتاج: مدير أسرار المزوّد، أو متغيرات بيئة محميّة على الخادم، أو ملف `.env` بصلاحيات `640` ومالك مستخدم التطبيق فقط.
2. **`config:cache` يجمّد القيم.** الإنتاج يشغّل `php artisan optimize`، وبعدها لا يقرأ التطبيق `.env` إطلاقًا: أي تغيير بالبيئة **لا يسري** قبل إعادة `php artisan optimize` ثم `php artisan queue:restart` (العمال طويلو العمر) و`php artisan reverb:restart`.
3. **متغيرات `VITE_*` عامة بالتصميم**: تُدمَج داخل حزمة جافاسكربت وقت `npm run build` ويراها كل زائر. لا يوضع فيها أي سر إطلاقًا (`VITE_REVERB_APP_KEY` هو معرّف عام لا سر).
4. **التدوير**: كل سر له خطوات تدوير بـ[التحصين الأمني](security-hardening.md#تدوير-الأسرار-p4).

## 1) REQUIRED: لا يعمل الإنتاج الصحيح بدونها

| المتغير | القيمة بالإنتاج | ملاحظة |
|---|---|---|
| `APP_ENV` | `production` | تفعّل HSTS وتشدّد `production:check` (FAIL بدل WARN) وتمنع الأوامر الهدّامة |
| `APP_DEBUG` | `false` | **FAIL حاجب دائمًا** إن كانت true (تكشف Stack Traces والإعدادات) |
| `APP_URL` | `https://<نطاقك>` | يُفحص: https، ليس مضيفًا محليًا |
| `APP_KEY` | يولَّد مرة واحدة (`php artisan key:generate --show`) | **SECRET**. انظر [تحذير APP_KEY](security-hardening.md#app_key-تحذير-خاص) |
| `DB_CONNECTION` | `mysql` (أو `pgsql`) | **sqlite مرفوض بالإنتاج** (FAIL) |
| `DB_HOST` `DB_PORT` `DB_DATABASE` `DB_USERNAME` | بنية تحتية | مستخدم بصلاحيات التطبيق فقط (ليس root) |
| `SESSION_DRIVER` | `database` أو `redis` | `array`/`null` مرفوضان؛ `file` لخادم واحد فقط |
| `SESSION_SECURE_COOKIE` | `true` | **FAIL** بالإنتاج إن لم تكن true |
| `CACHE_STORE` | `redis` أو `database` | `array`/`null` مرفوضان؛ `file` لخادم واحد فقط؛ يلزم دعم الأقفال (المجدول) |
| `QUEUE_CONNECTION` | `database` أو `redis` | `sync` تحذير، `null` مرفوض. يلزم عامل `queue:work` دائم |
| `MAIL_MAILER` + `MAIL_HOST` `MAIL_PORT` `MAIL_USERNAME` `MAIL_FROM_ADDRESS` | `smtp` وبيانات مزوّدك | `log`/`array` مرفوضان بالإنتاج (توثيق البريد/استرجاع كلمة السر شرط الاستبدال) |
| `BROADCAST_CONNECTION` | `reverb` | وإلا لا تحديث لحظي للدردشة (الحفظ يعمل) |
| `REVERB_APP_ID` `REVERB_APP_KEY` `REVERB_HOST` `REVERB_PORT` `REVERB_SCHEME` | `https` للمخطط | `REVERB_HOST` النطاق العام لخادم Reverb |
| `LOG_STACK` | `daily` | `single` بلا تدوير يملأ القرص |
| `LOG_LEVEL` | `info` أو `warning` | `debug` بالإنتاج ضجيج وخطر تسجيل زائد |

## 2) SECRET: لا تُطبع ولا تُسجَّل ولا تُخزَّن بالمستودع

| المتغير | أين يُستعمل | تدويره |
|---|---|---|
| `APP_KEY` | التشفير، الجلسات المشفّرة، الكوكيز، الروابط الموقَّعة | **ليس تدويرًا عاديًا**: [تحذير خاص](security-hardening.md#app_key-تحذير-خاص) |
| `APP_PREVIOUS_KEYS` | قراءة بيانات مشفرة بمفتاح سابق أثناء التدوير | يُزال بعد انتهاء الفترة |
| `DB_PASSWORD` | اتصال القاعدة | [تدوير كلمة مرور القاعدة](security-hardening.md#تدوير-الأسرار-p4) |
| `REDIS_PASSWORD` | Redis | بنفس الطريقة |
| `REVERB_APP_SECRET` | توقيع البثّ والمصادقة | بنفس الطريقة (+ إعادة بناء الأصول لا يلزم: السر لا يصل المتصفح) |
| `MAIL_PASSWORD` (أو `POSTMARK_TOKEN`/`RESEND_KEY`) | إرسال البريد | عند المزوّد ثم تحديث البيئة |
| `AWS_SECRET_ACCESS_KEY` | قرص S3 (إن استُعمل) | عند المزوّد |
| `SLACK_BOT_USER_OAUTH_TOKEN` / `LOG_SLACK_WEBHOOK_URL` | تنبيهات (إن استُعملت) | عند Slack |
| `MEMCACHED_PASSWORD` | Memcached (إن استُعمل) | عند المزوّد |
| `PERF_READINESS_TOKEN` | تقييد `/ready` برأس `X-Health-Token` | ولّد قيمة عشوائية جديدة وحدّث الموازن |
| `ADMIN_BOOTSTRAP_PASSWORD` | أول مدير فقط | **مؤقت**: يُحذف بعد أول تشغيل (انظر [أول نشر](first-production-deploy.md)) |
| `MAINTENANCE_SECRET` | سر دخول وضع الصيانة (يقرؤه `tools/deploy/deploy.sh` فقط) | قيمة جديدة لكل مرة، لا تُحفظ ولا تُسجَّل |
| `BACKUP_*` مفاتيح التشفير / `RESTORE_AGE_IDENTITY` | نسخ احتياطي واستعادة | مفتاح الاستعادة الخاص **لا يبقى على الخادم** |

> **معرّف عام لا سر**: `REVERB_APP_KEY` (يصل المتصفح عبر `VITE_REVERB_APP_KEY`)، `ADSENSE_CLIENT_ID`. لا تعاملهما كسر لكن لا تضعهما بالتذاكر العامة بلا داعٍ.

## 3) OPTIONAL: لها افتراضات آمنة، لا تضبطها إلا لسبب

| المتغير | الافتراضي | الغرض |
|---|---|---|
| `APP_RELEASE` / `RELEASE_SHA` | غير مضبوط | معرّف الإصدار (يظهر في `/ready` والسجلات ومركز العمليات). صيغة `[A-Za-z0-9._-]` حتى 64 خانة وإلا يُتجاهَل |
| `TRUSTED_PROXIES` | فارغ (لا وكلاء) | عناوين/CIDR الموازن. `*` فقط إن لم يُستقبَل الطلب إلا من الموازن |
| `REVERB_ALLOWED_ORIGINS` | `*` | أسماء مضيفين مسموحة لاتصالات WebSocket (بلا `http://`) |
| `PERF_*` | انظر [performance-e24](performance-e24.md) | تحسينات الأداء والمراقبة (E24) |
| `OPS_SCHEDULER_STALE_SECONDS` / `OPS_SCHEDULER_DOWN_SECONDS` | 300 / 900 | عتبات نبض المجدول |
| `OPS_READY_FAILS_ON_SCHEDULER` | `false` | `true`: `/ready` يفشل فقط عند حالة down (لا عند التأخر البسيط ولا بعد نشر جديد) |
| `OPS_QUEUE_BACKLOG_WARN_SECONDS` / `OPS_FAILED_JOBS_WARN` | 900 / 1 | عتبات تنبيه الطابور (مركز العمليات وproduction:check) |
| `OPS_ALLOW_DESTRUCTIVE_COMMANDS` | `false` | استثناء واعٍ **لعملية واحدة** من حظر `migrate:rollback/fresh` وغيرها بالإنتاج. لا يوضع بـ`.env` الدائم |
| `LOG_DAILY_DAYS` | 14 | أيام الاحتفاظ بقناة `daily` |
| `GEMS_*`, `PROGRESSION_*` | انظر `config/gems.php` | منطق المنتج: **لا تغيّرها ضمن عملية تشغيل** (قرار منتج) |
| `DB_QUEUE_RETRY_AFTER` / `REDIS_QUEUE_RETRY_AFTER` | 90 | يجب أن يبقى **أكبر من 80** (أقصى timeout لأي Job) وإلا تُنفَّذ المهمة مرتين |
| `SESSION_SAME_SITE` / `SESSION_HTTP_ONLY` / `SESSION_LIFETIME` | `lax` / `true` / 120 | الكوكي: لا تعطّل HttpOnly |
| `DB_ENGINE` / `DB_TIMEZONE` | `InnoDB` / `+00:00` | محرك جداول MySQL ومنطقة جلسة القاعدة (E31؛ يجب أن تطابق UTC) |
| `OPS_ALLOW_SQLITE_PRODUCTION` | `false` | `true`: يحوّل فشل `production:check` على SQLite إلى تحذير. **غير مستحسن**: MySQL 8.0.16+ هي القاعدة المعتمدة ([E31](mysql-production-e31.md)) |
| `MIGRATION_TARGET_CONNECTION` / `MIGRATION_SQLITE_DATABASE` / `MIGRATION_BATCH_SIZE` / `MIGRATION_MAX_BATCH_BYTES` | `mysql` / — / 1000 / 1000000 | أداة نقل SQLite→MySQL لمرة واحدة (محلية فقط، ترفض الإنتاج) |
| `MIGRATION_MYSQL_HOST` / `_PORT` / `_DATABASE` / `_USERNAME` / `_PASSWORD` | — | تجاوزات هدف النقل (كلمة المرور **SECRET** من البيئة فقط ولا تُطبع) |
| `PAYMENTS_ENABLED` / `PAYMENTS_DEFAULT_PROVIDER` / `PAYMENTS_MANUAL_ENABLED` | `true` / `manual` / `true` | دفع الرعاية (E32): مفتاح إيقاف شامل، المزوّد الافتراضي، وتفعيل التحويل اليدوي بالنشر ([E32](sponsorship-payments-e32.md)). لا أسرار: تفاصيل التحويل العلنية من إعدادات الإدارة |
| `PAYMENTS_ALLOWED_CURRENCIES` / `PAYMENTS_MIN_AMOUNT_MINOR` / `PAYMENTS_MAX_AMOUNT_MINOR` / `PAYMENTS_ALLOW_CUSTOM_AMOUNT` / `PAYMENTS_MAX_OPEN_REQUESTS` | `USD,EUR,TRY` / 100 / — / `true` / 5 | حدود الطلب (عملات تُقاطَع مع الرعاية، أدنى/أعلى مبلغ بالوحدة الصغرى، المبالغ المقترحة فقط، سقف الطلبات المفتوحة لكل مستخدم) |
| `PAYMENTS_REQUEST_EXPIRY_HOURS` | 48 | مهلة «بانتظار الدفع» قبل الانتهاء الآلي (`payments:expire-stale` كل 15 دقيقة). المرسَل/المحقَّق لا ينتهي أبدًا |
| `PAYMENTS_PROOF_DISK` / `PAYMENTS_PROOF_MAX_KB` | `local` / 5120 | إثبات التحويل: **قرص خاص إلزامي** (يُرفض `public`)، JPEG/PNG/WEBP/PDF فقط |
| `PAYMENTS_PROOF_RETENTION_DAYS` / `PAYMENTS_DISPUTE_WINDOW_DAYS` | 365 / 90 | تقليم ملفات الإثبات (`payments:prune-proofs --dry-run` أولًا) بعد الحالة النهائية وخارج نافذة النزاع؛ سجل الطلب لا يُحذف |
| `PAYMENTS_WEBHOOKS_ENABLED` | `false` | مسار Webhook المستقبلي مغلق افتراضيًا؛ لا مزوّد إلكتروني منفَّذ بعد (يتطلب مزوّدًا يتحقق من توقيعه) |
| `PAYMENTS_NOTIFICATIONS_ENABLED` | `true` | إشعارات صاحب الطلب (استلام/توضيح/رفض/استرداد) |

## 4) INFRASTRUCTURE-SPECIFIC: تعتمد على بنيتك/مزوّدك

| المتغيرات | تعتمد على |
|---|---|
| `DB_HOST` `DB_PORT` `DB_SOCKET` `DB_URL` `MYSQL_ATTR_SSL_CA` `DB_CHARSET` `DB_COLLATION` | خادم القاعدة وTLS |
| `REDIS_CLIENT` `REDIS_HOST` `REDIS_PORT` `REDIS_USERNAME` `REDIS_DB` `REDIS_CACHE_DB` `REDIS_URL` `REDIS_PREFIX` `REDIS_CLUSTER` | خادم Redis (انظر [دليل التشغيل](operations-runbook.md#redis)) |
| `FILESYSTEM_DISK` `AWS_*` | قرص الملفات (محلي أم S3-متوافق) |
| `TRUSTED_PROXIES` | الموازن/Cloudflare |
| `REVERB_SERVER_HOST` `REVERB_SERVER_PORT` `REVERB_SCALING_ENABLED` `REVERB_APP_MAX_CONNECTIONS` ... | طوبولوجيا خادم Reverb |
| `MAIL_SCHEME` `MAIL_EHLO_DOMAIN` `MAIL_SENDMAIL_PATH` | مزوّد البريد |
| `WKHTML_PDF_BINARY` | مسار wkhtmltopdf على الخادم (تصدير PDF) |
| `LOG_STDERR_FORMATTER` `LOG_SYSLOG_FACILITY` `PAPERTRAIL_*` | منصة السجلات المركزية (P2) |

## 5) متغيرات تشغيل السكربتات (ليست بيئة التطبيق)

لا تُوضع في `.env` التطبيق؛ تُمرَّر لحظة تشغيل السكربت: `BACKUP_DIR`، `BACKUP_AGE_RECIPIENT`/`BACKUP_GPG_RECIPIENT`، `BACKUP_UPLOAD_CMD`، `BACKUP_DB_DEFAULTS_FILE`، `RESTORE_DB_*`، `RESTORE_CONFIRM_DATABASE`، `DEPLOY_RELOAD_CMD`، `DEPLOY_SMOKE_URL`، `DEPLOY_BACKUP_CONFIRMED`. انظر [النسخ الاحتياطي](backup-disaster-recovery.md) و[هندسة الإصدارات](release-engineering.md).

## 6) الحد الأدنى النموذجي للإنتاج (placeholders فقط)

```dotenv
APP_NAME=Ahjiyat
APP_ENV=production
APP_KEY=                       # php artisan key:generate --show (مرة واحدة، يُحفظ بخزنة أسرار)
APP_DEBUG=false
APP_URL=https://ahjiyat.example.com
APP_RELEASE=                   # يُضبط عند كل نشر

LOG_STACK=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14

DB_CONNECTION=mysql
DB_HOST=
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=                   # SECRET

SESSION_DRIVER=database        # أو redis
SESSION_SECURE_COOKIE=true
CACHE_STORE=database           # أو redis
QUEUE_CONNECTION=database      # أو redis

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=                 # SECRET
MAIL_FROM_ADDRESS=no-reply@ahjiyat.example.com

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=             # SECRET (≥ 32 خانة عشوائية)
REVERB_HOST=ws.ahjiyat.example.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_ALLOWED_ORIGINS=ahjiyat.example.com
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

TRUSTED_PROXIES=               # CIDR الموازن إن وُجد
```

ثم: `php artisan production:check` (يجب Exit 0) قبل أي نشر.
`````

#### خريطة تعديلات `routes/console.php`

  - السطر 57: `// E32: إنهاء طلبات الدعم «بانتظار الدفع» المتأخرة (حالة فقط، لا حذف؛ المرسَل/المحقَّق لا ينتهي). غيابه لا يكسر شيئًا: تبقى الطلبات القديمة مفتوحة حتى`

### `routes/console.php`

`sha256: 52d85bb841058cceb900eff79d44187ce8b8d6a692d23c7e42cc5dde5d6d523d`

`````php
<?php

use App\Services\PendingGemReleaseService;
use App\Support\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

/*
 * E24: كل المهام المجدولة تمرّ من هنا بنفس القاعدتين:
 *  - withoutOverlapping($minutes): كان الافتراضي 24 ساعة، فانهيار عملية أثناء التشغيل (OOM/نشر/قتل) كان يجمّد مهمة ساعية يومًا كاملًا.
 *    القيم أدناه أطول بكثير من زمن التشغيل الطبيعي (أمان ضد التداخل) وأقصر من اليوم (تعافٍ تلقائي).
 *  - onOneServer(): عند تشغيل المجدول على أكثر من خادم (نشر أفقي) ينفّذ خادم واحد فقط كل تشغيل. يحتاج كاشًا مشتركًا (Redis/قاعدة بيانات)؛ على خادم واحد بلا أي أثر.
 *    يُعطَّل بـ PERF_SCHEDULER_ONE_SERVER=false إن لم يتوفّر كاش مشترك بعد.
 */
$guard = function (Event $event, int $lockMinutes): Event {
    $event->withoutOverlapping($lockMinutes);

    return config('performance.scheduler.one_server', true) ? $event->onOneServer() : $event;
};

// تحويل الجواهر المعلقة إلى متاحة يومياً بعد انتهاء فترة التعليق (config('gems.pending_hold_days'))
// المنطق في PendingGemReleaseService: استعلامات مجمّعة لكل دفعة، والتحرير الفعلي حصرًا عبر GemWalletService.
$guard(Schedule::call(fn () => app(PendingGemReleaseService::class)->run())->daily()->name('release-pending-gems'), 360);

// تذكيرات "ينتهي قريبًا" للمواسم والحملات المستقلة (قراءة فقط) - ساعيًا، وقبل تذكيرات العودة بنفس الساعة (ترتيب التسجيل)
// كي يسبق ينتهي-قريبًا تذكير المهام الأدنى أولوية. غيابه لا يكسر شيئًا: لا تصل التذكيرات فقط.
$guard(Schedule::command('notifications:dispatch-lifecycle-reminders')->hourly()->name('notifications-lifecycle-reminders'), 120);

// E15: تذكيرات العودة (تحذير السلسلة + مهام اليوم) - ساعيًا. غيابه لا يكسر شيئًا: لا تصل التذكيرات فقط.
$guard(Schedule::command('competitive:process-lifecycle')->hourly()->name('competitive-process-lifecycle'), 120);

// E19: دعوات الفرق، عدّادات الأعضاء، وترتيب الفرق المتأخر (بعد اعتماد المنافسات).
$guard(Schedule::command('teams:process-lifecycle')->hourly()->name('teams-process-lifecycle'), 120);

$guard(Schedule::command('notifications:dispatch-reengagement')->hourly()->name('notifications-reengagement'), 120);

// E15: تنظيف المقروء القديم يوميًا - صحة النظام لا تعتمد عليه.
$guard(Schedule::command('notifications:prune')->daily()->name('notifications-prune'), 360);

// التقاط بدء المواسم الناتج عن مرور الوقت. غيابه لا يكسر شيئًا: البدء بالحفظ الإداري يُلتقَط فورًا بخطافات النماذج.
$guard(Schedule::command('seasons:sync-live-state')->everyFiveMinutes()->name('seasons-sync-live-state'), 30);

// التقاط إتاحة الحملات الناتجة عن مرور الوقت. غيابه لا يكسر شيئًا: الإتاحة بالحفظ الإداري تُلتقَط فورًا بخطاف النموذج.
$guard(Schedule::command('campaigns:sync-availability')->everyFiveMinutes()->name('campaigns-sync-availability'), 30);

// E28: تنظيف صور أحجيات المبدعين اليتيمة أسبوعيًا (ملف بلا نسخة ولا أحجية يرتبط بها، وأقدم من مهلة الأمان). غيابه لا يكسر شيئًا: يبقى الملف اليتيم دون أثر على المنصة.
$guard(Schedule::command('creators:prune-media')->weekly()->name('creators-prune-media'), 120);

// E30: قفل خطط تعزيز مكافآت المنافسات القريبة من البدء (لقطة التمويل قبل البدء؛ المساهمات المتأخرة لا تغيّر جوائز المنافسة الجارية). غيابه = لا تعزيز لتلك المنافسات (فشل مغلق) لا أكثر.
$guard(Schedule::command('sponsorship:lock-event-plans')->everyFiveMinutes()->name('sponsorship-lock-event-plans'), 30);

// E25: نبض المجدول - يكتب الطابع الزمني بالكاش الافتراضي كل دقيقة؛ عمره = صحة المجدول (مركز العمليات، production:check، وعتبة /ready الاختيارية).
// يمرّ من $guard مثل بقية المهام (قاعدة E24: لا مهمة بلا قفل تداخل محدود ولا onOneServer). قفل 5 دقائق يكفي لعملية بطولها ميلي ثوانٍ وينتهي وحده بعد أي انهيار.
// يُسجَّل أخيرًا كي لا يغيّر ترتيب المهام القائمة.
$guard(Schedule::call(fn () => app(SchedulerHeartbeat::class)->beat())->everyMinute()->name('scheduler-heartbeat'), 5);

// E32: إنهاء طلبات الدعم «بانتظار الدفع» المتأخرة (حالة فقط، لا حذف؛ المرسَل/المحقَّق لا ينتهي). غيابه لا يكسر شيئًا: تبقى الطلبات القديمة مفتوحة حتى يُنفَّذ.
$guard(Schedule::command('payments:expire-stale')->everyFifteenMinutes()->name('payments-expire-stale'), 30);
`````

#### خريطة تعديلات `routes/web.php`

  - (لا وسم E32 نصيّ؛ انظر الملف)

### `routes/web.php`

`sha256: 410324f68417ee6efe1a889dd9984a0c518a3d0cd6b68be55e2cf5a9231603fd`

`````php
<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignStepController;
use App\Http\Controllers\ChallengeController;
use App\Http\Controllers\GameSessionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PlayerProfileController;
use App\Http\Controllers\ProfileCustomizationController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PuzzleController;
use App\Http\Controllers\RedemptionController;
use App\Http\Controllers\SeasonController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// E27: البحث العام والاكتشاف (قراءة فقط، عامة للضيوف). /search/suggest نقطة JSON للاقتراحات الفورية بمحدّد معدّل أضيق للضيف.
Route::get('/search', [\App\Http\Controllers\SearchController::class, 'index'])->middleware('throttle:global-search')->name('search.index');
Route::get('/search/suggest', [\App\Http\Controllers\SearchController::class, 'suggest'])->middleware('throttle:search-suggest')->name('search.suggest');
Route::get('/discover', [\App\Http\Controllers\DiscoverController::class, 'index'])->middleware('throttle:discover')->name('discover.index');

Route::get('/puzzles', [PuzzleController::class, 'index'])->name('puzzles.index');
Route::get('/puzzles/category/{category:slug}', [PuzzleController::class, 'index'])->name('puzzles.category');
Route::get('/puzzles/{puzzle}', [PuzzleController::class, 'show'])->name('puzzles.show');

Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard.index');

Route::get('/store', [StoreController::class, 'index'])->name('store.index');
Route::get('/store/items/{item:slug}', [StoreController::class, 'show'])->name('store.items.show');

Route::get('/challenges', [ChallengeController::class, 'index'])->name('challenges.index');

// E17: المنافسات العامة (قراءة فقط) - قائمة وصفحة حدث مع الترتيب
Route::get('/competitions', [\App\Http\Controllers\CompetitionController::class, 'index'])->name('competitions.index');
// E19: الفرق. الصفحات الحرفية (create/mine/invitations/leaderboard) تسبق {team:slug} حتى لا يلتقطها المسار الديناميكي (والمعرّفات محجوزة بـconfig/teams.php).
Route::get('/teams', [\App\Http\Controllers\TeamController::class, 'index'])->name('teams.index');
Route::get('/teams/leaderboard', [\App\Http\Controllers\TeamController::class, 'leaderboard'])->name('teams.leaderboard');
Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::get('/teams/create', [\App\Http\Controllers\TeamController::class, 'create'])->name('teams.create');
    Route::get('/teams/mine', [\App\Http\Controllers\TeamController::class, 'mine'])->name('teams.mine');
    Route::get('/teams/invitations', [\App\Http\Controllers\TeamMembershipController::class, 'invitations'])->name('teams.invitations');
});
// E20: تحدّيات الفرق (حرفية قبل {team:slug}) وبطولات الفرق. الصفحات الحرفية بين الفرق: challenges محجوز كمعرّف.
Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::get('/teams/challenges', [\App\Http\Controllers\TeamChallengeController::class, 'index'])->name('teams.challenges.index');
    Route::get('/teams/challenges/create', [\App\Http\Controllers\TeamChallengeController::class, 'create'])->name('teams.challenges.create');
});
Route::get('/teams/challenges/{challenge:public_id}', [\App\Http\Controllers\TeamChallengeController::class, 'show'])->name('teams.challenges.show');
Route::get('/team-championships', [\App\Http\Controllers\TeamChampionshipController::class, 'index'])->name('team-championships.index');
Route::get('/team-championships/{championship:slug}', [\App\Http\Controllers\TeamChampionshipController::class, 'show'])->name('team-championships.show');
// E21: الدردشة (مباشرة/فريق/عامة) + عمليات الرسائل. كلها لموثَّق غير مجمَّد؛ التعديلات POST/PATCH/DELETE فقط (لا GET يغيّر حالة). /teams/{team}/chat لا يتعارض مع /teams/{team}.
Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::get('/messages', [\App\Http\Controllers\Chat\DirectMessageController::class, 'index'])->name('messages.index');
    Route::get('/messages/{user:public_id}', [\App\Http\Controllers\Chat\DirectMessageController::class, 'show'])->name('messages.direct');
    Route::post('/messages/{user:public_id}', [\App\Http\Controllers\Chat\DirectMessageController::class, 'send'])->middleware('throttle:chat-dm-send')->name('messages.direct.send');
    Route::get('/teams/{team:slug}/chat', [\App\Http\Controllers\Chat\TeamChatController::class, 'show'])->name('teams.chat');
    Route::post('/teams/{team:slug}/chat', [\App\Http\Controllers\Chat\TeamChatController::class, 'send'])->middleware('throttle:chat-team-send')->name('teams.chat.send');
    Route::get('/community/chat', [\App\Http\Controllers\Chat\CommunityChatController::class, 'show'])->name('community.chat');
    Route::post('/community/chat', [\App\Http\Controllers\Chat\CommunityChatController::class, 'send'])->middleware('throttle:chat-global-send')->name('community.chat.send');

    Route::get('/chat/threads/{thread:public_id}/messages', [\App\Http\Controllers\Chat\ChatMessageController::class, 'older'])->name('chat.messages');
    Route::post('/chat/threads/{thread:public_id}/read', [\App\Http\Controllers\Chat\ChatMessageController::class, 'read'])->middleware('throttle:chat-read')->name('chat.read');
    Route::patch('/chat/messages/{message}', [\App\Http\Controllers\Chat\ChatMessageController::class, 'update'])->middleware('throttle:chat-edit')->name('chat.messages.update');
    Route::delete('/chat/messages/{message}', [\App\Http\Controllers\Chat\ChatMessageController::class, 'destroy'])->middleware('throttle:chat-edit')->name('chat.messages.destroy');
    Route::post('/chat/messages/{message}/report', [\App\Http\Controllers\Chat\ChatMessageController::class, 'report'])->middleware('throttle:chat-report')->name('chat.messages.report');
    Route::post('/chat/messages/{message}/hide', [\App\Http\Controllers\Chat\ChatMessageController::class, 'hide'])->middleware('throttle:chat-moderate')->name('chat.messages.hide');
    Route::post('/chat/messages/{message}/restore', [\App\Http\Controllers\Chat\ChatMessageController::class, 'restore'])->middleware('throttle:chat-moderate')->name('chat.messages.restore');
});
Route::get('/teams/{team:slug}', [\App\Http\Controllers\TeamController::class, 'show'])->name('teams.show');

// E18: قاعة الأمجاد (حرفية قبل {event:slug} حتى لا يلتقطها المسار الديناميكي)
Route::get('/competitions/hall-of-fame', [\App\Http\Controllers\CompetitionHallOfFameController::class, 'index'])->name('competitions.hall-of-fame');
Route::get('/competitions/{event:slug}', [\App\Http\Controllers\CompetitionController::class, 'show'])->name('competitions.show');
Route::get('/challenges/{challenge}', [ChallengeController::class, 'show'])->name('challenges.show');

Route::get('/terms', [PageController::class, 'terms'])->name('pages.terms');
Route::get('/privacy', [PageController::class, 'privacy'])->name('pages.privacy');

Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
Route::get('/campaigns/{campaign:slug}', [CampaignController::class, 'show'])->name('campaigns.show');

Route::get('/seasons', [SeasonController::class, 'index'])->name('seasons.index');
Route::get('/seasons/{season:slug}', [SeasonController::class, 'show'])->name('seasons.show');

Route::get('/players/{user:public_id}', [PlayerProfileController::class, 'show'])->name('players.show');
Route::get('/players/{user:public_id}/competitive', [\App\Http\Controllers\PlayerCompetitiveController::class, 'show'])->name('players.competitive');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:registration');

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:password-reset-submit')->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:email-verification'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:email-verification')->name('verification.send');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])
        ->middleware('throttle:account-sensitive')->name('profile.update');

    Route::get('/confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('/confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware('throttle:password-confirm');

    Route::middleware(['verified', 'account.active'])->group(function () {
        // E19: إجراءات الفرق (POST/PATCH/DELETE فقط عدا صفحة الإدارة). التفويض من الدور الفعلي بالقاعدة، لا من الطلب.
        // {user:public_id} ليس ابنًا للفريق بعلاقة (Team::users) فيُعطَّل الربط المحصور هناك والخدمة تتحقق من العضوية؛ أما {invitation}/{joinRequest} فمحصوران بعلاقتي الفريق: 404 تلقائي لمورد فريق آخر.
        Route::post('/teams/challenges', [\App\Http\Controllers\TeamChallengeController::class, 'store'])->middleware('throttle:team-challenge-create')->name('teams.challenges.store');
        Route::post('/teams/challenges/{challenge:public_id}/accept', [\App\Http\Controllers\TeamChallengeController::class, 'accept'])->middleware('throttle:team-challenge-actions')->name('teams.challenges.accept');
        Route::post('/teams/challenges/{challenge:public_id}/decline', [\App\Http\Controllers\TeamChallengeController::class, 'decline'])->middleware('throttle:team-challenge-actions')->name('teams.challenges.decline');
        Route::delete('/teams/challenges/{challenge:public_id}', [\App\Http\Controllers\TeamChallengeController::class, 'cancel'])->middleware('throttle:team-challenge-actions')->name('teams.challenges.cancel');
        Route::put('/teams/challenges/{challenge:public_id}/roster', [\App\Http\Controllers\TeamChallengeController::class, 'roster'])->middleware('throttle:team-challenge-actions')->name('teams.challenges.roster');
        Route::post('/teams/challenges/{challenge:public_id}/start', [\App\Http\Controllers\TeamChallengePlayController::class, 'start'])->middleware('throttle:competitive-play')->name('teams.challenges.start');
        Route::post('/teams/challenges/{challenge:public_id}/submit', [\App\Http\Controllers\TeamChallengePlayController::class, 'submit'])->middleware('throttle:competitive-play')->name('teams.challenges.submit');
        Route::post('/teams', [\App\Http\Controllers\TeamController::class, 'store'])->middleware('throttle:team-create')->name('teams.store');
        Route::post('/teams/invitations/{invitation:public_id}/accept', [\App\Http\Controllers\TeamMembershipController::class, 'accept'])->middleware('throttle:team-actions')->name('teams.invitations.accept');
        Route::post('/teams/invitations/{invitation:public_id}/decline', [\App\Http\Controllers\TeamMembershipController::class, 'decline'])->middleware('throttle:team-actions')->name('teams.invitations.decline');
        Route::post('/teams/{team:slug}/join', [\App\Http\Controllers\TeamMembershipController::class, 'join'])->middleware('throttle:team-join')->name('teams.join');
        Route::post('/teams/{team:slug}/requests', [\App\Http\Controllers\TeamMembershipController::class, 'requestJoin'])->middleware('throttle:team-join')->name('teams.requests.store');
        Route::delete('/teams/{team:slug}/requests', [\App\Http\Controllers\TeamMembershipController::class, 'cancelRequest'])->middleware('throttle:team-actions')->name('teams.requests.cancel');
        Route::delete('/teams/{team:slug}/membership', [\App\Http\Controllers\TeamMembershipController::class, 'leave'])->middleware('throttle:team-actions')->name('teams.leave');
        Route::get('/teams/{team:slug}/manage', [\App\Http\Controllers\TeamManagementController::class, 'manage'])->name('teams.manage');
        Route::patch('/teams/{team:slug}', [\App\Http\Controllers\TeamManagementController::class, 'update'])->middleware('throttle:team-actions')->name('teams.update');
        Route::post('/teams/{team:slug}/deactivate', [\App\Http\Controllers\TeamManagementController::class, 'deactivate'])->middleware('throttle:team-actions')->name('teams.deactivate');
        Route::post('/teams/{team:slug}/invitations/{user:public_id}', [\App\Http\Controllers\TeamManagementController::class, 'invite'])->middleware('throttle:team-invite')->withoutScopedBindings()->name('teams.invitations.store');
        Route::delete('/teams/{team:slug}/invitations/{invitation:public_id}', [\App\Http\Controllers\TeamManagementController::class, 'cancelInvitation'])->middleware('throttle:team-actions')->name('teams.invitations.cancel');
        Route::post('/teams/{team:slug}/requests/{joinRequest:public_id}/accept', [\App\Http\Controllers\TeamManagementController::class, 'acceptRequest'])->middleware('throttle:team-actions')->name('teams.requests.accept');
        Route::post('/teams/{team:slug}/requests/{joinRequest:public_id}/decline', [\App\Http\Controllers\TeamManagementController::class, 'declineRequest'])->middleware('throttle:team-actions')->name('teams.requests.decline');
        Route::delete('/teams/{team:slug}/members/{user:public_id}', [\App\Http\Controllers\TeamManagementController::class, 'removeMember'])->middleware('throttle:team-actions')->withoutScopedBindings()->name('teams.members.remove');
        Route::patch('/teams/{team:slug}/members/{user:public_id}/role', [\App\Http\Controllers\TeamManagementController::class, 'changeRole'])->middleware('throttle:team-actions')->withoutScopedBindings()->name('teams.members.role');
        Route::post('/teams/{team:slug}/transfer/{user:public_id}', [\App\Http\Controllers\TeamManagementController::class, 'transfer'])->middleware('throttle:team-actions')->withoutScopedBindings()->name('teams.transfer');

        Route::post('/puzzles/{puzzle}/attempt', [PuzzleController::class, 'attempt'])
            ->middleware('throttle:puzzle-attempt')->name('puzzles.attempt');
        Route::post('/puzzles/{puzzle}/hint', [PuzzleController::class, 'hint'])
            ->middleware('throttle:puzzle-hint')->name('puzzles.hint');

        Route::post('/puzzles/{puzzle}/session', [GameSessionController::class, 'store'])
            ->middleware('throttle:game-session-start')->name('game-sessions.start');
        Route::post('/game-sessions/{session}/reveal', [GameSessionController::class, 'reveal'])
            ->middleware('throttle:game-session-reveal')->name('game-sessions.reveal');

        Route::post('/challenges/{challenge}/join', [ChallengeController::class, 'join'])
            ->middleware('throttle:game-actions')->name('challenges.join');

        Route::get('/campaigns/{campaign:slug}/steps/{step}', [CampaignStepController::class, 'show'])
            ->name('campaigns.steps.show');
        Route::post('/campaigns/{campaign:slug}/steps/{step}/complete', [CampaignStepController::class, 'complete'])
            ->middleware('throttle:game-actions')->name('campaigns.steps.complete');
        Route::post('/campaigns/{campaign:slug}/steps/{step}/reflect', [CampaignStepController::class, 'reflect'])
            ->middleware('throttle:game-actions')->name('campaigns.steps.reflect');
        Route::post('/campaigns/{campaign:slug}/steps/{step}/attempt', [CampaignStepController::class, 'attempt'])
            ->middleware('throttle:puzzle-attempt')->name('campaigns.steps.attempt');
        Route::post('/campaigns/{campaign:slug}/steps/{step}/session', [CampaignStepController::class, 'startSession'])
            ->middleware('throttle:game-session-start')->name('campaigns.steps.session');

        Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');

        Route::get('/redemption', [RedemptionController::class, 'index'])->name('redemption.index');
        Route::get('/redemption/create', [RedemptionController::class, 'create'])->name('redemption.create');
        Route::post('/redemption', [RedemptionController::class, 'store'])
            ->middleware('throttle:redemption')->name('redemption.store');

        Route::post('/store/items/{item}/purchase', [StoreController::class, 'purchase'])
            ->middleware('throttle:store-purchase')->name('store.items.purchase');

        Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');

        Route::get('/profile/customize', [ProfileCustomizationController::class, 'edit'])->name('profile.customize');
        Route::post('/profile/cosmetics/{item}/equip', [ProfileCustomizationController::class, 'equip'])
            ->middleware('throttle:cosmetic-equip')->name('profile.cosmetics.equip');
        Route::delete('/profile/cosmetics/{slot}/unequip', [ProfileCustomizationController::class, 'unequip'])
            ->middleware('throttle:cosmetic-equip')->name('profile.cosmetics.unequip');
        Route::patch('/profile/visibility', [ProfileCustomizationController::class, 'updateVisibility'])
            ->middleware('throttle:account-sensitive')->name('profile.visibility.update');

        // E12: صفحة التقدُّم - قراءة فقط، لا Mutation عبر أي مسار هنا.
        Route::get('/progress', [\App\Http\Controllers\PlayerProgressionController::class, 'show'])->name('progress.show');
        Route::get('/quests', [\App\Http\Controllers\PlayerQuestsController::class, 'show'])->name('quests.show');

        // E15: مركز الإشعارات. GET = عرض فقط. كل تعديل POST/PUT/DELETE بـCSRF وبملكية صارمة داخل المتحكّم.
        Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/preferences', [\App\Http\Controllers\NotificationController::class, 'preferences'])->name('notifications.preferences');
        Route::put('/notifications/preferences', [\App\Http\Controllers\NotificationController::class, 'updatePreferences'])->name('notifications.preferences.update');
        Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('/notifications/{id}/open', [\App\Http\Controllers\NotificationController::class, 'open'])->whereUuid('id')->name('notifications.open');
        Route::post('/notifications/{id}/read', [\App\Http\Controllers\NotificationController::class, 'read'])->whereUuid('id')->name('notifications.read');
        Route::delete('/notifications/{id}', [\App\Http\Controllers\NotificationController::class, 'destroy'])->whereUuid('id')->name('notifications.destroy');

        // E16: الأصدقاء والحظر. GET = عرض فقط. كل تعديل POST/PATCH/DELETE بـCSRF؛ الطرف الآخر بـpublic_id من المسار، والطرف الحالي
        // دائمًا المستخدم المصادَق (لا user_id من النموذج). إرسال الطلبات بتحديد معدّل ثنائي.
        Route::prefix('friends')->name('friends.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FriendsController::class, 'index'])->name('index');
            Route::get('/search', [\App\Http\Controllers\PlayerSearchController::class, 'index'])->middleware('throttle:player-search')->name('search');
            Route::patch('/settings', [\App\Http\Controllers\FriendsController::class, 'updateSettings'])->middleware('throttle:friend-actions')->name('settings');

            Route::post('/requests/{user:public_id}', [\App\Http\Controllers\FriendRequestController::class, 'store'])->middleware('throttle:friend-requests')->name('requests.store');
            Route::post('/requests/{user:public_id}/accept', [\App\Http\Controllers\FriendRequestController::class, 'accept'])->middleware('throttle:friend-actions')->name('requests.accept');
            Route::post('/requests/{user:public_id}/decline', [\App\Http\Controllers\FriendRequestController::class, 'decline'])->middleware('throttle:friend-actions')->name('requests.decline');
            Route::delete('/requests/{user:public_id}', [\App\Http\Controllers\FriendRequestController::class, 'cancel'])->middleware('throttle:friend-actions')->name('requests.cancel');
            Route::delete('/blocks/{user:public_id}', [\App\Http\Controllers\FriendBlockController::class, 'destroy'])->middleware('throttle:friend-actions')->name('blocks.destroy');
            Route::post('/blocks/{user:public_id}', [\App\Http\Controllers\FriendBlockController::class, 'store'])->middleware('throttle:friend-actions')->name('blocks.store');
            Route::delete('/{user:public_id}', [\App\Http\Controllers\FriendRequestController::class, 'removeFriend'])->middleware('throttle:friend-actions')->name('remove');
        });

        // E17-A: تحدّيات الأصدقاء. الخصم بـpublic_id من المسار والتحدي بـpublic_id (ULID)، والمصادَق هو الطرف دائمًا. GET = عرض فقط.
        Route::prefix('friends/challenges')->name('friends.challenges.')->group(function () {
            $c = \App\Http\Controllers\FriendChallengeController::class;

            Route::get('/', [$c, 'index'])->name('index');
            Route::get('/new/{user:public_id}', [$c, 'create'])->name('create');
            Route::post('/new/{user:public_id}', [$c, 'store'])->middleware('throttle:friend-challenges')->name('store');
            Route::get('/{challenge:public_id}', [$c, 'show'])->name('show');
            Route::post('/{challenge:public_id}/accept', [$c, 'accept'])->middleware('throttle:friend-actions')->name('accept');
            Route::post('/{challenge:public_id}/decline', [$c, 'decline'])->middleware('throttle:friend-actions')->name('decline');
            Route::delete('/{challenge:public_id}', [$c, 'cancel'])->middleware('throttle:friend-actions')->name('cancel');
            Route::post('/{challenge:public_id}/start', [$c, 'start'])->middleware('throttle:competitive-play')->name('start');
            Route::post('/{challenge:public_id}/submit', [$c, 'submit'])->middleware('throttle:competitive-play')->name('submit');
        });

        // E17-B/C: تسجيل ولعب المنافسات. الحدث بـslug من المسار؛ لا score/winner/rank من العميل.
        Route::prefix('competitions/{event:slug}')->name('competitions.')->group(function () {
            $c = \App\Http\Controllers\CompetitionPlayController::class;

            Route::post('/register', [$c, 'register'])->middleware('throttle:competitive-register')->name('register');
            Route::delete('/register', [$c, 'leave'])->middleware('throttle:competitive-register')->name('leave');
            Route::post('/start', [$c, 'start'])->middleware('throttle:competitive-play')->name('start');
            Route::get('/play', [$c, 'play'])->name('play');
            Route::post('/submit', [$c, 'submit'])->middleware('throttle:competitive-play')->name('submit');
        });


    });





});
// E14 (بند 605): تحويل نقر إعلان - عام، معرِّف Creative فقط، لا رابط من الاستعلام.
Route::get('/ads/click/{creative}', \App\Http\Controllers\AdClickController::class)
    ->middleware('throttle:ad-click')->name('ads.click');

// E28: منظومة المبدعين وأحجيات المجتمع (ملف مسارات مستقل بالسياق web نفسه).
require __DIR__.'/creator.php';
require __DIR__.'/engagement.php';
// E30: الداعمون والرعاة (قراءة عامة) وسلاسل المبدعين.
require __DIR__.'/sponsorship.php';
require __DIR__.'/payments.php';
require __DIR__.'/series.php';
`````

---

## C) MIGRATIONS — 1 جديدة، محتوى كامل

ترحيل واحد **إضافي فقط** (ينشئ جدول `sponsorship_payments`؛ لا يلمس جدولًا قائمًا ولا بيانات). ترحيلات E30/E31 التاريخية لم تُعدَّل. اسمه الزمني `2026_10_24_000001` يأتي بعد آخر ترحيل E31 (`2026_10_23_000001`). `down()` يسقط الجدول فقط. الجدول لا يحمل أي بيانات بطاقة/سر/بريد؛ المبالغ أعداد صحيحة غير موقّعة؛ قيود UNIQUE النهائية: `sponsorship_contribution_id`، و`(provider, provider_reference)`، و`reference`، و`(user_id, request_key)`.

ثم `php artisan migrate`.

### `database/migrations/2026_10_24_000001_create_sponsorship_payments_table.php`

`sha256: c4b10013e056fe0d7c9e8e99e345e0de2f7ed1482770e1a629da6d95eda20674`

`````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E32 - طلبات الدفع/الدعم (checkout). كيان مستقل عن SponsorshipContribution: يتتبّع مسار الدفع فقط؛ المساهمة تبقى سجل الدعم المالي المؤكَّد الوحيد.
 *
 * حدود السلامة المالية في قاعدة البيانات (النهائية؛ المنطق يحاول أولًا لكن هذه هي الحدّ الأخير):
 *  - sponsorship_contribution_id UNIQUE      ⇒ طلب واحد = مساهمة مجسَّدة واحدة كحدّ أقصى (تحقق مزدوج/إعادة محاولة لا تُنتج ثانية).
 *  - (provider, provider_reference) UNIQUE   ⇒ مرجع المزوّد الخارجي فريد داخل المزوّد (استجابة/استدعاء مكرّر لا يُنشئ طلبًا ثانيًا). NULL متعدد مسموح (اليدوي بلا مرجع مزوّد).
 *  - reference UNIQUE                        ⇒ رمز التحويل البشري (SUP-XXXXXXXX) فريد.
 *  - (user_id, request_key) UNIQUE           ⇒ نقر مزدوج على «إنشاء الطلب» ينتج طلبًا واحدًا.
 *
 * لا حذف تتابعي للتاريخ المالي: كل مفاتيح البرنامج/الداعم/المساهمة/المستخدم RESTRICT (حذف المستخدم محجوب أيضًا بـFinancialHistoryGuard).
 * المبالغ أعداد صحيحة بالوحدة الصغرى؛ لا بريد ضيف ولا بيانات بطاقة ولا أسرار مزوّد ولا أجسام webhook خام في أي عمود (الضيف مؤجَّل P1: يحتاج عمودًا مشفّرًا).
 * أسماء الفهارس صريحة (حدّ MySQL: 64 حرفًا).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsorship_payments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('reference', 24)->unique();

            $table->foreignId('sponsorship_program_id')->constrained('sponsorship_programs')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('supporter_profile_id')->nullable()->constrained('supporter_profiles')->restrictOnDelete();
            $table->foreignId('sponsorship_contribution_id')->nullable()->constrained('sponsorship_contributions')->restrictOnDelete();

            $table->string('provider', 32);
            $table->string('provider_reference', 120)->nullable();
            $table->string('request_key', 64)->nullable();

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('accounting_amount_minor')->nullable(); // يُسجَّل عند التحقق
            $table->char('accounting_currency', 3)->nullable();

            $table->string('status', 24)->default('awaiting_payment');
            $table->boolean('is_anonymous')->default(false);
            $table->string('display_name', 80)->nullable();   // الاسم الذي اختاره الداعم للعرض العلني إن لم يكن مجهولًا (نص عادي)

            $table->string('payer_note', 255)->nullable();    // ملاحظة المحوِّل (اسم المحوِّل/تاريخ التحويل): يراها المالك والمراجع فقط
            $table->string('user_message', 255)->nullable();  // رسالة مرئية لصاحب الطلب (سبب رفض/طلب توضيح)
            $table->string('review_note', 500)->nullable();   // ملاحظة مراجع داخلية: لا تُعرض لصاحب الطلب ولا للعامة أبدًا
            $table->string('failure_code', 32)->nullable();   // رمز سبب الرفض من قائمة بيضاء

            // إثبات التحويل (خاص): المسار على قرص غير علني، اسم عشوائي يولّده الخادم، وبصمة لاكتشاف إعادة استخدام نفس الإثبات بين طلبات.
            $table->string('proof_path', 255)->nullable();
            $table->string('proof_mime', 40)->nullable();
            $table->unsignedInteger('proof_size')->nullable();
            $table->char('proof_sha256', 64)->nullable();
            $table->timestamp('proof_uploaded_at')->nullable();
            $table->timestamp('proof_purged_at')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('clarification_requested_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // حدود السلامة المالية (انظر أعلى الملف).
            $table->unique('sponsorship_contribution_id', 'sponsorship_payments_contribution_unique');
            $table->unique(['provider', 'provider_reference'], 'sponsorship_payments_provider_ref_unique');
            $table->unique(['user_id', 'request_key'], 'sponsorship_payments_request_key_unique');

            // طابور المراجعة (الحالة + الأقدم أولًا) / مجاميع البرنامج / سجل المستخدم / الانتهاء المجدول / كشف تكرار الإثبات / FK الداعم.
            $table->index(['status', 'created_at'], 'sponsorship_payments_status_created_index');
            $table->index(['sponsorship_program_id', 'status'], 'sponsorship_payments_program_status_index');
            $table->index(['user_id', 'created_at'], 'sponsorship_payments_user_created_index');
            $table->index(['status', 'expires_at'], 'sponsorship_payments_expiry_index');
            $table->index('proof_sha256', 'sponsorship_payments_proof_hash_index');
            $table->index('supporter_profile_id', 'sponsorship_payments_supporter_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorship_payments');
    }
};
`````

---

## D) ENV / CONFIG — الأسطر الدقيقة

**كل متغيرات E32 اختيارية ومعلَّقة بـ`#` وافتراضياتها آمنة.** لا تضع أي حساب بنكي أو سر في `.env.example`: تفاصيل التحويل العلنية (المستفيد/البنك/الحساب) تُدخَل من لوحة الإدارة **«الرعاية والدعم ← إعدادات الدفع»** وتُخزَّن في إعدادات المنصة، لا في الملفات. ملف `.env` الحقيقي عندك لا يُستبدل؛ أضف ما تريد تغييره فقط.

### D.1 أسطر `.env.example` (موجودة في الملف المعدَّل بالقسم B)

```dotenv
# E32 - دفع الرعاية والعمليات المالية (كلها اختيارية؛ الافتراضيات آمنة). لا أسرار ولا بيانات بنكية هنا: تفاصيل التحويل تُدار من لوحة الإدارة (إعدادات الدفع). انظر docs/sponsorship-payments-e32.md.
# PAYMENTS_ENABLED=true                      # مفتاح إيقاف شامل: false = لا طلبات دعم جديدة (المراجعة والاسترداد للقائم يبقيان)
# PAYMENTS_DEFAULT_PROVIDER=manual           # المزوّد الافتراضي (manual = تحويل يدوي + إثبات + تحقق مالي)
# PAYMENTS_MANUAL_ENABLED=true               # تفعيل التحويل اليدوي بالنشر (يُشترط معه مفتاح الإدارة payments.manual_enabled)
# PAYMENTS_ALLOWED_CURRENCIES=USD,EUR,TRY    # عملات الدفع المسموحة (تُقاطَع مع عملات الرعاية)
# PAYMENTS_MIN_AMOUNT_MINOR=100              # أدنى مبلغ بالوحدة الصغرى (100 = 1.00)
# PAYMENTS_MAX_AMOUNT_MINOR=                 # أعلى مبلغ للطلب الواحد (فارغ = سقف الرعاية SPONSORSHIP_MAX_AMOUNT_MINOR)
# PAYMENTS_ALLOW_CUSTOM_AMOUNT=true          # false = المبالغ المقترحة فقط
# PAYMENTS_MAX_OPEN_REQUESTS=5               # سقف الطلبات المفتوحة لكل مستخدم (يمنع إغراق طابور المراجعة)
# PAYMENTS_REQUEST_EXPIRY_HOURS=48           # مهلة طلب «بانتظار الدفع» قبل الانتهاء الآلي (المرسَل/المحقَّق لا ينتهي أبدًا)
# PAYMENTS_PROOF_DISK=local                  # قرص إثبات التحويل: يجب أن يكون خاصًا (يُرفض public)
# PAYMENTS_PROOF_MAX_KB=5120                 # أقصى حجم إثبات بالكيلوبايت (JPEG/PNG/WEBP/PDF فقط)
# PAYMENTS_PROOF_RETENTION_DAYS=365          # مدة الاحتفاظ بملف الإثبات بعد الحالة النهائية (التقليم بأمر صريح؛ السجل لا يُحذف)
# PAYMENTS_DISPUTE_WINDOW_DAYS=90            # نافذة النزاع: لا يُقلَّم إثبات مرفوض/مسترد قبل انقضائها
# PAYMENTS_WEBHOOKS_ENABLED=false            # مسار Webhook المستقبلي للمزوّدين الإلكترونيين: مغلق افتراضيًا (لا مزوّد إلكتروني منفَّذ الآن)
# PAYMENTS_NOTIFICATIONS_ENABLED=true        # إشعارات صاحب الطلب (استلام/طلب توضيح/رفض/استرداد)
```

### D.2 `config/platform.php` — مجموعة إعدادات المنصة `payments` (جديدة؛ تُحرَّر من لوحة الإدارة)

```php
    'payments' => [
        'manual_enabled' => ['type' => 'boolean', 'default' => true],
        'manual_title' => ['type' => 'string', 'default' => 'تحويل بنكي'],
        'manual_beneficiary' => ['type' => 'string', 'default' => null],
        'manual_bank_name' => ['type' => 'string', 'default' => null],
        'manual_account' => ['type' => 'text', 'default' => null],
        'manual_reference_note' => ['type' => 'text', 'default' => 'اكتب رمز المرجع الظاهر أعلاه في خانة الملاحظات/الغرض من التحويل.'],
        'manual_extra_note' => ['type' => 'text', 'default' => null],
        'allowed_currencies' => ['type' => 'string', 'default' => null],
        'proof_required' => ['type' => 'boolean', 'default' => false],
    ],
```

### D.3 ملفات ضبط أخرى

* `config/payments.php` — **ملف جديد** (القسم A): المزوّدون (`manual` فقط)، العملات، حدود المبلغ، مهلة الطلب (48 ساعة)، إثبات التحويل (قرص خاص، JPEG/PNG/WEBP/PDF، 5120KB)، الاحتفاظ، Webhook (**مغلق افتراضيًا**)، المطابقة.
* `config/db_transfer.php`: إضافة `sponsorship_payments` إلى مجاميع المال المتحقَّق منها عند نقل SQLite→MySQL (`amount_minor` و`accounting_amount_minor`).
* `app/Services/Database/FinancialHistoryGuard.php`: إضافة `sponsorship_payments` إلى جداول السجل المالي المحمية من الحذف المتسلسل.
* `routes/web.php`: سطر `require __DIR__.'/payments.php';`. `bootstrap/app.php`: تسجيل `routes/payments_webhooks.php` ضمن مجموعة `api` عبر `then:` (**بلا أي استثناء CSRF**) — المسار `POST /api/payments/webhooks/{provider}` مغلق بـ404 ما لم يُضبط `PAYMENTS_WEBHOOKS_ENABLED=true` ويوجد مزوّد إلكتروني مفعّل يدعمه (لا يوجد الآن).
* `routes/console.php`: `payments:expire-stale` كل 15 دقيقة (المجدول `schedule:run` يجب أن يعمل).
* `app/Providers/AppServiceProvider.php`: سجل المزوّدين (singleton) + 6 محدِّدات معدل: `support-read` 120/د، `support-checkout` 10/د و40/س، `support-submit` 10/د و60/س، `support-proof-upload` 5/د و20/س، `payment-status` 60/د، `payment-webhook` 120/د لكل IP.
* **لا صلاحيات جديدة**: تُعاد استعمال `sponsorships.view/manage/confirm` من E30 (التحقق والرفض والتوضيح والإلغاء والاسترداد وفتح الإثبات = `sponsorships.confirm`). لا حاجة لإعادة تشغيل `RolesAndPermissionsSeeder`.

---

## E) LOCAL COMMANDS (PowerShell)

> مسارات صريحة فقط. **لا `php artisan test` بلا مسار ولا المجموعة الكاملة.** لا أوامر git.

### E.1 نسخ الملفات والتحقق من البصمات

```powershell
cd C:\Users\engya\Desktop\puzzles\puzzle-gems-platform

$pack = "$env:TEMP\e32"
Expand-Archive "<مسار>\e32-files.zip" -DestinationPath $pack -Force
Copy-Item "$pack\new-files\*" . -Recurse -Force
Copy-Item "$pack\modified-full-files\*" . -Recurse -Force

Get-Content "$pack\manifest-sha256.txt" | ForEach-Object {
    $h, $p = $_ -split '\s+', 2
    $a = (Get-FileHash -Algorithm SHA256 -LiteralPath $p).Hash.ToLower()
    if ($a -eq $h) { "OK   $p" } else { "DIFF $p" }
}
```

كل السطور يجب أن تكون `OK`.

### E.2 الترحيل وتنظيف المخابئ

```powershell
php artisan migrate --pretend      # اختياري: يعرض SQL جدول sponsorship_payments فقط (لا يغيّر شيئًا)
php artisan migrate                # ينشئ جدول sponsorship_payments فقط (إضافي؛ لا يمسّ بياناتك)
php artisan optimize:clear
```

### E.3 اختبارات SQLite (سريعة؛ مسارات صريحة)

```powershell
php vendor/bin/pest tests/Feature/PaymentsE32
# المتوقع هنا: 148 ناجحًا + 3 متخطّاة (اختبارات MySQL-only E32-MY1..MY3 تتخطى على SQLite)

php vendor/bin/pest tests/Feature/SponsorshipE30
php vendor/bin/pest tests/Feature/SecurityE26/E26RequestSecurityTest.php tests/Feature/SecurityE26/E26StaticAuditTest.php tests/Feature/SecurityE26/E26DocsTest.php tests/Feature/SecurityE26/E26FilesTest.php
php vendor/bin/pest tests/Feature/OperationsE25/DocumentationIntegrityTest.php tests/Feature/OperationsE25/SecretHygieneTest.php
php vendor/bin/pest tests/Feature/DatabaseE31/E31StaticSchemaTest.php tests/Feature/DatabaseE31/E31ParityAndGuardsTest.php tests/Feature/DatabaseE31/E31DomainIntegrityTest.php tests/Feature/DatabaseE31/E31TransferTest.php tests/Feature/DatabaseE31/E31DocsAndToolingTest.php
php vendor/bin/pest tests/Feature/Notifications
```

### E.4 أوامر التشغيل والفحص (قراءة فقط ما لم يُذكر)

```powershell
php artisan db:parity --static                      # قراءة فقط؛ المتوقع PASS: 119 ترحيلًا → 717 جملة، 0 أخطاء ترجمة
php artisan route:list --path=support               # مسارات الدعم والدفع
php artisan route:list --path=payments              # مسار المراجع المالي + webhook (api/payments/webhooks/{provider})
php artisan schedule:list                           # يجب أن يظهر payments-expire-stale كل 15 دقيقة

php artisan sponsorship:reconcile-payments --dry-run   # مطابقة مالية (قراءة فقط دائمًا)؛ المتوقع mismatches=0
php artisan sponsorship:reconcile-payments --json
php artisan payments:expire-stale                      # ينهي طلبات «بانتظار الدفع» المتأخرة (حالة فقط)
php artisan payments:prune-proofs --dry-run            # يعدّ مرشّحي تقليم الإثبات فقط
```

### E.5 تجربة يدوية محلية (بيانات تجريبية — محلي فقط؛ يرفض الإنتاج)

```powershell
php artisan db:seed --class=DemoE32Seeder
# ينشئ: مدير مالي demo-payments-admin@sponsorship.test + برنامج «دعم أحجيات (دفع تجريبي)» + 6 مستخدمين تجريبيين
# (بانتظار/مرسَل/محقَّق/مرفوض/مجهول/مسترد) = 6 طلبات و3 مساهمات. كلمة مرور الحسابات التجريبية: password (محلية فقط).

php artisan serve          # في نافذة منفصلة؛ لا تتركه يعمل في هذا الطرفية
# الواجهة العامة:  http://127.0.0.1:8000/support
# الإدارة:         http://127.0.0.1:8000/admin  ← «الرعاية والدعم ← طلبات الدعم والدفع» و«إعدادات الدفع»
```

مسار الاختبار اليدوي المقترح: (1) `/support` ← برنامج ← «ادعم هذا البرنامج» ← مبلغ/عملة/ظهور علني أو مجهول ← احتفظ برمز `SUP-…`. (2) نفّذ التحويل (وهميًا) ثم «أرسلتُ التحويل» وارفع إثباتًا (PNG/JPEG/WEBP/PDF). (3) كمراجع مالي: افتح الطلب في طابور الإدارة ← «الإثبات» ← «تحقق». (4) تأكد أن المساهمة صارت مؤكَّدة وأن المستوى/الصدارة/التمويل تغيّرت **بعد** التحقق فقط، وأن الطلب المرسَل قبله لم يغيّرها. (5) جرّب الرفض والتوضيح والاسترداد.

---

## F) MYSQL VALIDATION COMMANDS — **USER-LOCAL MYSQL VALIDATION REQUIRED**

> لم أُشغّل MySQL هنا ولا أدّعي نجاح أي بند منها. هذه الأوامر تثبّت قيود UNIQUE النهائية والتزامن الحقيقي (`lockForUpdate`) والفهارس على MySQL 8 الفعلية. للإعداد المحلي (Docker MySQL 8 + القاعدتان + المستخدم) اتبع **القسم E من حزمة E31** (`E31-delivery-pack-A-G.md`)؛ ملخّصه أدناه.

### F.1 تجهيز الجلسة (يفترض MySQL 8 يعمل وقاعدة اختبار تنتهي بـ `_test_mysql`)

```powershell
$appPwd = Read-Host 'كلمة مرور ahjiyat_app'     # لا تكتبها في ملف ولا ترسلها لي
php -m | Select-String pdo_mysql                  # يجب أن يظهر pdo_mysql

$env:DB_CONNECTION='mysql'; $env:DB_HOST='127.0.0.1'; $env:DB_PORT='3306'
$env:DB_DATABASE='ahjiyat_platform'; $env:DB_USERNAME='ahjiyat_app'; $env:DB_PASSWORD=$appPwd; $env:DB_TIMEZONE='+00:00'
```

### F.2 E31 أولًا (بوابة معلّقة: خطوة «E31 on MySQL» فشلت في CI)

```powershell
php vendor/bin/pest --configuration=phpunit.mysql.xml.dist tests/Feature/DatabaseE31 tests/Unit/TestDatabaseGuardTest.php
# أرسل لي أول الإخفاقات (اسم الاختبار + رسالة الخطأ) إن وُجدت. لا ترسل كلمات مرور.
```

### F.3 بناء المخطط على قاعدة MySQL **فارغة** ثم المقارنة

```powershell
php artisan migrate --force
php artisan db:parity --connection=mysql --runtime     # قراءة فقط؛ المتوقع: النتيجة PASS (خروج 0)
```

### F.4 اختبارات E32 على MySQL (هنا تعمل E32-MY1..MY3 ولا تتخطى)

```powershell
php vendor/bin/pest --configuration=phpunit.mysql.xml.dist tests/Feature/PaymentsE32
php vendor/bin/pest --configuration=phpunit.mysql.xml.dist tests/Feature/SponsorshipE30
php vendor/bin/pest --configuration=phpunit.mysql.xml.dist tests/Feature/Economy tests/Feature/GemWalletServiceTest.php
```

المتوقع: كل شيء ناجح، و**لا اختبار MySQL-only متخطٍّ** في `PaymentsE32` (E32-MY1: المخطط InnoDB/utf8mb4 مع مفاتيح أجنبية RESTRICT فلا يُحذف مستخدم/برنامج له طلبات؛ E32-MY2: جلستان حقيقيتان تتسابقان على مفاتيح UNIQUE للدفع فتنتظر الثانية (1205) ثم تحصل على 1062 ولا صفّان أبدًا؛ E32-MY3: النص العربي والإيموجي يعودان بايتًا ببايت وأعمدة المال أعداد صحيحة). ملاحظة صريحة: تسابق «تحقق» نفسه بين جلستين حقيقيتين على MySQL غير مغطّى باختبار آلي (مغطّى على SQLite بالتتابع وبقيود UNIQUE عبر MY2)؛ جرّبه يدويًا (F.6).

### F.5 فحص البنية على القاعدة المبنية (قراءة فقط)

```powershell
$env:MYSQL_PWD = $appPwd
mysql --default-character-set=utf8mb4 -u ahjiyat_app ahjiyat_platform -e "SHOW CREATE TABLE sponsorship_payments\G"
mysql --default-character-set=utf8mb4 -u ahjiyat_app ahjiyat_platform -e "SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='ahjiyat_platform' AND TABLE_NAME='sponsorship_payments' GROUP BY INDEX_NAME, NON_UNIQUE;"
mysql --default-character-set=utf8mb4 -u ahjiyat_app ahjiyat_platform -e "EXPLAIN SELECT id FROM sponsorship_payments WHERE status='submitted' ORDER BY id DESC LIMIT 20;"
Remove-Item Env:\MYSQL_PWD
```

المتوقع: وجود فهارس UNIQUE على `public_id` و`reference` و`sponsorship_contribution_id` و`(provider, provider_reference)` و`(user_id, request_key)`، ومحرك InnoDB وترميز utf8mb4. استعلام الطابور يجب أن يستعمل فهرس `sponsorship_payments_status_created_index` (قد يظهر `Using filesort` لعدد صغير من صفوف الطابور وهذا مقبول)؛ المرفوض فقط `type=ALL` على جدول كبير.

### F.6 التطبيق نفسه على MySQL (اختياري)

```powershell
php artisan sponsorship:reconcile-payments --json      # المتوقع mismatches=0
php artisan db:seed --class=DemoE32Seeder              # محلي فقط
# ثم جرّب المسار اليدوي في E.5 وانظر أن الضغط المزدوج على «تحقق» لا يولّد مساهمتين.
```

### F.7 ما أريد استلامه منك لإغلاق E32

1. مخرجات F.2 (E31 على MySQL) — أول الإخفاقات إن وُجدت.
2. مخرجات F.3 و F.4 (PASS/FAIL وملخّص الاختبارات).
3. مخرجات F.5 (`SHOW CREATE TABLE` والفهارس وEXPLAIN).
(لا تُرسل كلمات مرور ولا قيم صفوف.)

---
**انتهى E32 — STOP. لم تبدأ E33.**
