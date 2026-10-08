@extends('layouts.guest')

@section('title', 'إنشاء حساب')

@section('content')
    <h1 class="font-display font-black text-xl text-white mb-6 text-center">إنشاء حساب جديد</h1>

    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf
        <div>
            <label for="name" class="block text-sm font-bold text-slate-300 mb-2">الاسم</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus
                   class="input-gem">
            @error('name')
                <span class="text-xs text-rose font-semibold mt-1.5 block">{{ $message }}</span>
            @enderror
        </div>
        <div>
            <label for="email" class="block text-sm font-bold text-slate-300 mb-2">البريد الإلكتروني</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required
                   class="input-gem">
            @error('email')
                <span class="text-xs text-rose font-semibold mt-1.5 block">{{ $message }}</span>
            @enderror
        </div>
        <div>
            <label for="password" class="block text-sm font-bold text-slate-300 mb-2">كلمة المرور</label>
            <input id="password" type="password" name="password" required
                   class="input-gem">
            @error('password')
                <span class="text-xs text-rose font-semibold mt-1.5 block">{{ $message }}</span>
            @enderror
        </div>
        <div>
            <label for="password_confirmation" class="block text-sm font-bold text-slate-300 mb-2">تأكيد كلمة المرور</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required
                   class="input-gem">
        </div>
        <button type="submit" class="btn-gem w-full justify-center !mt-6">
            إنشاء الحساب
        </button>
    </form>

    <p class="text-center text-sm text-slate-400 mt-6">
        لديك حساب بالفعل؟
        <a href="{{ route('login') }}" class="text-amethyst font-bold hover:text-white transition">تسجيل الدخول</a>
    </p>
@endsection
