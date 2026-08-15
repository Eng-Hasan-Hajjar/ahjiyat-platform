@extends('layouts.guest')

@section('title', 'إنشاء حساب')

@section('content')
    <h1 class="font-display font-bold text-lg text-ink mb-4 text-center">إنشاء حساب جديد</h1>

    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-ink/70 mb-1">الاسم</label>
            <input type="text" name="name" value="{{ old('name') }}" required autofocus
                   class="w-full rounded-lg border border-ink/20 px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-amethyst">
        </div>
        <div>
            <label class="block text-sm font-medium text-ink/70 mb-1">البريد الإلكتروني</label>
            <input type="email" name="email" value="{{ old('email') }}" required
                   class="w-full rounded-lg border border-ink/20 px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-amethyst">
        </div>
        <div>
            <label class="block text-sm font-medium text-ink/70 mb-1">كلمة المرور</label>
            <input type="password" name="password" required
                   class="w-full rounded-lg border border-ink/20 px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-amethyst">
        </div>
        <div>
            <label class="block text-sm font-medium text-ink/70 mb-1">تأكيد كلمة المرور</label>
            <input type="password" name="password_confirmation" required
                   class="w-full rounded-lg border border-ink/20 px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-amethyst">
        </div>
        <button type="submit" class="w-full bg-amethyst text-white font-bold py-2.5 rounded-lg hover:bg-amethyst-700 transition">
            إنشاء الحساب
        </button>
    </form>

    <p class="text-center text-sm text-ink/60 mt-4">
        لديك حساب بالفعل؟ <a href="{{ route('login') }}" class="text-amethyst font-medium">تسجيل الدخول</a>
    </p>
@endsection
