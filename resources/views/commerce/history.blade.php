@extends('layouts.app')

@php
    $tones = [
        'verified' => 'border-emerald/30 bg-emerald/10 text-emerald',
        'refunded' => 'border-rose/30 bg-rose/10 text-rose',
        'rejected' => 'border-rose/30 bg-rose/10 text-rose',
        'cancelled' => 'border-rose/30 bg-rose/10 text-rose',
        'expired' => 'border-rose/30 bg-rose/10 text-rose',
        'failed' => 'border-rose/30 bg-rose/10 text-rose',
    ];
@endphp

@section('title', 'مشترياتي')
@section('meta_description', 'سجل مشترياتك الرقمية وإيصالاتها.')

@section('content')
    <x-page-header title="مشترياتي" subtitle="سجل مشترياتك الرقمية" icon="sparkles" class="anim-fade-up" :back="route('pass.index')" back-label="العودة للمسار" />

    <section class="glass rounded-2xl p-3 sm:p-5 mb-6 anim-fade-up d-1" data-commerce-history>
        @forelse ($purchases as $purchase)
            @php
                $phase = $presenter->phase($purchase);
                $copy = $presenter->copy($purchase);
                $paid = in_array($purchase->status, ['verified', 'refunded'], true);
            @endphp
            <article class="flex flex-col gap-2 border-b border-white/5 py-3 last:border-0 sm:flex-row sm:items-center sm:justify-between" data-commerce-row="{{ $purchase->reference_code }}">
                <div class="min-w-0">
                    <p class="font-bold text-white truncate">{{ $purchase->product_name }}</p>
                    <p class="text-xs text-slate-400"><span class="font-mono" dir="ltr">{{ $purchase->reference_code }}</span> · {{ $presenter->providerLabel($purchase) }} · {{ $purchase->created_at->format('Y-m-d H:i') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <span class="font-display font-black text-white" dir="ltr">{{ $presenter->amountLabel($purchase) }}</span>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-black {{ $tones[$phase] ?? 'border-gold/30 bg-gold/10 text-gold' }}">{{ $copy['label'] }}</span>
                    @if ($paid)
                        <a href="{{ route('commerce.receipts.show', $purchase->public_id) }}" class="text-xs font-bold text-amethyst underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-commerce-receipt-link>الإيصال</a>
                    @elseif ($purchase->product_type === \App\Models\DigitalPurchase::PRODUCT_PASS_PREMIUM)
                        <a href="{{ route('pass.orders.show', $purchase->public_id) }}" class="text-xs font-bold text-amethyst underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">التفاصيل</a>
                    @endif
                </div>
            </article>
        @empty
            <p class="text-sm text-slate-400 py-6 text-center" data-commerce-empty>لا توجد مشتريات بعد.</p>
        @endforelse

        @if ($purchases->hasPages())
            <div class="mt-4 flex justify-between text-sm">
                @if ($purchases->previousPageUrl())<a href="{{ $purchases->previousPageUrl() }}" class="font-bold text-amethyst">السابق</a>@else<span></span>@endif
                @if ($purchases->nextPageUrl())<a href="{{ $purchases->nextPageUrl() }}" class="font-bold text-amethyst">التالي</a>@endif
            </div>
        @endif
    </section>
@endsection
