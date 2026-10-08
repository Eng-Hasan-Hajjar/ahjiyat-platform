@extends('layouts.app')

@section('title', 'محفظتي')

@section('content')

    <x-page-header title="محفظتي" subtitle="رصيدك داخل المنصة وسجل معاملاتك. الرصيد افتراضي وليس حسابًا مصرفيًا." icon="wallet" class="anim-fade-up">
        <x-slot:actions>
            <a href="{{ route('redemption.index') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="gift" class="w-4 h-4" />طلبات الاستبدال</a>
            <a href="{{ route('store.index') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="store" class="w-4 h-4" />المتجر</a>
        </x-slot:actions>
    </x-page-header>

    @php
        $standard = $wallets->first(fn ($w) => $w->currency->type === \App\Models\Currency::TYPE_STANDARD);
        $others = $wallets->reject(fn ($w) => $w->currency->type === \App\Models\Currency::TYPE_STANDARD);
    @endphp

    @if ($standard)
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6 anim-fade-up d-1" data-wallet="standard">
            <x-stat-card :label="'الرصيد المتاح - '.$standard->currency->name" :value="number_format($standard->available_balance)" icon="wallet" tone="emerald" />
            <x-stat-card label="الرصيد المعلّق" :value="number_format($standard->pending_balance)" icon="calendar" tone="gold" hint="يصبح متاحًا للاستبدال بعد فترة التعليق" />
            <x-stat-card label="إجمالي ما تم كسبه" :value="number_format($standard->lifetime_earned)" icon="chart" tone="amethyst" />
        </div>
    @endif

    @if ($others->isNotEmpty())
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-8 anim-fade-up d-1" data-wallet="others">
            @foreach ($others as $wallet)
                <x-stat-card :label="$wallet->currency->name" :value="number_format($wallet->available_balance)" icon="sparkles"
                    :hint="collect([
                        $wallet->pending_balance > 0 ? '+ '.number_format($wallet->pending_balance).' معلَّق' : null,
                        $wallet->currency->expires_at ? 'تنتهي '.$wallet->currency->expires_at->format('Y-m-d') : null,
                    ])->filter()->implode(' · ') ?: null" />
            @endforeach
        </div>
    @endif

    <x-section-header title="سجل المعاملات" class="anim-fade-up d-2" />

    @if ($transactions->isEmpty())
        <x-empty-state icon="wallet" title="لا توجد معاملات بعد" message="ابدأ بحل الأحجيات لتكسب جواهرك الأولى." :action="route('puzzles.index')" actionLabel="تصفّح الأحجيات" class="anim-fade-up d-3" />
    @else
        <div class="glass rounded-2xl divide-y divide-white/5 anim-fade-up d-3" data-wallet="transactions">
            @foreach ($transactions as $transaction)
                @php $reason = \App\Support\WalletReasonLabel::describe($transaction->reason); @endphp
                <div class="flex items-center justify-between gap-3 px-4 md:px-5 py-4">
                    <div class="min-w-0">
                        <span class="block text-sm font-bold text-white truncate" data-tx-label>{{ $reason['label'] }}</span>
                        <span class="text-xs text-slate-500">{{ $transaction->currency->name ?? '' }} · {{ $transaction->created_at->format('Y-m-d H:i') }}</span>
                        @if ($reason['known'] && $reason['raw'] !== '')
                            <span class="block text-[11px] text-slate-600 truncate" dir="ltr" data-tx-raw>{{ $reason['raw'] }}</span>
                        @endif
                    </div>
                    <span class="shrink-0 font-display font-black text-sm md:text-base {{ $transaction->amount >= 0 ? 'text-emerald' : 'text-rose' }}" dir="ltr">
                        {{ $transaction->amount >= 0 ? '+' : '' }}{{ number_format($transaction->amount) }}
                    </span>
                </div>
            @endforeach
        </div>
    @endif

    <div class="mt-6">{{ $transactions->links() }}</div>

@endsection
