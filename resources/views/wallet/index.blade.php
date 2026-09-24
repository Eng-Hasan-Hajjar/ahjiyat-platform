@extends('layouts.app')

@section('title', 'محفظتي')

@section('content')

    <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-6 anim-fade-up">محفظتي</h1>

    @php
        $standard = $wallets->first(fn ($w) => $w->currency->type === \App\Models\Currency::TYPE_STANDARD);
        $others = $wallets->reject(fn ($w) => $w->currency->type === \App\Models\Currency::TYPE_STANDARD);
    @endphp

    @if ($standard)
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="puzzle-card anim-fade-up d-1">
                <span class="text-xs font-bold text-slate-400 block mb-2">الرصيد المتاح - {{ $standard->currency->name }}</span>
                <span class="font-display font-black text-3xl text-emerald flex items-center gap-2">
                    {{ number_format($standard->available_balance) }}
                    <span class="w-4 h-4 bg-emerald gem-facet inline-block"></span>
                </span>
            </div>
            <div class="puzzle-card anim-fade-up d-2">
                <span class="text-xs font-bold text-slate-400 block mb-2">الرصيد المعلّق</span>
                <span class="font-display font-black text-3xl text-gold flex items-center gap-2">
                    {{ number_format($standard->pending_balance) }}
                    <span class="w-4 h-4 bg-gold gem-facet inline-block"></span>
                </span>
                <span class="text-[11px] text-slate-500 block mt-2">يصبح متاحًا للاستبدال بعد فترة التعليق</span>
            </div>
            <div class="puzzle-card anim-fade-up d-3">
                <span class="text-xs font-bold text-slate-400 block mb-2">إجمالي ما تم كسبه</span>
                <span class="font-display font-black text-3xl text-white flex items-center gap-2">
                    {{ number_format($standard->lifetime_earned) }}
                    <span class="w-4 h-4 bg-amethyst gem-facet inline-block"></span>
                </span>
            </div>
        </div>
    @endif

    @if ($others->isNotEmpty())
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-10">
            @foreach ($others as $wallet)
                <div class="puzzle-card anim-fade-up">
                    <span class="text-xs font-bold text-slate-400 block mb-2">{{ $wallet->currency->name }}</span>
                    <span class="font-display font-black text-2xl text-white flex items-center gap-2">
                        {{ number_format($wallet->available_balance) }}
                    </span>
                    @if ($wallet->pending_balance > 0)
                        <span class="text-[11px] text-slate-500 block mt-1">+ {{ number_format($wallet->pending_balance) }} معلَّق</span>
                    @endif
                    @if ($wallet->currency->expires_at)
                        <span class="text-[11px] text-amber-400 block mt-1">تنتهي {{ $wallet->currency->expires_at->format('Y-m-d') }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <h2 class="font-display font-black text-lg md:text-xl text-white mb-4 anim-fade-up d-2">سجل المعاملات</h2>

    <div class="glass rounded-2xl divide-y divide-white/5 anim-fade-up d-3">
        @forelse ($transactions as $transaction)
            <div class="flex items-center justify-between gap-3 px-4 md:px-5 py-4">
                <div class="min-w-0">
                    <span class="block text-sm font-bold text-white truncate">{{ $transaction->reason }}</span>
                    <span class="text-xs text-slate-500">{{ $transaction->currency->name ?? '' }} · {{ $transaction->created_at->format('Y-m-d H:i') }}</span>
                </div>
                <span class="shrink-0 font-display font-black text-sm md:text-base {{ $transaction->amount >= 0 ? 'text-emerald' : 'text-rose' }}">
                    {{ $transaction->amount >= 0 ? '+' : '' }}{{ number_format($transaction->amount) }}
                </span>
            </div>
        @empty
            <p class="px-4 py-10 text-center text-slate-500 text-sm">لا توجد معاملات بعد. ابدأ بحل الأحجيات لتكسب جواهرك الأولى 💎</p>
        @endforelse
    </div>

    <div class="mt-6">{{ $transactions->links() }}</div>

@endsection