@extends('layouts.guest')

@section('title', 'إعادة تعيين كلمة المرور')

@section('content')
    <h1 class="font-display font-black text-xl text-white mb-6 text-center">إعادة تعيين كلمة المرور</h1>

    <form method="POST" action="{{ route('password.store') }}" class="space-y-4">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div>
            <label class="block text-sm font-bold text-slate-300 mb-2">البريد الإلكتروني</label>
            <input type="email" name="email" value="{{ old('email', $request->email) }}" required autofocus
                   autocomplete="username" class="input-gem">
            @error('email')
                <span class="text-xs text-rose font-semibold mt-1.5 block">{{ $message }}</span>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-bold text-slate-300 mb-2">كلمة المرور الجديدة</label>
            <input type="password" name="password" required autocomplete="new-password" class="input-gem">
            @error('password')
                <span class="text-xs text-rose font-semibold mt-1.5 block">{{ $message }}</span>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-bold text-slate-300 mb-2">تأكيد كلمة المرور</label>
            <input type="password" name="password_confirmation" required autocomplete="new-password" class="input-gem">
        </div>

        <button type="submit" class="btn-gem w-full justify-center !mt-6">
            حفظ كلمة المرور
        </button>
    </form>
@endsection
