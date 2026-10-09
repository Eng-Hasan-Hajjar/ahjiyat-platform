@php
    $settings = app(\App\Services\PlatformSettingsService::class);
    $general = $settings->getGroup('general');
    $branding = $settings->getGroup('branding');
    $appearance = $settings->getGroup('appearance');
    $navigation = $settings->getGroup('navigation');
    $contact = $settings->getGroup('contact');
    $seo = $settings->getGroup('seo');
    $access = $settings->getGroup('access');
    $announcement = $settings->getGroup('announcement');
    $footer = $settings->getGroup('footer');

    $fontMap = ['cairo' => 'Cairo', 'tajawal' => 'Tajawal', 'noto_kufi' => 'Noto Kufi Arabic'];
    $googleFontFamily = $fontMap[$appearance['font_family']] ?? 'Cairo';
    $fontSizeMap = ['small' => '15px', 'normal' => '16px', 'large' => '17.5px'];
    $radiusMap = ['compact' => '.6rem', 'rounded' => '1rem', 'soft' => '1.5rem'];

    $exists = fn(?string $path) => $path && \Illuminate\Support\Facades\Storage::disk('public')->exists($path);
    $logoUrl = $exists($branding['logo_main']) ? \Illuminate\Support\Facades\Storage::url($branding['logo_main']) : null;
    $faviconUrl = $exists($branding['favicon']) ? \Illuminate\Support\Facades\Storage::url($branding['favicon']) : null;
    $ogImageUrl = $exists($branding['social_share_image']) ? \Illuminate\Support\Facades\Storage::url($branding['social_share_image']) : null;

    $metaTitle = $seo['meta_title'] ?: $general['site_name'];
    $metaDescription = $seo['meta_description'] ?: $general['short_description'];
    // E11: هوية Navbar - Avatar مجهَّز بدل أيقونة عامة (بند 64)، محسوبة هنا فقط عند تسجيل الدخول.
    $navLoadout = auth()->check() ? app(\App\Services\PlayerIdentity\CosmeticLoadoutService::class)->loadoutFor(auth()->user()) : null;
    // E12 (بند 131/274): رقم مستوى صغير فقط - لا XP Bar ضخمة بالـNavbar.
    $navLevel = auth()->check() ? app(\App\Services\Progression\LevelService::class)->currentLevelFor(auth()->user()) : null;
    // E22: بنية التنقل (عرض فقط) - مصدر واحد للرأس وللتنقل السفلي.
    $menu = \App\Support\NavigationMenu::build(auth()->user(), $navigation);

