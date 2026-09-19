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

    $exists = fn (?string $path) => $path && \Illuminate\Support\Facades\Storage::disk('public')->exists($path);
    $logoUrl = $exists($branding['logo_main']) ? \Illuminate\Support\Facades\Storage::url($branding['logo_main']) : null;
    $faviconUrl = $exists($branding['favicon']) ? \Illuminate\Support\Facades\Storage::url($branding['favicon']) : null;
    $ogImageUrl = $exists($branding['social_share_image']) ? \Illuminate\Support\Facades\Storage::url($branding['social_share_image']) : null;

    $metaTitle = $seo['meta_title'] ?: $general['site_name'];
    $metaDescription = $seo['meta_description'] ?: $general['short_description'];
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
    <link href="https://fonts.googleapis.com/css2?family={{ str_replace(' ', '+', $googleFontFamily) }}:wght@400;600;700;800;900&display=swap" rel="stylesheet">

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
</head>
<body class="min-h-screen flex flex-col antialiased">

<div class="aurora-bg"></div>

@if ($announcement['enabled'] && $announcement['text'])
    <x-announcement-bar :announcement="$announcement" />
@endif

<header x-data="{ mobileOpen: false }" @keydown.escape.window="mobileOpen = false" class="sticky top-0 z-40 glass border-x-0 border-t-0">
    <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between gap-3">
        <a href="{{ route('home') }}" class="flex items-center gap-3 shrink-0" @click="mobileOpen = false">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $general['site_name'] }}" class="h-9 w-auto">
            @else
                <span class="gem-facet anim-float w-9 h-9 grid place-items-center text-sm font-black text-white bg-gradient-to-br from-amethyst via-fuchsia-500 to-gold glow-amethyst">✦</span>
            @endif
            <span class="text-xl font-black text-gradient-gem">{{ $general['short_name'] }}</span>
        </a>

        <nav class="hidden md:flex items-center gap-6 text-sm font-bold text-slate-300">
            @if ($navigation['show_puzzles_link'])
                <a href="{{ route('puzzles.index') }}" class="hover:text-white transition">الأحجيات</a>
            @endif
            @if ($navigation['show_seasons_link'])
                <a href="{{ route('seasons.index') }}" class="hover:text-white transition">المواسم</a>
            @endif
            @if ($navigation['show_challenges_link'])
                <a href="{{ route('challenges.index') }}" class="hover:text-white transition">التحديات</a>
            @endif
            @if ($navigation['show_leaderboard_link'])
                <a href="{{ route('leaderboard.index') }}" class="hover:text-white transition">لوحة الصدارة</a>
            @endif
            @auth
                <a href="{{ route('wallet.index') }}" class="hover:text-gold transition">محفظتي</a>
                <a href="{{ route('redemption.index') }}" class="hover:text-gold transition">الاستبدال</a>
            @endauth
        </nav>

        <div class="flex items-center gap-2 sm:gap-3 text-sm font-bold">
            @if ($appearance['allow_theme_switch'])
                <x-theme-switcher />
            @endif

            @auth
                @if ($navigation['show_gem_balance'])
                    <x-gem-badge :amount="auth()->user()->wallet?->available_balance ?? 0" />
                @endif
                <a href="{{ route('profile.edit') }}" class="hidden sm:inline-flex chip">{{ auth()->user()->name }}</a>
                <form method="POST" action="{{ route('logout') }}" class="hidden md:block">
                    @csrf
                    <button class="chip !text-rose">خروج</button>
                </form>
            @else
                <a href="{{ route('login') }}" class="hidden sm:inline-flex chip">دخول</a>
                @if ($access['allow_registration'] && $access['show_registration_cta'])
                    <a href="{{ route('register') }}" class="hidden md:inline-flex btn-gem !py-2 !px-4 text-sm">إنشاء حساب</a>
                @endif
            @endauth

            <button
                @click="mobileOpen = !mobileOpen"
                type="button"
                class="md:hidden grid place-items-center w-10 h-10 rounded-xl border border-white/10 bg-white/5 text-white shrink-0"
                :aria-expanded="mobileOpen"
                aria-label="فتح القائمة"
            >
                <svg x-show="!mobileOpen" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
                <svg x-show="mobileOpen" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>

    <div
        x-show="mobileOpen"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-2"
        class="md:hidden border-t border-white/10 bg-night-900/95 backdrop-blur-xl"
        @click.outside="mobileOpen = false"
    >
        <nav class="max-w-6xl mx-auto px-4 py-4 flex flex-col gap-1 text-sm font-bold text-slate-300">
            @if ($navigation['show_puzzles_link'])
                <a href="{{ route('puzzles.index') }}" @click="mobileOpen = false" class="rounded-xl px-4 py-3 hover:bg-white/5 hover:text-white transition">الأحجيات</a>
            @endif
            @if ($navigation['show_seasons_link'])
                <a href="{{ route('seasons.index') }}" @click="mobileOpen = false" class="rounded-xl px-4 py-3 hover:bg-white/5 hover:text-white transition">المواسم</a>
            @endif
            @if ($navigation['show_challenges_link'])
                <a href="{{ route('challenges.index') }}" @click="mobileOpen = false" class="rounded-xl px-4 py-3 hover:bg-white/5 hover:text-white transition">التحديات</a>
            @endif
            @if ($navigation['show_leaderboard_link'])
                <a href="{{ route('leaderboard.index') }}" @click="mobileOpen = false" class="rounded-xl px-4 py-3 hover:bg-white/5 hover:text-white transition">لوحة الصدارة</a>
            @endif

            @auth
                <a href="{{ route('wallet.index') }}" @click="mobileOpen = false" class="rounded-xl px-4 py-3 hover:bg-white/5 hover:text-gold transition">محفظتي</a>
                <a href="{{ route('redemption.index') }}" @click="mobileOpen = false" class="rounded-xl px-4 py-3 hover:bg-white/5 hover:text-gold transition">الاستبدال</a>
                <a href="{{ route('profile.edit') }}" @click="mobileOpen = false" class="rounded-xl px-4 py-3 hover:bg-white/5 hover:text-white transition">{{ auth()->user()->name }}</a>

                <form method="POST" action="{{ route('logout') }}" class="mt-2 pt-3 border-t border-white/10">
                    @csrf
                    <button class="w-full text-right rounded-xl px-4 py-3 text-rose hover:bg-rose/10 transition">تسجيل الخروج</button>
                </form>
            @else
                <div class="mt-2 pt-3 border-t border-white/10 flex flex-col gap-2">
                    <a href="{{ route('login') }}" @click="mobileOpen = false" class="chip text-center">دخول</a>
                    @if ($access['allow_registration'] && $access['show_registration_cta'])
                        <a href="{{ route('register') }}" @click="mobileOpen = false" class="btn-gem justify-center">إنشاء حساب</a>
                    @endif
                </div>
            @endauth
        </nav>
    </div>
