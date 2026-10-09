@php
    // E23: صفحات الدخول تتبع الثيم واللون المعتمدين بلوحة الإدارة كبقية الموقع (كانت داكنة دائمًا بألوان افتراضية). قراءة واحدة من كاش الإعدادات المشترك، بلا استعلام جديد.
    $settings = app(\App\Services\PlatformSettingsService::class);
    $appearance = $settings->getGroup('appearance');
    $fontMap = ['cairo' => 'Cairo', 'tajawal' => 'Tajawal', 'noto_kufi' => 'Noto Kufi Arabic'];
    $googleFontFamily = $fontMap[$appearance['font_family']] ?? 'Cairo';
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
    <meta name="theme-color" content="#060a17">
    <title>{{ config('app.name') }} - @yield('title')</title>

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
            --font-body: "{{ $googleFontFamily }}", ui-sans-serif, system-ui, sans-serif;
            --font-display: "{{ $googleFontFamily }}", ui-sans-serif, system-ui, sans-serif;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center px-4 py-10 antialiased">

{{-- E22: رابط تخطّي وموضع رئيسي لصفحات الدخول (كان غائبًا عن هذا الغلاف المستقل). --}}
<a href="#main-content" data-skip-link class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:start-3 focus:z-[100] focus:rounded-xl focus:bg-night-900 focus:px-4 focus:py-2.5 focus:text-sm focus:font-bold focus:text-white focus:outline-none focus:ring-2 focus:ring-amethyst">تخطَّ إلى المحتوى</a>

<div class="aurora-bg"></div>

<main id="main-content" tabindex="-1" class="w-full max-w-sm focus:outline-none">
    <a href="{{ route('home') }}" class="flex flex-col items-center gap-3 mb-6">
        <span class="gem-facet anim-float w-14 h-14 grid place-items-center text-xl font-black text-white bg-gradient-to-br from-amethyst via-fuchsia-500 to-gold glow-amethyst">✦</span>
        <span class="text-2xl font-black text-gradient-gem">أحجيات</span>
    </a>

    <div class="glass rounded-3xl p-6 sm:p-8 anim-fade-up">
        @if ($errors->any())
            <div class="mb-5 rounded-xl border border-rose/30 bg-rose/10 text-rose px-4 py-3 text-sm">
                <ul class="list-disc pr-5 space-y-1 font-semibold">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('success'))
            <div class="mb-5 rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-3 text-sm font-bold">
                {{ session('success') }}
            </div>
        @endif

        @if (session('status'))
            <div class="mb-5 rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-3 text-sm font-bold">
                {{ session('status') }}
            </div>
        @endif

        @yield('content')
    </div>
</main>

</body>
</html>