@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="utf-8">
    <script>
        (function () {
            try {
                var allowSwitch = {{ $appearance['allow_theme_switch'] ? 'true' : 'false' }};
                var serverDefault = @js($appearance['default_theme']);
                var stored = allowSwitch ? localStorage.getItem('ahjiyat-theme') : null;
                var mode = stored || serverDefault;
                if (mode === 'system') {
                    mode = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
                }
                document.documentElement.setAttribute('data-theme', mode === 'light' ? 'light' : 'dark');
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>

    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="{{ $appearance['color_primary'] }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $metaTitle }} - @yield('title', $general['tagline'])</title>
    <meta name="description" content="@yield('meta_description', $metaDescription)">
    @if ($seo['meta_keywords'])
        <meta name="keywords" content="{{ $seo['meta_keywords'] }}">
    @endif
    @unless ($seo['indexing_enabled'])
        <meta name="robots" content="noindex, nofollow">
    @endunless

    <meta property="og:title" content="@yield('title', $metaTitle)">
    <meta property="og:description" content="@yield('meta_description', $metaDescription)">
    <meta property="og:type" content="website">
    @if ($ogImageUrl)
        <meta property="og:image" content="{{ $ogImageUrl }}">
    @endif
    <meta name="twitter:card" content="{{ $seo['twitter_card_type'] }}">

    @if ($faviconUrl)
        <link rel="icon" href="{{ $faviconUrl }}">
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family={{ str_replace(' ', '+', $googleFontFamily) }}:wght@400;600;700;800;900&display=swap"
        rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        :root {
            --color-primary: {{ $appearance['color_primary'] }};
            --color-secondary: {{ $appearance['color_secondary'] }};
            --color-accent: {{ $appearance['color_accent'] }};
            --color-success: {{ $appearance['color_success'] }};
            --color-warning: {{ $appearance['color_warning'] }};
            --color-danger: {{ $appearance['color_danger'] }};
            --ui-radius: {{ $radiusMap[$appearance['ui_radius']] ?? '1rem' }};
            --font-body: "{{ $googleFontFamily }}", ui-sans-serif, system-ui, sans-serif;
            --font-display: "{{ $googleFontFamily }}", ui-sans-serif, system-ui, sans-serif;
            font-size: {{ $fontSizeMap[$appearance['base_font_size']] ?? '16px' }};
        }
    </style>

    @stack('head')
</head>

<body class="min-h-screen flex flex-col antialiased @auth has-bottom-nav @endauth">

    {{-- E22: رابط تخطّي للوحة المفاتيح وقارئات الشاشة: أول عنصر قابل للتركيز، يظهر عند التركيز فقط. --}}
    <a href="#main-content" data-skip-link class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:start-3 focus:z-[100] focus:rounded-xl focus:bg-night-900 focus:px-4 focus:py-2.5 focus:text-sm focus:font-bold focus:text-white focus:outline-none focus:ring-2 focus:ring-amethyst">تخطَّ إلى المحتوى</a>

    <div class="aurora-bg"></div>

    @if ($announcement['enabled'] && $announcement['text'])
        <x-announcement-bar :announcement="$announcement" />
    @endif

    @include('layouts.partials.header')

    <main id="main-content" tabindex="-1" class="flex-1 focus:outline-none">
        {{-- E22: غلاف المحتوى مقيَّد القراءة ومستقل عن غلاف الرأس السائل. الصفحة تعدّل عرضه بـ@section('content_width', 'max-w-7xl') مثلًا. --}}
        <div class="mx-auto w-full {{ trim($__env->yieldContent('content_width')) ?: 'max-w-6xl' }} px-4 sm:px-6 py-6 sm:py-10">
            @include('layouts.partials.flash')

            @yield('content')
        </div>
    </main>

    <footer class="app-footer mt-10">
        <div class="max-w-6xl mx-auto px-4 py-8">
            @if ($footer['description'])
                <p class="text-sm text-slate-400 mb-4 max-w-xl">{{ $footer['description'] }}</p>
            @endif

            @if ($footer['show_social_links'] && collect($contact)->filter()->isNotEmpty())
                <div class="flex items-center gap-3 mb-4 flex-wrap">
                    @foreach (['whatsapp' => 'واتساب', 'instagram' => 'انستغرام', 'facebook' => 'فيسبوك', 'x_twitter' => 'X', 'telegram' => 'تيليجرام', 'youtube' => 'يوتيوب', 'tiktok' => 'تيك توك'] as $key => $label)
                        @if ($contact[$key])
                            <a href="{{ $contact[$key] }}" target="_blank" rel="noopener"
                                class="chip !py-1.5 !px-3 text-xs">{{ $label }}</a>
                        @endif
                    @endforeach
                </div>
            @endif

            <div
                class="flex flex-col sm:flex-row items-center justify-between gap-3 text-sm text-slate-400 text-center sm:text-right pt-4 border-t border-white/5">
                <span>{{ $footer['copyright_text'] ?: ('© ' . date('Y') . ' ' . $general['site_name']) }}</span>

                @if ($footer['show_legal_links'])
                    <div class="flex items-center gap-4 font-bold">
                        <a href="{{ route('pages.terms') }}" class="hover:text-white transition">شروط الاستخدام</a>
                        <a href="{{ route('pages.privacy') }}" class="hover:text-white transition">سياسة الخصوصية</a>
                    </div>
                @endif
            </div>
        </div>
    </footer>

    @include('layouts.partials.bottom-nav')

</body>

</html>