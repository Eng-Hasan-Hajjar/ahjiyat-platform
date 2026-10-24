@extends('layouts.app')

@php
    /** @var \App\Models\DigitalPurchase $purchase */
    use App\Models\DigitalPurchase as DP;
    $amount = \App\Services\Sponsorship\SponsorshipMoney::format((int) $purchase->amount_minor, $purchase->currency);
    $tone = match ($purchase->status) {
        DP::STATUS_VERIFIED => 'border-emerald/30 bg-emerald/10 text-emerald',
        DP::STATUS_REJECTED, DP::STATUS_CANCELLED, DP::STATUS_EXPIRED, DP::STATUS_REFUNDED => 'border-rose/30 bg-rose/10 text-rose',
        default => 'border-gold/30 bg-gold/10 text-gold',
    };
@endphp

@section('title', 'طلب المسار المميز')
@section('meta_description', 'تفاصيل طلب شراء المسار المميز وحالة المراجعة.')

@section('content')
    <x-page-header title="طلب المسار المميز" :subtitle="$purchase->product_name" icon="sparkles" class="anim-fade-up" :back="route('pass.index')" back-label="العودة للمسار" />

    <section class="glass rounded-2xl p-5 mb-6 anim-fade-up d-1" data-pass-order>
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <span class="inline-flex items-center rounded-full border px-3 py-1 text-sm font-black {{ $tone }}" data-order-status="{{ $purchase->status }}">{{ $purchase->statusLabel() }}</span>
            <span class="font-display font-black text-2xl text-white" dir="ltr">{{ $amount }}</span>
        </div>
        <dl class="grid grid-cols-2 gap-3 text-sm">
            <div><dt class="text-xs text-slate-400">مرجع الطلب</dt><dd class="font-mono font-black text-white tracking-wider select-all" dir="ltr" data-order-reference>{{ $purchase->reference_code }}</dd></div>
            <div><dt class="text-xs text-slate-400">أُنشئ في</dt><dd class="text-slate-200">{{ $purchase->created_at->format('Y-m-d H:i') }}</dd></div>
            @if ($purchase->status === DP::STATUS_AWAITING && $purchase->expires_at)
                <div><dt class="text-xs text-slate-400">ينتهي الطلب في</dt><dd class="text-slate-200">{{ $purchase->expires_at->format('Y-m-d H:i') }}</dd></div>
            @endif
            @if ($purchase->submitted_at)
                <div><dt class="text-xs text-slate-400">قُدِّم في</dt><dd class="text-slate-200">{{ $purchase->submitted_at->format('Y-m-d H:i') }}</dd></div>
            @endif
        </dl>

        @if ($purchase->status === DP::STATUS_REJECTED && $purchase->decision_message)
            <p class="mt-4 text-sm text-rose"><span class="font-bold">سبب الرفض:</span> {{ $purchase->decision_message }}</p>
        @endif
        @if ($purchase->status === DP::STATUS_VERIFIED)
            <p class="mt-4 text-sm text-emerald font-bold">تم تأكيد الدفع وفُتح المسار المميز. مكافآتك المميزة جاهزة للاستلام من صفحة المسار.</p>
            <a href="{{ route('pass.index') }}" class="btn-gem !py-2.5 !px-6 text-sm mt-3 inline-flex focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">اذهب للمسار</a>
        @endif
        @if ($purchase->status === DP::STATUS_SUBMITTED)
            <p class="mt-4 text-sm text-gold">استلمنا مرجع تحويلك وهو قيد المراجعة. لن يُفتح المسار المميز إلا بعد تأكيد الدفع.</p>
        @endif
        @if ($receiptUrl)
            <a href="{{ $receiptUrl }}" class="mt-3 inline-flex text-xs font-bold text-amethyst underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-order-receipt>عرض الإيصال</a>
        @endif
    </section>

    {{-- E41: دفع إلكتروني مستضاف. الحالة المعروضة موثوقة من الخادم فقط؛ لا «نجاح» يُعرض لمجرد العودة من صفحة المزوّد. --}}
    @if ($hosted)
        <section class="glass rounded-2xl p-5 mb-6 anim-fade-up d-2" data-pass-hosted data-order-phase="{{ $phase }}">
            <h2 class="font-display font-black text-lg text-white mb-1">{{ $copy['label'] }}</h2>
            <p class="text-sm text-slate-300">{{ $copy['message'] }}</p>
            <p class="mt-2 text-xs text-slate-500">طريقة الدفع: {{ $providerLabel }}.</p>
            @if ($canContinue)
                <form method="POST" action="{{ route('commerce.checkout.start', $purchase->public_id) }}" class="mt-4">
                    @csrf
                    <button type="submit" class="btn-gem !py-2.5 !px-6 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-order-continue>{{ $phase === 'failed' ? 'إعادة محاولة الدفع' : 'متابعة الدفع' }}</button>
                </form>
                <p class="mt-2 text-xs text-slate-500">ستنتقل إلى صفحة الدفع الخاصة بمزوّد الدفع. لا نطلب بيانات بطاقتك ولا نحفظها.</p>
            @elseif ($purchase->status === DP::STATUS_AWAITING && ! $purchase->needsReview())
                <p class="mt-3 text-xs text-slate-400">الدفع الإلكتروني غير متاح حاليًا. يمكنك إلغاء الطلب أو المحاولة لاحقًا.</p>
            @endif
            @if ($purchase->status === DP::STATUS_AWAITING && $purchase->provider_session_id)
                <a href="{{ route('commerce.checkout.return', $purchase->public_id) }}" class="mt-3 inline-flex text-xs font-bold text-amethyst underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-order-check-status>تحقق من حالة الدفع</a>
            @endif
        </section>
    @endif

    @if ($instructions && in_array($purchase->status, [DP::STATUS_AWAITING, DP::STATUS_SUBMITTED], true))
        <section class="glass rounded-2xl p-5 mb-6 anim-fade-up d-2" data-pass-instructions>
            <h2 class="font-display font-black text-lg text-white mb-2">{{ $instructions['title'] }}</h2>
            @if (! $instructions['configured'])
                <p class="text-sm text-slate-400">لم تُضبط بيانات التحويل بعد. تواصل مع الدعم قبل الدفع.</p>
            @else
                <dl class="grid gap-2 text-sm">
                    @if ($instructions['beneficiary'])<div><dt class="text-xs text-slate-400">المستفيد</dt><dd class="text-white font-bold">{{ $instructions['beneficiary'] }}</dd></div>@endif
                    @if ($instructions['bank_name'])<div><dt class="text-xs text-slate-400">البنك</dt><dd class="text-white">{{ $instructions['bank_name'] }}</dd></div>@endif
                    @if ($instructions['account'])<div><dt class="text-xs text-slate-400">الحساب</dt><dd class="font-mono text-white select-all" dir="ltr">{{ $instructions['account'] }}</dd></div>@endif
                    <div><dt class="text-xs text-slate-400">المبلغ بالضبط</dt><dd class="text-white font-bold" dir="ltr">{{ $amount }}</dd></div>
                </dl>
                <p class="mt-3 text-sm text-slate-300">اكتب مرجع الطلب <span class="font-mono font-black text-white" dir="ltr">{{ $purchase->reference_code }}</span> في خانة الملاحظة عند التحويل.</p>
                @if ($instructions['reference_note'])<p class="mt-2 text-xs text-slate-400">{{ $instructions['reference_note'] }}</p>@endif
                @if ($instructions['extra_note'])<p class="mt-2 text-xs text-slate-400">{{ $instructions['extra_note'] }}</p>@endif
            @endif
        </section>
    @endif

    @if ($purchase->status === DP::STATUS_AWAITING && ! $hosted)
        <section class="glass rounded-2xl p-5 mb-6 anim-fade-up d-3" data-pass-submit>
            <h2 class="font-display font-black text-lg text-white mb-3">بعد التحويل: أرسل تفاصيله</h2>
            <form method="POST" action="{{ route('pass.orders.submit', $purchase->public_id) }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div>
                    <label for="transfer_reference" class="block text-sm font-bold text-slate-200 mb-1">مرجع التحويل / رقم العملية</label>
                    <input id="transfer_reference" name="transfer_reference" type="text" maxlength="{{ (int) config('ahjiyat_pass.purchase.transfer_reference_max', 120) }}" value="{{ old('transfer_reference') }}" dir="ltr" autocomplete="off" class="w-full rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                    <p class="text-xs text-slate-500 mt-1">لا تُدخل أرقام بطاقات أو رموز تحقق أو كلمات مرور.</p>
                </div>
                <div>
                    <label for="payer_note" class="block text-sm font-bold text-slate-200 mb-1">ملاحظة (اختياري)</label>
                    <input id="payer_note" name="payer_note" type="text" maxlength="{{ (int) config('ahjiyat_pass.purchase.payer_note_max', 255) }}" value="{{ old('payer_note') }}" class="w-full rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                </div>
                <div>
                    <label for="proof" class="block text-sm font-bold text-slate-200 mb-1">إثبات التحويل @if ($proofRequired)<span class="text-rose">(مطلوب)</span>@else(اختياري)@endif</label>
                    <input id="proof" name="proof" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="block w-full text-sm text-slate-300 file:me-3 file:rounded-lg file:border-0 file:bg-white/10 file:px-3 file:py-2 file:text-sm file:font-bold file:text-white">
                    <p class="text-xs text-slate-500 mt-1">صورة JPEG/PNG/WEBP أو PDF، ويُحفظ خاصًا للمراجعة فقط.</p>
                </div>
                <button type="submit" class="btn-gem !py-2.5 !px-6 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إرسال للمراجعة</button>
            </form>
        </section>
    @endif

    @if ($purchase->isOpen())
        <form method="POST" action="{{ route('pass.orders.cancel', $purchase->public_id) }}" class="anim-fade-up d-3" onsubmit="return confirm('إلغاء هذا الطلب؟')">
            @csrf
            <button type="submit" class="text-xs font-bold text-slate-400 hover:text-white underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إلغاء الطلب</button>
        </form>
    @endif
@endsection
