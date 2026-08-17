<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#060a17">
    <title>{{ config('app.name') }} - @yield('title')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex items-center justify-center px-4 py-10 antialiased">

<div class="aurora-bg"></div>

<div class="w-full max-w-sm">
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
                🎉 {{ session('success') }}
            </div>
        @endif

        @if (session('status'))
            <div class="mb-5 rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-3 text-sm font-bold">
                {{ session('status') }}
            </div>
        @endif

        @yield('content')
    </div>
</div>

</body>
</html>