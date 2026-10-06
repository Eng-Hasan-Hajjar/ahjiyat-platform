@extends('layouts.app')

@section('title', $event->title)

@section('content')
    @php $elapsedMs = max(0, (int) now()->getPreciseTimestamp(3) - $startedMs); @endphp
    <div class="max-w-3xl mx-auto">
        <div class="glass rounded-3xl p-6 md:p-9 anim-fade-up">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-5">
                <span class="chip !py-1 !px-3 text-xs">{{ $event->title }}</span>
                <span class="chip !py-1 !px-3 text-xs" x-data="{ base: {{ $elapsedMs }}, t0: Date.now(), s: 0 }" x-init="setInterval(() => s = Math.floor((base + Date.now() - t0) / 1000), 500)" aria-live="off">
                    ⏱ <span x-text="s">0</span> ث <span class="text-slate-500">(للعرض فقط)</span>
                </span>
            </div>

            <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-4 leading-tight">{{ $puzzle->prompt }}</h1>

            @if ($puzzle->image_path)
                <div class="rounded-2xl overflow-hidden border border-white/10 mb-6">
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($puzzle->image_path) }}" alt="{{ $puzzle->title }}" class="w-full h-auto">
                </div>
            @endif

            <form method="POST" action="{{ route('competitions.submit', $event) }}" class="mt-6">
                @csrf
                @include($renderer)

                <p class="text-xs text-amber-400 mb-3">محاولة واحدة فقط: بعد الإرسال تُسجَّل نتيجتك ولا يمكن إعادتها.</p>
                <button type="submit" class="btn-gem !py-3 !px-6">أرسل إجابتي</button>
            </form>
        </div>
    </div>
@endsection
