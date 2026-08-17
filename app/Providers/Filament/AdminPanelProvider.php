<?php

namespace App\Providers\Filament;

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
            ->renderHook(
                'panels::head.start',
                fn (): HtmlString => new HtmlString(<<<'HTML'
                    <script>
                        if (window.innerWidth < 1024) {
                            localStorage.setItem('isOpen', 'false');
                        }
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
            ])
            ->authMiddleware([Authenticate::class]);
    }
}