@extends('layouts.guest')

@section('title', 'تأكيد كلمة المرور')

@section('content')
    <h1 class="font-display font-black text-xl text-white mb-3 text-center">تأكيد كلمة المرور</h1>
    <p class="text-sm text-slate-400 text-center mb-6 leading-relaxed">
        هذه منطقة آمنة من النظام. الرجاء إدخال كلمة المرور للمتابعة.
    </p>

    <form method="POST" action="{{ route('password.confirm') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-bold text-slate-300 mb-2">كلمة المرور</label>
            <input type="password" name="password" required autocomplete="current-password" class="input-gem">
            @error('password')
                <span class="text-xs text-rose font-semibold mt-1.5 block">{{ $message }}</span>
            @enderror
        </div>

        <button type="submit" class="btn-gem w-full justify-center !mt-6">
            تأكيد
        </button>
    </form>
@endsection
