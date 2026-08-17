@extends('layouts.guest')

@section('title', 'استعادة كلمة المرور')

@section('content')
    <h1 class="font-display font-black text-xl text-white mb-3 text-center">استعادة كلمة المرور</h1>
    <p class="text-sm text-slate-400 text-center mb-6 leading-relaxed">
        أدخل بريدك الإلكتروني وسنرسل لك رابط إعادة تعيين كلمة المرور.
    </p>

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-bold text-slate-300 mb-2">البريد الإلكتروني</label>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus
                   class="input-gem">
            @error('email')
                <span class="text-xs text-rose font-semibold mt-1.5 block">{{ $message }}</span>
            @enderror
        </div>

        <button type="submit" class="btn-gem w-full justify-center !mt-6">
            إرسال رابط الاستعادة
        </button>
    </form>

    <p class="text-center text-sm text-slate-400 mt-6">
        <a href="{{ route('login') }}" class="text-amethyst font-bold hover:text-white transition">← رجوع لتسجيل الدخول</a>
    </p>
@endsection
