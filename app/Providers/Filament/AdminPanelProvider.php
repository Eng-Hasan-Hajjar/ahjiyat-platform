<?php

namespace App\Providers\Filament;
use App\Http\Middleware\SecurityHeaders;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Support\HtmlString;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // E22: ترتيب منطقي لمجموعات الإدارة (محتوى ← منافسات وفرق ← اقتصاد ← تفاعل ← إشراف وأمان ← تحليلات ونظام ← وصول). نصوص وترتيب فقط، بلا منطق ولا صلاحيات.
            ->navigationGroups(['الأحجيات', 'الحملات', 'المنافسات', 'الفرق', 'المتجر', 'الاقتصاد', 'الجواهر والاستبدال', 'التقدُّم', 'المشاركة', 'الإعلانات والرعايات', 'الإشراف', 'الأمان', 'التحليلات والنظام', 'إدارة الوصول'])
            ->login()
            ->brandName('أحجيات')
            // نفس تدرّج ألوان gem-tone المستخدم بباقي الموقع (app.css)
            ->colors([
                'primary' => Color::hex('#8b5cf6'), // amethyst
                'success' => Color::hex('#34d399'), // emerald
                'warning' => Color::hex('#fcd34d'), // gold
                'danger' => Color::hex('#fb7185'),  // rose
            ])
            // ثيم غامق دائم يطابق هوية الموقع، بدون خيار التبديل للفاتح
            ->darkMode(isForced: true)
            ->font('Cairo')
            // إصلاح علّة معروفة بـ Filament v3: القائمة الجانبية تُهيّأ "مفتوحة" افتراضياً
            // حتى على الموبايل (github.com/filamentphp/filament/issues/15056). نجبرها
            // تنغلق بكل تحميل صفحة على شاشة ضيقة - نفس سلوك أي قائمة موبايل منسدلة
            // طبيعية (ما يفترض تفضل مفتوحة بين تنقلات الصفحات أصلاً).
            // كمان نضيف زر إغلاق واضح جوا القائمة على الموبايل، لأن Filament
            // افتراضياً يعتمد فقط على "اضغط برّا القائمة لتسكرها" بدون زر صريح.
            //
            // Hotfix (2026-10-09): السبب الحقيقي لظهور الزر على Desktop لم يكن
            // تقصير Tailwind بتوليد lg:hidden (الكلاس هذا مُولَّد فعلاً - مستخدَم
            // بملفين blade آخرين بالمشروع) بل أن btn.style.cssText أدناه كان يضيف
            // display:flex كـ inline style، وinline style دائماً يتغلّب على أي
            // قاعدة class-based (بما فيها lg:hidden جوا @media) بصرف النظر عن
            // عرض الشاشة. الإصلاح: (أ) الأسلوب المفضَّل بالطلب - ما عاد الزر
            // يُنشأ أصلاً إلا لو matchMedia يطابق الموبايل فعلاً، مع الاستماع
            // لـresize لإضافته/حذفه ديناميكياً عند تغيّر حجم النافذة. (ب) خط دفاع
            // احتياطي - كل الـstyle انتقل لقاعدة CSS حقيقية (مش inline) مع
            // @media (min-width:1024px){ display:none !important } صريحة، فحتى
            // لو صار أي استثناء بمنطق JS تبقى القاعدة هذي الفيصل. تمت أيضاً
            // إزالة حرف ✕ الخام واستبداله بـSVG صغير (Heroicon x-mark) متناسق
            // مع Filament.
            ->renderHook(
                'panels::head.start',
                fn (): HtmlString => new HtmlString(<<<'HTML'
                    <style>
                        .ahjiyat-sidebar-close-btn {
                            position: absolute;
                            top: 1rem;
                            inset-inline-end: 1rem;
                            z-index: 40;
                            width: 2.25rem;
                            height: 2.25rem;
                            border-radius: 9999px;
                            background: rgba(255, 255, 255, .08);
                            color: #fff;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            border: 1px solid rgba(255, 255, 255, .15);
                            cursor: pointer;
                        }
                        .ahjiyat-sidebar-close-btn svg {
                            width: 1.1rem;
                            height: 1.1rem;
                        }
                        /* خط دفاع احتياطي: حتى لو تغيّر منطق الـJS مستقبلاً وصار
                           الزر يُنشأ بالخطأ على Desktop، هاي القاعدة تضمن إخفاءه. */
                        @media (min-width: 1024px) {
                            .ahjiyat-sidebar-close-btn {
                                display: none !important;
                            }
                        }
                    </style>
                    <script>
                        if (window.innerWidth < 1024) {
                            localStorage.setItem('isOpen', 'false');
                        }

                        document.addEventListener('DOMContentLoaded', function () {
                            var MOBILE_QUERY = '(max-width: 1023px)';

                            function removeSidebarCloseButton() {
                                var existing = document.querySelector('.ahjiyat-sidebar-close-btn');
                                if (existing) existing.remove();
                            }

                            function createSidebarCloseButton() {
                                var sidebar = document.querySelector('.fi-sidebar');
                                if (!sidebar || sidebar.querySelector('.ahjiyat-sidebar-close-btn')) return;

                                var btn = document.createElement('button');
                                btn.type = 'button';
                                btn.className = 'ahjiyat-sidebar-close-btn';
                                btn.setAttribute('aria-label', 'إغلاق القائمة');
                                btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 5.22a.75.75 0 0 1 1.06 0L10 8.94l3.72-3.72a.75.75 0 1 1 1.06 1.06L11.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06L10 11.06l-3.72 3.72a.75.75 0 0 1-1.06-1.06L8.94 10 5.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>';
                                btn.addEventListener('click', function () {
                                    if (window.Alpine && window.Alpine.store('sidebar')) {
                                        window.Alpine.store('sidebar').close();
                                    }
                                });

                                sidebar.prepend(btn);
                            }

                            // الأسلوب المفضَّل (A): لا يُنشأ العنصر إطلاقاً إلا لو
                            // العرض فعلاً موبايل/تابلت - مش بس نخفيه بالـCSS بعد إنشائه.
                            function syncSidebarCloseButton() {
                                if (window.matchMedia(MOBILE_QUERY).matches) {
                                    createSidebarCloseButton();
                                } else {
                                    removeSidebarCloseButton();
                                }
                            }

                            syncSidebarCloseButton();
                            document.addEventListener('livewire:navigated', syncSidebarCloseButton);
                            window.addEventListener('resize', syncSidebarCloseButton);
                        });
                    </script>
                    HTML),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([Pages\Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
           ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // E8: لوحة Filament لا ترث مجموعة web العامة تلقائياً - لها
                // Middleware Stack خاص بها معرَّف صراحة هنا فقط. اكتُشف
                // اختباريًا (SecurityHeadersTest) أن /admin كانت بلا Headers
                // أمان إطلاقاً رغم تسجيلها على مجموعة web بـbootstrap/app.php.
                SecurityHeaders::class,
            ])
            
            ->authMiddleware([Authenticate::class]);
    }
}