@extends('layouts.app')

@section('title', 'الملف الشخصي')

@section('content')
    <div class="max-w-md mx-auto puzzle-card !p-6 md:!p-8 anim-fade-up">
        <div class="flex items-center gap-4 mb-6">
            <span class="gem-facet w-14 h-14 grid place-items-center text-lg font-black text-white bg-gradient-to-br from-amethyst to-gold shrink-0">
                {{ mb_substr($user->name, 0, 1) }}
            </span>
            <div class="min-w-0">
                <h1 class="font-display font-black text-xl text-white truncate">{{ $user->name }}</h1>
                <span class="text-xs text-slate-500 font-semibold">عضو منذ {{ $user->created_at->format('Y-m-d') }}</span>
            </div>
        </div>

        <form method="POST" action="{{ route('profile.update') }}" class="space-y-4">
            @csrf
            @method('PATCH')

            <div>
                <label class="block text-sm font-bold text-slate-300 mb-2">الاسم</label>
                <input type="text" name="name" value="{{ old('name', $user->name) }}" required
                       class="input-gem">
                @error('name')
                    <span class="text-xs text-rose font-semibold mt-1.5 block">{{ $message }}</span>
                @enderror
            </div>
            <div>
                <label class="block text-sm font-bold text-slate-300 mb-2">البريد الإلكتروني</label>
                <input type="email" name="email" value="{{ old('email', $user->email) }}" required
                       class="input-gem">
                @error('email')
                    <span class="text-xs text-rose font-semibold mt-1.5 block">{{ $message }}</span>
                @enderror
                @unless ($user->hasVerifiedEmail())
                    <span class="text-xs text-gold font-semibold mt-1.5 block">
                        بريدك غير موثّق بعد. تغييره سيتطلب إعادة التوثيق.
                    </span>
                @endunless
            </div>

            <button type="submit" class="btn-gem w-full justify-center !mt-6">
                حفظ التعديلات
            </button>
        </form>
    </div>
@endsection