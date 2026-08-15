@extends('layouts.guest')

@section('title', 'تسجيل الدخول')

@section('content')
    <h1 class="font-display font-bold text-lg text-ink mb-4 text-center">تسجيل الدخول</h1>

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-ink/70 mb-1">البريد الإلكتروني</label>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus
                   class="w-full rounded-lg border border-ink/20 px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-amethyst">
        </div>
        <div>
            <label class="block text-sm font-medium text-ink/70 mb-1">كلمة المرور</label>
            <input type="password" name="password" required
                   class="w-full rounded-lg border border-ink/20 px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-amethyst">
        </div>
        <label class="flex items-center gap-2 text-sm text-ink/60">
            <input type="checkbox" name="remember" class="accent-amethyst">
            تذكرني
        </label>
        <button type="submit" class="w-full bg-amethyst text-white font-bold py-2.5 rounded-lg hover:bg-amethyst-700 transition">
            دخول
        </button>
    </form>

    <p class="text-center text-sm text-ink/60 mt-4">
        ليس لديك حساب؟ <a href="{{ route('register') }}" class="text-amethyst font-medium">إنشاء حساب جديد</a>
    </p>
@endsection