</header>

<main class="flex-1">
    <div class="max-w-6xl mx-auto px-4 py-10">
        @if (session('success'))
            <div class="mb-6 rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-3 text-sm font-bold anim-fade-up">
                🎉 {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-6 rounded-xl border border-rose/30 bg-rose/10 text-rose px-4 py-3 text-sm font-bold anim-fade-up">
                ❌ {{ session('error') }}
            </div>
        @endif

        @if (session('hint'))
            <div class="mb-6 rounded-xl border border-gold/30 bg-gold/10 text-gold px-4 py-3 text-sm font-bold anim-fade-up">
                💡 <strong>التلميح:</strong> {{ session('hint') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded-xl border border-rose/30 bg-rose/10 text-rose px-4 py-3 text-sm anim-fade-up">
                <ul class="list-disc ps-5 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </div>
</main>

<footer class="glass border-x-0 border-b-0 mt-10">
    <div class="max-w-6xl mx-auto px-4 py-8">
        @if ($footer['description'])
            <p class="text-sm text-slate-400 mb-4 max-w-xl">{{ $footer['description'] }}</p>
        @endif

        @if ($footer['show_social_links'] && collect($contact)->filter()->isNotEmpty())
            <div class="flex items-center gap-3 mb-4 flex-wrap">
                @foreach (['whatsapp' => 'واتساب', 'instagram' => 'انستغرام', 'facebook' => 'فيسبوك', 'x_twitter' => 'X', 'telegram' => 'تيليجرام', 'youtube' => 'يوتيوب', 'tiktok' => 'تيك توك'] as $key => $label)
                    @if ($contact[$key])
                        <a href="{{ $contact[$key] }}" target="_blank" rel="noopener" class="chip !py-1.5 !px-3 text-xs">{{ $label }}</a>
                    @endif
                @endforeach
            </div>
        @endif

        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 text-sm text-slate-400 text-center sm:text-right pt-4 border-t border-white/5">
            <span>{{ $footer['copyright_text'] ?: ('© '.date('Y').' '.$general['site_name']) }}</span>

            @if ($footer['show_legal_links'])
                <div class="flex items-center gap-4 font-bold">
                    <a href="{{ route('pages.terms') }}" class="hover:text-white transition">شروط الاستخدام</a>
                    <a href="{{ route('pages.privacy') }}" class="hover:text-white transition">سياسة الخصوصية</a>
                </div>
            @endif
        </div>
    </div>
</footer>

</body>
</html>