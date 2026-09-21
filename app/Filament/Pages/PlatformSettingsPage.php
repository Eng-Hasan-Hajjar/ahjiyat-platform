<?php

namespace App\Filament\Pages;

use App\Services\PlatformSettingsService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * صفحة واحدة لكل إعدادات المنصة (لا 20 Resource منفصلة) - Tabs لكل فئة.
 * القراءة/الكتابة حصرًا عبر PlatformSettingsService - هذه الصفحة لا تلمس
 * PlatformSetting مباشرة إطلاقاً. حفظ واحد يحفظ كل الفئات دفعة واحدة عبر
 * setMany() لكل Group.
 */
class PlatformSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'الإعدادات';

    protected static ?string $title = 'إعدادات المنصة';

    protected static ?string $slug = 'settings';

    protected static string $view = 'filament.pages.platform-settings-page';

    public array $data = [];

    // E5: كانت canAccess() تُعيد true دائماً (Bug تاريخي من E4 أُصلح
    // بتصريح صريح وقتها) - الآن الفحص الفعلي: صلاحية settings.view.
    // Super Admin يتجاوز هذا تلقائياً عبر Gate::before قبل وصوله هون أصلاً.
    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.view') ?? false;
    }

    public function mount(): void
    {
        // نفس الثغرة الكامنة المكتشَفة أثناء اختبارات E6: canAccess() وحدها
        // تتحكم فقط بظهور العنصر بالقائمة الجانبية - لا تمنع فتح الرابط
        // مباشرة لمن يملك admin.access لكن ليس settings.view تحديداً (كان
        // بإمكانه معاينة كل الإعدادات، رغم أن save()/resetAppearance() محميتان
        // أصلاً). abort_unless صريح هنا يضمن حجباً حقيقياً على مستوى HTTP.
        abort_unless(static::canAccess(), 403);

        $settings = app(PlatformSettingsService::class);

        $this->form->fill([
            'general' => $settings->getGroup('general'),
            'branding' => $settings->getGroup('branding'),
            'appearance' => $settings->getGroup('appearance'),
            'home' => $settings->getGroup('home'),
            'navigation' => $settings->getGroup('navigation'),
            'contact' => $settings->getGroup('contact'),
            'seo' => $settings->getGroup('seo'),
            'access' => $settings->getGroup('access'),
            'announcement' => $settings->getGroup('announcement'),
            'footer' => $settings->getGroup('footer'),
        ]);
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Tabs::make('settings')
                    ->columnSpanFull()
                    ->tabs([

                        Forms\Components\Tabs\Tab::make('الإعدادات العامة')
                            ->schema([
                                Forms\Components\TextInput::make('general.site_name')->label('اسم المنصة')->required(),
                                Forms\Components\TextInput::make('general.short_name')->label('الاسم المختصر'),
                                Forms\Components\TextInput::make('general.tagline')->label('الجملة التعريفية (Tagline)')->columnSpanFull(),
                                Forms\Components\Textarea::make('general.short_description')->label('وصف قصير عام')->columnSpanFull(),
                                Forms\Components\TextInput::make('general.public_email')->label('البريد العام')->email(),
                                Forms\Components\TextInput::make('general.support_email')->label('بريد الدعم')->email(),
                                Forms\Components\TextInput::make('general.contact_phone')->label('رقم التواصل'),
                                Forms\Components\Select::make('general.display_timezone')
                                    ->label('المنطقة الزمنية المعروضة')
                                    ->options(['Asia/Damascus' => 'دمشق', 'Asia/Riyadh' => 'الرياض', 'Asia/Dubai' => 'دبي', 'Europe/Istanbul' => 'إسطنبول'])
                                    ->native(false)
                                    ->helperText('للعرض فقط - لا تغيّر منطقة السيرفر الزمنية الفعلية.'),
                            ])->columns(2),

                        Forms\Components\Tabs\Tab::make('الهوية البصرية')
                            ->schema([
                                Forms\Components\FileUpload::make('branding.logo_main')
                                    ->label('الشعار الرئيسي')->image()->disk('public')->directory('settings/branding')
                                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])->maxSize(2048),
                                Forms\Components\FileUpload::make('branding.logo_square')
                                    ->label('شعار مربَّع (App Icon)')->image()->disk('public')->directory('settings/branding')
                                    ->acceptedFileTypes(['image/png', 'image/webp'])->maxSize(1024),
                                Forms\Components\FileUpload::make('branding.logo_light')
                                    ->label('شعار للثيم الفاتح (اختياري)')->image()->disk('public')->directory('settings/branding')
                                    ->acceptedFileTypes(['image/png', 'image/webp'])->maxSize(1024),
                                Forms\Components\FileUpload::make('branding.logo_dark')
                                    ->label('شعار للثيم الداكن (اختياري)')->image()->disk('public')->directory('settings/branding')
                                    ->acceptedFileTypes(['image/png', 'image/webp'])->maxSize(1024),
                                Forms\Components\FileUpload::make('branding.favicon')
                                    ->label('أيقونة المتصفح (Favicon)')->image()->disk('public')->directory('settings/branding')
                                    ->acceptedFileTypes(['image/png', 'image/x-icon', 'image/vnd.microsoft.icon'])->maxSize(512),
                                Forms\Components\FileUpload::make('branding.social_share_image')
                                    ->label('صورة المشاركة الافتراضية (OG Image)')->image()->disk('public')->directory('settings/branding')
                                    ->acceptedFileTypes(['image/png', 'image/jpeg'])->maxSize(2048),
                            ])->columns(2),

                        Forms\Components\Tabs\Tab::make('المظهر والثيم')
                            ->schema([
                                Forms\Components\Select::make('appearance.default_theme')
                                    ->label('الثيم الافتراضي')
                                    ->options(['dark' => 'داكن', 'light' => 'فاتح', 'system' => 'حسب نظام الجهاز'])
                                    ->native(false)->required(),
                                Forms\Components\Toggle::make('appearance.allow_theme_switch')
                                    ->label('السماح للمستخدم بتغيير الثيم')
                                    ->helperText('يظهر زر تبديل الثيم بالقائمة العلوية إن كان مفعَّلًا.'),

                                Forms\Components\Select::make('appearance.color_preset')
                                    ->label('نمط لوني جاهز')
                                    ->options([
                                        'amethyst' => 'Amethyst (الافتراضي)', 'midnight' => 'Midnight',
                                        'royal_blue' => 'Royal Blue', 'emerald' => 'Emerald', 'obsidian_gold' => 'Obsidian Gold',
                                    ])
                                    ->native(false)->live()
                                    ->afterStateUpdated(function (Get $get, Forms\Set $set, ?string $state) {
                                        $presets = [
                                            'amethyst' => ['#8b5cf6', '#22d3ee', '#fcd34d'],
                                            'midnight' => ['#6366f1', '#38bdf8', '#e2e8f0'],
                                            'royal_blue' => ['#2563eb', '#06b6d4', '#facc15'],
                                            'emerald' => ['#10b981', '#0ea5e9', '#fbbf24'],
                                            'obsidian_gold' => ['#a16207', '#78716c', '#fde047'],
                                        ];
                                        if ($state && isset($presets[$state])) {
                                            [$primary, $secondary, $accent] = $presets[$state];
                                            $set('appearance.color_primary', $primary);
                                            $set('appearance.color_secondary', $secondary);
                                            $set('appearance.color_accent', $accent);
                                        }
                                    })
                                    ->helperText('اختيار نمط جاهز يملأ الألوان أدناه تلقائيًا - يمكنك تعديلها يدويًا بعده.'),

                                Forms\Components\ColorPicker::make('appearance.color_primary')->label('اللون الأساسي (Primary)')->live(),
                                Forms\Components\ColorPicker::make('appearance.color_secondary')->label('اللون الثانوي (Secondary)')->live(),
                                Forms\Components\ColorPicker::make('appearance.color_accent')->label('لون التمييز (Accent)')->live(),
                                Forms\Components\ColorPicker::make('appearance.color_success')->label('لون النجاح'),
                                Forms\Components\ColorPicker::make('appearance.color_warning')->label('لون التنبيه'),
                                Forms\Components\ColorPicker::make('appearance.color_danger')->label('لون الخطر'),

                                Forms\Components\Select::make('appearance.font_family')
                                    ->label('الخط')
                                    ->options(['cairo' => 'Cairo', 'tajawal' => 'Tajawal', 'noto_kufi' => 'Noto Kufi Arabic'])
                                    ->native(false),
                                Forms\Components\Select::make('appearance.base_font_size')
                                    ->label('حجم الخط الأساسي')
                                    ->options(['small' => 'صغير', 'normal' => 'عادي', 'large' => 'كبير'])
                                    ->native(false),
                                Forms\Components\Select::make('appearance.ui_radius')
                                    ->label('استدارة الحواف')
                                    ->options(['compact' => 'مضغوط', 'rounded' => 'مستدير (افتراضي)', 'soft' => 'ناعم جدًا'])
                                    ->native(false),

                                Forms\Components\Placeholder::make('appearance_preview')
                                    ->label('معاينة حية')
                                    ->content(fn (Get $get) => view('filament.pages.partials.appearance-preview', [
                                        'primary' => $get('appearance.color_primary') ?: '#8b5cf6',
                                        'secondary' => $get('appearance.color_secondary') ?: '#22d3ee',
                                        'accent' => $get('appearance.color_accent') ?: '#fcd34d',
                                    ]))
                                    ->columnSpanFull(),

                                Forms\Components\Actions::make([
                                    Forms\Components\Actions\Action::make('resetAppearance')
                                        ->label('إعادة إعدادات المظهر للوضع الافتراضي')
                                        ->color('danger')
                                        ->requiresConfirmation()
                                        ->modalDescription('سيُعاد ضبط الثيم والألوان والخط فقط للقيم الافتراضية - بقية الإعدادات لن تتأثر.')
                                        ->action('resetAppearance'),
                                ])->columnSpanFull(),
                            ])->columns(2),

                        Forms\Components\Tabs\Tab::make('الرئيسية والتنقل')
                            ->schema([
                                Forms\Components\TextInput::make('home.hero_title')->label('عنوان الـHero الرئيسي (اختياري)')->columnSpanFull(),
                                Forms\Components\TextInput::make('home.hero_subtitle')->label('العنوان الفرعي (اختياري)')->columnSpanFull(),
                                Forms\Components\TextInput::make('home.cta_text')->label('نص الزر الرئيسي'),
                                Forms\Components\Toggle::make('home.show_featured_season')->label('إظهار الموسم الرسمي المُبرَز'),
                                Forms\Components\Toggle::make('home.show_categories')->label('إظهار التصنيفات'),
                                Forms\Components\Toggle::make('home.show_leaderboard')->label('إظهار رابط لوحة الصدارة بالرئيسية'),
                                Forms\Components\Toggle::make('home.show_challenges')->label('إظهار رابط التحديات بالرئيسية'),

                                Forms\Components\Toggle::make('navigation.show_seasons_link')->label('إظهار رابط المواسم بالقائمة'),
                                Forms\Components\Toggle::make('navigation.show_puzzles_link')->label('إظهار رابط الأحجيات بالقائمة'),
                                Forms\Components\Toggle::make('navigation.show_leaderboard_link')->label('إظهار رابط لوحة الصدارة بالقائمة'),
                                Forms\Components\Toggle::make('navigation.show_challenges_link')->label('إظهار رابط التحديات بالقائمة'),
                                Forms\Components\Toggle::make('navigation.show_gem_balance')->label('إظهار رصيد الجواهر بالقائمة'),
                            ])->columns(2),

                        Forms\Components\Tabs\Tab::make('التواصل والشبكات')
                            ->schema([
                                Forms\Components\TextInput::make('contact.whatsapp')->label('واتساب')->url()->prefixIcon('heroicon-o-phone'),
                                Forms\Components\TextInput::make('contact.instagram')->label('انستغرام')->url(),
                                Forms\Components\TextInput::make('contact.facebook')->label('فيسبوك')->url(),
                                Forms\Components\TextInput::make('contact.x_twitter')->label('X (تويتر)')->url(),
                                Forms\Components\TextInput::make('contact.telegram')->label('تيليجرام')->url(),
                                Forms\Components\TextInput::make('contact.youtube')->label('يوتيوب')->url(),
                                Forms\Components\TextInput::make('contact.tiktok')->label('تيك توك')->url(),
                            ])->columns(2),

                        Forms\Components\Tabs\Tab::make('SEO والمشاركة')
                            ->schema([
                                Forms\Components\TextInput::make('seo.meta_title')->label('عنوان SEO الافتراضي')->columnSpanFull(),
                                Forms\Components\Textarea::make('seo.meta_description')->label('وصف SEO الافتراضي')->columnSpanFull(),
                                Forms\Components\TextInput::make('seo.meta_keywords')->label('كلمات مفتاحية (اختياري)')->columnSpanFull(),
                                Forms\Components\Toggle::make('seo.indexing_enabled')->label('السماح لمحركات البحث بالفهرسة'),
                                Forms\Components\Select::make('seo.twitter_card_type')
                                    ->label('نوع بطاقة X (تويتر)')
                                    ->options(['summary' => 'Summary', 'summary_large_image' => 'Summary Large Image'])
                                    ->native(false),
                            ])->columns(2),

                        Forms\Components\Tabs\Tab::make('الحسابات والوصول')
                            ->schema([
                                Forms\Components\Toggle::make('access.allow_registration')
                                    ->label('السماح بإنشاء حسابات جديدة')
                                    ->helperText('تعطيلها لا يؤثر على تسجيل الدخول أو لوحة الإدارة إطلاقًا.'),
                                Forms\Components\Toggle::make('access.show_registration_cta')->label('إظهار زر "إنشاء حساب" بالقائمة'),
                            ])->columns(2),

                        Forms\Components\Tabs\Tab::make('التنبيه العام')
                            ->schema([
                                Forms\Components\Toggle::make('announcement.enabled')->label('تفعيل شريط التنبيه')->live(),
                                Forms\Components\Select::make('announcement.type')
                                    ->label('نوع التنبيه')
                                    ->options(['info' => 'معلومة', 'success' => 'نجاح', 'warning' => 'تحذير', 'urgent' => 'عاجل'])
                                    ->native(false)
                                    ->visible(fn (Get $get) => $get('announcement.enabled')),
                                Forms\Components\TextInput::make('announcement.text')
                                    ->label('نص التنبيه (نص عادي فقط، لا HTML)')
                                    ->visible(fn (Get $get) => $get('announcement.enabled'))
                                    ->columnSpanFull(),
                                Forms\Components\TextInput::make('announcement.url')
                                    ->label('رابط عند الضغط (اختياري)')->url()
                                    ->visible(fn (Get $get) => $get('announcement.enabled')),
                                Forms\Components\TextInput::make('announcement.cta_label')
                                    ->label('نص الزر (اختياري)')
                                    ->visible(fn (Get $get) => $get('announcement.enabled')),
                            ])->columns(2),

                        Forms\Components\Tabs\Tab::make('الفوتر')
                            ->schema([
                                Forms\Components\Textarea::make('footer.description')->label('وصف مختصر بالفوتر')->columnSpanFull(),
                                Forms\Components\TextInput::make('footer.copyright_text')
                                    ->label('نص حقوق النشر')
                                    ->helperText('اتركه فارغًا لعرض "© السنة الحالية + اسم المنصة" تلقائيًا.'),
                                Forms\Components\Toggle::make('footer.show_social_links')->label('إظهار روابط التواصل بالفوتر'),
                                Forms\Components\Toggle::make('footer.show_legal_links')->label('إظهار روابط الشروط والخصوصية'),
                            ])->columns(2),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->can('settings.update'), 403, 'ليس لديك صلاحية تعديل الإعدادات - يمكنك العرض فقط.');

        $state = $this->form->getState();
        $settings = app(PlatformSettingsService::class);

        foreach ($state as $group => $values) {
            $settings->setMany($group, $values, Auth::user());
        }

        Notification::make()->success()->title('تم حفظ الإعدادات بنجاح')->send();
    }

    public function resetAppearance(): void
    {
        abort_unless(auth()->user()?->can('settings.update'), 403, 'ليس لديك صلاحية تعديل الإعدادات.');

        $defaults = config('platform.appearance');
        $resetValues = collect($defaults)->map(fn ($definition) => $definition['default'])->all();

        app(PlatformSettingsService::class)->setMany('appearance', $resetValues, Auth::user());

        $this->form->fill(['appearance' => $resetValues] + $this->form->getState());

        Notification::make()->success()->title('أُعيد ضبط المظهر للوضع الافتراضي')->send();
    }
}