@extends('layouts.guest')

@section('title', 'تسجيل الدخول')

@section('content')
    <h1 class="font-display font-black text-xl text-white mb-6 text-center">تسجيل الدخول</h1>

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-bold text-slate-300 mb-2">البريد الإلكتروني</label>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus
                   class="input-gem">
            @error('email')
                <span class="text-xs text-rose font-semibold mt-1.5 block">{{ $message }}</span>
            @enderror
        </div>
        <div>
            <label class="block text-sm font-bold text-slate-300 mb-2">كلمة المرور</label>
            <input type="password" name="password" required
                   class="input-gem">
        </div>

        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm font-semibold text-slate-400 cursor-pointer">
                <input type="checkbox" name="remember" class="accent-amethyst w-4 h-4 rounded">
                تذكرني
            </label>
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="text-sm font-bold text-amethyst hover:text-white transition">
                    نسيت كلمة المرور؟
                </a>
            @endif
        </div>

        <button type="submit" class="btn-gem w-full justify-center !mt-6">
            دخول
        </button>
    </form>

    <p class="text-center text-sm text-slate-400 mt-6">
        ليس لديك حساب؟
        <a href="{{ route('register') }}" class="text-amethyst font-bold hover:text-white transition">إنشاء حساب جديد</a>
    </p>
@endsection
