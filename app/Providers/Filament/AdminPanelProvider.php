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
            // كمان نضيف زر إغلاق (✕) واضح جوا القائمة على الموبايل، لأن Filament
            // افتراضياً يعتمد فقط على "اضغط برّا القائمة لتسكرها" بدون زر صريح.
            ->renderHook(
                'panels::head.start',
                fn (): HtmlString => new HtmlString(<<<'HTML'
                    <script>
                        if (window.innerWidth < 1024) {
                            localStorage.setItem('isOpen', 'false');
                        }

                        document.addEventListener('DOMContentLoaded', function () {
                            function addSidebarCloseButton() {
                                var sidebar = document.querySelector('.fi-sidebar');
                                if (!sidebar || sidebar.querySelector('.ahjiyat-sidebar-close-btn')) return;

                                var btn = document.createElement('button');
                                btn.type = 'button';
                                btn.className = 'ahjiyat-sidebar-close-btn lg:hidden';
                                btn.setAttribute('aria-label', 'إغلاق القائمة');
                                btn.textContent = '✕';
                                btn.style.cssText = 'position:absolute;top:1rem;inset-inline-end:1rem;z-index:40;width:2.25rem;height:2.25rem;border-radius:9999px;background:rgba(255,255,255,.08);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.1rem;border:1px solid rgba(255,255,255,.15);cursor:pointer;';
                                btn.addEventListener('click', function () {
                                    if (window.Alpine && window.Alpine.store('sidebar')) {
                                        window.Alpine.store('sidebar').close();
                                    }
                                });

                                sidebar.prepend(btn);
                            }

                            addSidebarCloseButton();
                            document.addEventListener('livewire:navigated', addSidebarCloseButton);
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