<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#060a17">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} - @yield('title', 'منصة الأحجيات والمكافآت')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex flex-col antialiased">

<div class="aurora-bg"></div>

{{-- ===== Header عصري (متجاوب مع الموبايل) ===== --}}
<header x-data="{ mobileOpen: false }" @keydown.escape.window="mobileOpen = false" class="sticky top-0 z-40 glass border-x-0 border-t-0">
    <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between gap-3">
        <a href="{{ route('home') }}" class="flex items-center gap-3 shrink-0" @click="mobileOpen = false">
            <span class="gem-facet anim-float w-9 h-9 grid place-items-center text-sm font-black text-white bg-gradient-to-br from-amethyst via-fuchsia-500 to-gold glow-amethyst">✦</span>
            <span class="text-xl font-black text-gradient-gem">أحجيات</span>
        </a>

        {{-- روابط سطح المكتب --}}
        <nav class="hidden md:flex items-center gap-6 text-sm font-bold text-slate-300">
            <a href="{{ route('puzzles.index') }}" class="hover:text-white transition">الأحجيات</a>
            <a href="{{ route('challenges.index') }}" class="hover:text-white transition">التحديات</a>
            <a href="{{ route('leaderboard.index') }}" class="hover:text-white transition">لوحة الصدارة</a>
            @auth
                <a href="{{ route('wallet.index') }}" class="hover:text-gold transition">محفظتي</a>
                <a href="{{ route('redemption.index') }}" class="hover:text-gold transition">الاستبدال</a>
            @endauth
        </nav>

        {{-- يمين الشريط: رصيد + حساب (سطح المكتب) + زر القائمة (موبايل) --}}
        <div class="flex items-center gap-2 sm:gap-3 text-sm font-bold">
            @auth
                <x-gem-badge :amount="auth()->user()->wallet?->available_balance ?? 0" />
                <a href="{{ route('profile.edit') }}" class="hidden sm:inline-flex chip">{{ auth()->user()->name }}</a>
                <form method="POST" action="{{ route('logout') }}" class="hidden md:block">
                    @csrf
                    <button class="chip !text-rose">خروج</button>
                </form>
            @else
                <a href="{{ route('login') }}" class="hidden sm:inline-flex chip">دخول</a>
                <a href="{{ route('register') }}" class="hidden md:inline-flex btn-gem !py-2 !px-4 text-sm">إنشاء حساب</a>
            @endauth

            {{-- زر الهامبرغر: يظهر فقط تحت md --}}
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

    {{-- ===== قائمة الموبايل المنسدلة ===== --}}
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
            <a href="{{ route('puzzles.index') }}" @click="mobileOpen = false" class="rounded-xl px-4 py-3 hover:bg-white/5 hover:text-white transition">الأحجيات</a>
            <a href="{{ route('challenges.index') }}" @click="mobileOpen = false" class="rounded-xl px-4 py-3 hover:bg-white/5 hover:text-white transition">التحديات</a>
            <a href="{{ route('leaderboard.index') }}" @click="mobileOpen = false" class="rounded-xl px-4 py-3 hover:bg-white/5 hover:text-white transition">لوحة الصدارة</a>

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
                    <a href="{{ route('register') }}" @click="mobileOpen = false" class="btn-gem justify-center">إنشاء حساب</a>
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
                <ul class="list-disc pr-5 space-y-1">
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
    <div class="max-w-6xl mx-auto px-4 py-6 flex flex-col sm:flex-row items-center justify-between gap-3 text-sm text-slate-400 text-center sm:text-right">
        <span>© {{ date('Y') }} <b class="text-gradient-gem">أحجيات</b> · منصة ألغاز وتحديات ذهنية</span>
        <div class="flex items-center gap-4 font-bold">
            <a href="{{ route('pages.terms') }}" class="hover:text-white transition">شروط الاستخدام</a>
            <a href="{{ route('pages.privacy') }}" class="hover:text-white transition">سياسة الخصوصية</a>
        </div>
    </div>
</footer>

</body>
</html>