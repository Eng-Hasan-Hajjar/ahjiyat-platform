@extends('layouts.app')

@section('title', 'لوحة الصدارة')

@section('content')
    <h1 class="font-display font-bold text-2xl text-ink mb-6">لوحة الصدارة</h1>

    <div class="bg-white border border-ink/10 rounded-xl divide-y divide-ink/5">
        @forelse ($topUsers as $index => $user)
            <div class="flex items-center gap-4 px-4 py-3">
                <span class="w-7 h-7 rounded-full flex items-center justify-center text-sm font-bold
                    {{ $index === 0 ? 'bg-gold text-ink' : ($index < 3 ? 'bg-amethyst-50 text-amethyst-700' : 'bg-ink/5 text-ink/50') }}">
                    {{ $index + 1 }}
                </span>
                <span class="flex-1 font-medium text-ink">{{ $user->name }}</span>
                <span class="text-sm text-ink/50">{{ $user->solved_count }} أحجية محلولة</span>
            </div>
        @empty
            <p class="px-4 py-6 text-center text-ink/50 text-sm">لا توجد بيانات كافية بعد.</p>
        @endforelse
    </div>
@endsection
