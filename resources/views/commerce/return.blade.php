@extends('layouts.app')

@php
    /** @var \App\Models\DigitalPurchase $purchase */
    $tone = match ($phase) {
        'verified' => 'border-emerald/30 bg-emerald/10 text-emerald',
        'failed', 'rejected', 'cancelled', 'expired', 'refunded' => 'border-rose/30 bg-rose/10 text-rose',
        default => 'border-gold/30 bg-gold/10 text-gold',
    };
@endphp

@section('title', 'حالة الدفع')
@section('meta_description', 'حالة عملية الدفع الخاصة بك.')

@section('content')
    <x-page-header title="حالة الدفع" :subtitle="$purchase->product_name" icon="sparkles" class="anim-fade-up" :back="$ordersUrl" back-label="العودة للطلب" />

    {{-- E41: صفحة العودة من المزوّد: عرض فقط. كل معاملات الاستعلام تُتجاهَل؛ الحالة هنا هي الحالة الموثوقة في القاعدة، والتأكيد الفعلي يأتي من المزوّد (webhook) لا من هذه الصفحة. --}}
    <section class="glass rounded-2xl p-5 mb-6 anim-fade-up d-1" data-commerce-return
        x-data="commerceStatus(@js($poll), @js($phase))" x-init="start()">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <span class="inline-flex items-center rounded-full border px-3 py-1 text-sm font-black {{ $tone }}" data-commerce-label x-text="label" aria-live="polite">{{ $copy['label'] }}</span>
            <span class="font-display font-black text-2xl text-white" dir="ltr">{{ $amount }}</span>
        </div>
        <p class="text-sm text-slate-300" data-commerce-message x-text="message" aria-live="polite">{{ $copy['message'] }}</p>
        <dl class="grid grid-cols-2 gap-3 text-sm mt-4">
            <div><dt class="text-xs text-slate-400">مرجع الطلب</dt><dd class="font-mono font-black text-white tracking-wider select-all" dir="ltr" data-commerce-reference>{{ $purchase->reference_code }}</dd></div>
            <div><dt class="text-xs text-slate-400">طريقة الدفع</dt><dd class="text-slate-200">{{ $providerLabel }}</dd></div>
        </dl>
        <div class="mt-4 flex flex-wrap gap-3">
            @if ($phase === 'verified' && $purchase->product_type === \App\Models\DigitalPurchase::PRODUCT_CREATOR_SERIES)
                <a href="{{ route('series.show', $purchase->product_public_id) }}" class="btn-gem !py-2.5 !px-6 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-commerce-go>افتح السلسلة</a>
            @elseif ($phase === 'verified')
                <a href="{{ route('pass.index') }}" class="btn-gem !py-2.5 !px-6 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-commerce-go>اذهب للمسار</a>
            @endif
            <a href="{{ route('commerce.purchases.index') }}" class="text-xs font-bold text-amethyst underline self-center rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">مشترياتي</a>
        </div>
        <noscript><p class="mt-3 text-xs text-slate-400">حدّث الصفحة بعد لحظات لمعرفة آخر حالة.</p></noscript>
    </section>

    <script>
        // استطلاع محدود: فاصل أدنى، تباعد تصاعدي حتى 30 ثانية، وسقف محاولات؛ ويتوقف عند حالة نهائية. قراءة فقط ولا يؤكد شيئًا.
        function commerceStatus(poll, phase) {
            return {
                label: @js($copy['label']), message: @js($copy['message']), phase, attempts: 0, timer: null,
                start() { if (['verified','refunded','rejected','cancelled','expired','review','failed'].includes(this.phase)) return; this.schedule(poll.min); },
                schedule(seconds) { this.timer = setTimeout(() => this.tick(), seconds * 1000); },
                async tick() {
                    this.attempts++;
                    try {
                        const r = await fetch(poll.url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' });
                        if (r.ok) {
                            const d = await r.json();
                            this.label = d.label; this.message = d.message; this.phase = d.phase;
                            if (d.final) { if (d.verified) { window.location.reload(); } return; }
                        }
                    } catch (e) { /* شبكة: نعيد لاحقًا ضمن السقف */ }
                    if (this.attempts < poll.max) { this.schedule(Math.min(30, poll.min * Math.pow(1.5, this.attempts))); }
                },
            };
        }
    </script>
@endsection
