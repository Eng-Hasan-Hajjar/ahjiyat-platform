@extends('layouts.app')

@section('title', 'الملف الشخصي')

@section('content')
    <div class="max-w-md mx-auto bg-white border border-ink/10 rounded-2xl p-6">
        <h1 class="font-display font-bold text-xl text-ink mb-4">الملف الشخصي</h1>

        <form method="POST" action="{{ route('profile.update') }}" class="space-y-4">
            @csrf
            @method('PATCH')

            <div>
                <label class="block text-sm font-medium text-ink/70 mb-1">الاسم</label>
                <input type="text" name="name" value="{{ old('name', $user->name) }}" required
                       class="w-full rounded-lg border border-ink/20 px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-amethyst">
            </div>
            <div>
                <label class="block text-sm font-medium text-ink/70 mb-1">البريد الإلكتروني</label>
                <input type="email" name="email" value="{{ old('email', $user->email) }}" required
                       class="w-full rounded-lg border border-ink/20 px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-amethyst">
            </div>

            <button type="submit" class="w-full bg-amethyst text-white font-bold py-2.5 rounded-lg hover:bg-amethyst-700 transition">
                حفظ التعديلات
            </button>
        </form>
    </div>
@endsection
