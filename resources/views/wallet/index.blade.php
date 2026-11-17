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
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3 anim-fade-up d-1" data-wallet="standard">
            <x-stat-card :label="'المتاح الآن - '.$standard->currency->name" :value="number_format($standard->available_balance)" icon="wallet" tone="emerald" hint="يمكنك استخدامه أو طلب استبداله" />
            <x-stat-card label="معلّق (ينتظر الإتاحة)" :value="number_format($standard->pending_balance)" icon="calendar" tone="gold" hint="يتحوّل إلى متاح تلقائيًا بعد فترة التعليق" />
            <x-stat-card label="إجمالي ما كسبته" :value="number_format($standard->lifetime_earned)" icon="chart" tone="amethyst" />
        </div>
        <p class="text-xs text-slate-400 mb-6 anim-fade-up d-1" data-wallet="explain">
            <span class="font-bold text-slate-300">المتاح</span> هو ما يمكنك استخدامه الآن.
            <span class="font-bold text-slate-300">المعلّق</span> جواهر كسبتها وتنتظر انتهاء فترة التعليق، ثم تنتقل وحدها إلى المتاح ولا تضيع.
        </p>
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

    @php
        $selectClass = 'w-full rounded-xl bg-white/5 border border-white/10 px-3 py-2.5 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst';
        $showCurrencyFilter = $wallets->count() > 1;
    @endphp

    {{-- E46: فلتر GET بسيط (بلا JavaScript): العملة (إن وُجدت أكثر من عملة) + نوع الحركة. لا يكتب شيئًا. --}}
    <form method="GET" action="{{ route('wallet.index') }}" class="glass rounded-2xl p-4 mb-4 grid grid-cols-1 sm:grid-cols-3 gap-3 items-end anim-fade-up d-2" data-wallet="filters">
        @if ($showCurrencyFilter)
            <div class="min-w-0">
                <label for="wallet-currency" class="block text-xs text-slate-400 mb-1">العملة</label>
                <select id="wallet-currency" name="currency" class="{{ $selectClass }}">
                    <option value="">كل العملات</option>
                    @foreach ($wallets as $wallet)
                        <option value="{{ $wallet->currency_id }}" @selected($filters['currency'] === (int) $wallet->currency_id)>{{ $wallet->currency->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="min-w-0 {{ $showCurrencyFilter ? '' : 'sm:col-span-2' }}">
            <label for="wallet-kind" class="block text-xs text-slate-400 mb-1">نوع الحركة</label>
            <select id="wallet-kind" name="kind" class="{{ $selectClass }}">
                <option value="">كل الحركات</option>
                @foreach ($kinds as $value => $label)
                    <option value="{{ $value }}" @selected($filters['kind'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="btn-gem !py-2.5 !px-5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">تطبيق</button>
            @if ($hasFilters)
                <a href="{{ route('wallet.index') }}" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-wallet="clear-filters">إلغاء التصفية</a>
            @endif
        </div>
    </form>

    @if ($transactions->isEmpty() && ! $hasFilters)
        <x-empty-state icon="wallet" title="لا توجد معاملات بعد" message="ابدأ بحل الأحجيات لتكسب جواهرك الأولى." :action="route('puzzles.index')" actionLabel="تصفّح الأحجيات" class="anim-fade-up d-3" />
    @elseif ($transactions->isEmpty())
        <div class="glass rounded-2xl px-6 py-8 text-center anim-fade-up d-3" data-wallet="no-match">
            <p class="text-sm text-slate-300">لا توجد حركات تطابق هذه التصفية.</p>
            <a href="{{ route('wallet.index') }}" class="chip mt-3 inline-flex focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">عرض كل الحركات</a>
        </div>
    @else
        <ul class="glass rounded-2xl divide-y divide-white/5 anim-fade-up d-3" data-wallet="transactions">
            @foreach ($transactions as $transaction)
                @php $m = \App\Support\WalletMovement::describe($transaction); @endphp
                <li class="px-4 md:px-5 py-4" data-tx data-tx-kind="{{ $m['kind'] }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <span class="block text-sm font-bold text-white break-words" data-tx-label>{{ $m['label'] }}</span>
                            <span class="mt-1 inline-flex items-center gap-1 rounded-full bg-white/5 px-2 py-0.5 text-[11px] font-bold {{ $m['tone'] }}" data-tx-kind-label>
                                <x-ui-icon :name="$m['icon']" class="w-3.5 h-3.5" />{{ $m['kind_label'] }}
                            </span>
                        </div>
                        <span class="shrink-0 font-display font-black text-base {{ $m['tone'] }}" dir="ltr" data-tx-amount>{{ $m['amount_text'] }}</span>
                    </div>
                    <p class="mt-1.5 text-xs text-slate-300 break-words" data-tx-effect>{{ $m['effect'] }}</p>
                    <p class="mt-0.5 text-[11px] text-slate-500">{{ $transaction->currency->name ?? '' }} · <span dir="ltr">{{ $transaction->created_at->format('Y-m-d H:i') }}</span></p>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="mt-6">{{ $transactions->links() }}</div>

@endsection
