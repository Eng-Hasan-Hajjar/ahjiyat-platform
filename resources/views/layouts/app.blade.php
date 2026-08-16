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

{{-- ===== Header عصري ===== --}}
<header class="sticky top-0 z-40 glass border-x-0 border-t-0">
    <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between gap-4">
        <a href="{{ route('home') }}" class="flex items-center gap-3 shrink-0">
            <span class="gem-facet anim-float w-9 h-9 grid place-items-center text-sm font-black text-white bg-gradient-to-br from-amethyst via-fuchsia-500 to-gold glow-amethyst">✦</span>
            <span class="text-xl font-black text-gradient-gem">أحجيات</span>
        </a>

        <nav class="hidden md:flex items-center gap-6 text-sm font-bold text-slate-300">
            <a href="{{ route('puzzles.index') }}" class="hover:text-white transition">الأحجيات</a>
            <a href="{{ route('leaderboard.index') }}" class="hover:text-white transition">لوحة الصدارة</a>
            @auth
                <a href="{{ route('wallet.index') }}" class="hover:text-gold transition">محفظتي</a>
                <a href="{{ route('redemption.index') }}" class="hover:text-gold transition">الاستبدال</a>
            @endauth
        </nav>

        <div class="flex items-center gap-3 text-sm font-bold">
            @auth
                <x-gem-badge :amount="auth()->user()->wallet?->available_balance ?? 0" />
                <a href="{{ route('profile.edit') }}" class="chip">{{ auth()->user()->name }}</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="chip !text-rose">خروج</button>
                </form>
            @else
                <a href="{{ route('login') }}" class="chip">دخول</a>
                <a href="{{ route('register') }}" class="btn-gem !py-2 !px-4 text-sm">إنشاء حساب</a>
            @endauth
        </div>
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
    <div class="max-w-6xl mx-auto px-4 py-6 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-400">
        <span>© {{ date('Y') }} <b class="text-gradient-gem">أحجيات</b></span>
        <span>منصة ألغاز وتحديات ذهنية</span>
    </div>
</footer>

</body>
</html>