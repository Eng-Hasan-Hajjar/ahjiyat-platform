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
