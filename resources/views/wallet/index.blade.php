@extends('layouts.app')

@section('title', 'محفظتي')

@section('content')

    <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-6 anim-fade-up">محفظتي</h1>

    {{-- ===== بطاقات الرصيد ===== --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-10">
        <div class="puzzle-card anim-fade-up d-1">
            <span class="text-xs font-bold text-slate-400 block mb-2">الرصيد المتاح</span>
            <span class="font-display font-black text-3xl text-emerald flex items-center gap-2">
                {{ number_format($wallet->available_balance ?? 0) }}
                <span class="w-4 h-4 bg-emerald gem-facet inline-block"></span>
            </span>
        </div>
        <div class="puzzle-card anim-fade-up d-2">
            <span class="text-xs font-bold text-slate-400 block mb-2">الرصيد المعلّق</span>
            <span class="font-display font-black text-3xl text-gold flex items-center gap-2">
                {{ number_format($wallet->pending_balance ?? 0) }}
                <span class="w-4 h-4 bg-gold gem-facet inline-block"></span>
            </span>
            <span class="text-[11px] text-slate-500 block mt-2">يصبح متاحًا للاستبدال بعد فترة التعليق</span>
        </div>
        <div class="puzzle-card anim-fade-up d-3">
            <span class="text-xs font-bold text-slate-400 block mb-2">إجمالي ما تم كسبه</span>
            <span class="font-display font-black text-3xl text-white flex items-center gap-2">
                {{ number_format($wallet->lifetime_earned ?? 0) }}
                <span class="w-4 h-4 bg-amethyst gem-facet inline-block"></span>
            </span>
        </div>
    </div>

    {{-- ===== سجل المعاملات ===== --}}
    <h2 class="font-display font-black text-lg md:text-xl text-white mb-4 anim-fade-up d-2">سجل المعاملات</h2>

    <div class="glass rounded-2xl divide-y divide-white/5 anim-fade-up d-3">
        @forelse ($transactions as $transaction)
            <div class="flex items-center justify-between gap-3 px-4 md:px-5 py-4">
                <div class="min-w-0">
                    <span class="block text-sm font-bold text-white truncate">{{ $transaction->reason }}</span>
                    <span class="text-xs text-slate-500">{{ $transaction->created_at->format('Y-m-d H:i') }}</span>
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