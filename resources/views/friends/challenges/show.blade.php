@extends('layouts.app')

@section('title', 'تحدٍّ مع '.$other->name)

@section('content')
    @php
        $labels = ['pending' => 'قيد الانتظار', 'accepted' => 'نشط', 'completed' => 'مكتمل', 'declined' => 'مرفوض', 'cancelled' => 'مُلغى', 'expired' => 'منتهٍ'];
        $outcome = $status === 'completed' ? ($challenge->is_draw ? 'تعادل' : ($challenge->winner_user_id === $me->id ? 'فوز' : 'خسارة')) : null;
        $elapsedMs = $run ? max(0, (int) now()->getPreciseTimestamp(3) - $startedMs) : 0;
    @endphp
    <div class="max-w-3xl mx-auto space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3 anim-fade-up">
            <h1 class="font-display font-black text-2xl text-white">تحدٍّ مع {{ $other->name }}</h1>
            <a href="{{ route('friends.challenges.index') }}" class="chip">← التحدّيات</a>
        </div>

        <div class="glass rounded-3xl p-6 anim-fade-up space-y-4">
            <x-friend-row :user="$other" class="!px-0 !py-0" />
            <dl class="grid gap-3 sm:grid-cols-3 text-sm">
                <div><dt class="text-slate-500">الأحجية</dt><dd class="text-white font-bold">{{ $challenge->puzzle->title }}</dd></div>
                <div><dt class="text-slate-500">الحالة</dt><dd class="text-white font-bold">{{ $labels[$status] ?? $status }}</dd></div>
                <div><dt class="text-slate-500">@if ($status === 'pending') ينتهي القبول @elseif ($status === 'accepted') تنتهي المهلة @else التاريخ @endif</dt>
                    <dd class="text-white font-bold">{{ ($status === 'completed' ? $challenge->completed_at : (in_array($status, ['pending', 'accepted']) ? $challenge->expires_at : $challenge->updated_at))->format('Y-m-d H:i') }}</dd></div>
            </dl>

            @if ($status === 'completed')
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-center">
                    <div class="font-display font-black text-2xl {{ $outcome === 'فوز' ? 'text-emerald-400' : ($outcome === 'خسارة' ? 'text-rose-400' : 'text-slate-200') }}">{{ $outcome }}</div>
                    <div class="text-sm text-slate-300 mt-1">نقاطك <strong class="text-white">{{ $mine?->score }}</strong> ({{ number_format(($mine?->duration_ms ?? 0) / 1000, 2) }} ث)
                        · نقاط {{ $other->name }} <strong class="text-white">{{ $theirs?->score }}</strong> ({{ number_format(($theirs?->duration_ms ?? 0) / 1000, 2) }} ث)</div>
                </div>
            @elseif ($status === 'pending' && ! $isChallenger)
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('friends.challenges.accept', $challenge) }}">@csrf <button type="submit" class="btn-gem !py-2 !px-5 text-sm" aria-label="قبول التحدي">قبول التحدي</button></form>
                    <form method="POST" action="{{ route('friends.challenges.decline', $challenge) }}">@csrf <button type="submit" class="chip text-sm" aria-label="رفض التحدي">رفض</button></form>
                </div>
            @elseif ($status === 'pending')
                <div class="flex flex-wrap items-center gap-3">
                    <span class="chip">بانتظار قبول {{ $other->name }}</span>
                    <form method="POST" action="{{ route('friends.challenges.cancel', $challenge) }}">@csrf @method('DELETE') <button type="submit" class="chip text-xs" aria-label="إلغاء التحدي">إلغاء التحدي</button></form>
                </div>
            @elseif ($status === 'accepted' && $mine)
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">
                    سُجّلت نتيجتك: <strong class="text-white">{{ $mine->score }}</strong> نقطة ({{ number_format($mine->duration_ms / 1000, 2) }} ث). نتيجة {{ $other->name }} تظهر عند اكتمال الطرفين.
                </div>
            @elseif ($status === 'accepted' && ! $run)
                <form method="POST" action="{{ route('friends.challenges.start', $challenge) }}">@csrf
                    <button type="submit" class="btn-gem !py-2.5 !px-5 text-sm" aria-label="بدء المحاولة الوحيدة">ابدأ محاولتك (لا يمكن إعادتها)</button>
                </form>
            @elseif (in_array($status, ['expired', 'declined', 'cancelled']))
                <p class="text-sm text-slate-400">انتهى هذا التحدي دون نتيجة نهائية.</p>
            @endif
        </div>

        @if ($run)
            <div class="glass rounded-3xl p-6 md:p-9 anim-fade-up">
                <div class="flex justify-end mb-3">
                    <span class="chip !py-1 !px-3 text-xs" x-data="{ base: {{ $elapsedMs }}, t0: Date.now(), s: 0 }" x-init="setInterval(() => s = Math.floor((base + Date.now() - t0) / 1000), 500)">
                        ⏱ <span x-text="s">0</span> ث <span class="text-slate-500">(للعرض فقط)</span>
                    </span>
                </div>
                <h2 class="font-display font-black text-2xl text-white mb-4 leading-tight">{{ $puzzle->prompt }}</h2>
                @if ($puzzle->image_path)
                    <div class="rounded-2xl overflow-hidden border border-white/10 mb-6">
                        <img src="{{ \Illuminate\Support\Facades\Storage::url($puzzle->image_path) }}" alt="{{ $puzzle->title }}" class="w-full h-auto">
                    </div>
                @endif
                <form method="POST" action="{{ route('friends.challenges.submit', $challenge) }}" class="mt-6">
                    @csrf
                    @include($renderer)
                    <p class="text-xs text-amber-400 mb-3">محاولة واحدة فقط: بعد الإرسال تُسجَّل نتيجتك ولا يمكن إعادتها.</p>
                    <button type="submit" class="btn-gem !py-3 !px-6">أرسل إجابتي</button>
                </form>
            </div>
        @endif
    </div>
@endsection
