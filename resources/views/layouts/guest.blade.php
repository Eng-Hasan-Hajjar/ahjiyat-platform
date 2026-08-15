<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} - @yield('title')</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800&family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ink flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <div class="text-center mb-6">
            <span class="w-10 h-10 bg-gold gem-facet inline-block mb-2"></span>
            <h1 class="font-display font-extrabold text-2xl text-parchment">أحجيات</h1>
        </div>
        <div class="bg-parchment rounded-2xl shadow-xl p-6">
            @if ($errors->any())
                <div class="mb-4 rounded-lg bg-rose-50 border border-rose text-rose px-4 py-3 text-sm">
                    <ul class="list-disc pr-5 space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if (session('success'))
                <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald text-emerald-600 px-4 py-3 text-sm">
                    {{ session('success') }}
                </div>
            @endif
            @yield('content')
        </div>
    </div>
</body>
</html>
