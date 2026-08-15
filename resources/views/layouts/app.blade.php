<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} - @yield('title', 'منصة الأحجيات والمكافآت')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800&family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex flex-col">

    <header class="bg-ink text-parchment">
        <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
            <a href="{{ route('home') }}" class="font-display font-extrabold text-xl flex items-center gap-2">
                <span class="w-6 h-6 bg-gold gem-facet inline-block"></span>
                أحجيات
            </a>

            <nav class="hidden md:flex items-center gap-6 text-sm font-medium">
                <a href="{{ route('puzzles.index') }}" class="hover:text-gold transition">الأحجيات</a>
                <a href="{{ route('leaderboard.index') }}" class="hover:text-gold transition">لوحة الصدارة</a>
                @auth
                    <a href="{{ route('wallet.index') }}" class="hover:text-gold transition">محفظتي</a>
                    <a href="{{ route('redemption.index') }}" class="hover:text-gold transition">الاستبدال</a>
                @endauth
            </nav>

            <div class="flex items-center gap-3">
                @auth
                    <x-gem-badge :amount="auth()->user()->wallet?->available_balance ?? 0" />
                    <a href="{{ route('profile.edit') }}" class="text-sm hover:text-gold">{{ auth()->user()->name }}</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="text-sm text-rose hover:underline">خروج</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="text-sm hover:text-gold">دخول</a>
                    <a href="{{ route('register') }}" class="text-sm bg-amethyst px-3 py-1.5 rounded-md hover:bg-amethyst-700 transition">إنشاء حساب</a>
                @endauth
            </div>
        </div>
    </header>

    <main class="flex-1">
        <div class="max-w-6xl mx-auto px-4 py-8">
            @if (session('success'))
                <div class="mb-6 rounded-lg bg-emerald-50 border border-emerald text-emerald-600 px-4 py-3 text-sm">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-6 rounded-lg bg-rose-50 border border-rose text-rose px-4 py-3 text-sm">
                    {{ session('error') }}
                </div>
            @endif

            @if (session('hint'))
                <div class="mb-6 rounded-lg bg-gold-50 border border-gold text-ink px-4 py-3 text-sm">
                    <strong>التلميح:</strong> {{ session('hint') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-6 rounded-lg bg-rose-50 border border-rose text-rose px-4 py-3 text-sm">
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

    <footer class="bg-ink text-parchment/70 text-sm">
        <div class="max-w-6xl mx-auto px-4 py-6 flex items-center justify-between">
            <span>&copy; {{ date('Y') }} أحجيات</span>
            <span>منصة ألغاز وتحديات ذهنية</span>
        </div>
    </footer>

</body>
</html>
