@extends('layouts.app')

@php
    $accent = \App\Services\AhjiyatPass\PassTheme::accent($pass);
    $background = \App\Services\AhjiyatPass\PassTheme::background($pass);
    $typeLabels = ['cosmetic' => 'تجميلي', 'access' => 'وصول', 'digital' => 'رقمي'];
    $last = $premium['purchase'];
@endphp

@section('title', 'المسار المميز - '.$pass->name)
@section('meta_description', 'المسار المميز لـ'.$pass->name.': شراء لمرة واحدة بمكافآت تجميلية إضافية. بلا اشتراك ولا تجديد تلقائي ولا أي أفضلية تنافسية.')

@section('content')
    <x-page-header title="المسار المميز" :subtitle="$pass->name" icon="sparkles" class="anim-fade-up" :back="route('pass.index')" back-label="العودة للمسار" />

    <section class="rounded-3xl border border-white/10 p-5 sm:p-6 mb-6 anim-fade-up d-1" style="--pass-accent: {{ $accent }}; background: {{ $background }}" data-pass-premium>
        @if ($has_premium)
            <p class="font-display font-black text-xl text-white mb-1">المسار المميز مفعّل لديك</p>
            <p class="text-sm text-slate-300">كل مكافآت المميز للشرائح التي بلغتها قابلة للاستلام من صفحة المسار.</p>
            <a href="{{ route('pass.index') }}" class="btn-gem !py-2.5 !px-6 text-sm mt-4 inline-flex focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">اذهب للمسار</a>
        @elseif (! $premium['enabled'] || ! $premium['sales_open'])
            <p class="font-display font-black text-xl text-white mb-1">الشراء غير متاح الآن</p>
            <p class="text-sm text-slate-300">المسار المميز غير معروض للبيع حاليًا. المسار المجاني يبقى متاحًا بالكامل.</p>
        @else
            <p class="text-xs text-slate-300">سعر المسار المميز (مرة واحدة)</p>
            <p class="font-display font-black text-3xl text-white mb-1" data-pass-price>{{ $premium['price_label'] }}</p>
            <p class="text-sm text-slate-300">يشمل {{ $premium['rewards_count'] }} مكافأة إضافية عبر شرائح هذا المسار. يُباع حتى {{ $premium['sales_end_at']->format('Y-m-d') }}.</p>

            @if ($frozen)
                <p class="mt-4 text-sm font-bold text-rose">حسابك مقيَّد؛ لا يمكن الشراء.</p>
            @else
                <form method="POST" action="{{ route('pass.orders.store') }}" class="mt-4">
                    @csrf
                    <input type="hidden" name="request_key" value="{{ $requestKey }}">
                    <button type="submit" class="btn-gem !py-2.5 !px-6 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-pass-order-create>{{ ($hostedDefault ?? false) ? 'متابعة الدفع' : 'إنشاء طلب شراء' }}</button>
                    @if ($manualAlternative ?? false)
                        <button type="submit" name="method" value="manual" class="ms-2 text-xs font-bold text-slate-300 underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-pass-order-manual>أفضّل التحويل اليدوي</button>
                    @endif
                </form>
                @if ($hostedDefault ?? false)
                    <p class="mt-2 text-xs text-slate-400">طريقة الدفع: {{ $providerLabel }}. ستنتقل إلى صفحة الدفع الخاصة بمزوّد الدفع؛ لا نطلب بيانات بطاقتك ولا نحفظها، ولا يُفتح المميز إلا بعد تأكيد الدفع من المزوّد.</p>
                @else
                    <p class="mt-2 text-xs text-slate-400">طريقة الدفع: {{ $providerLabel }}. إنشاء الطلب لا يخصم شيئًا ولا يفتح المميز؛ يُفتح بعد تأكيد الدفع من الإدارة.</p>
                @endif
            @endif
        @endif
    </section>

    @if ($last instanceof \App\Models\DigitalPurchase && ! $last->isOpen() && ! $last->isVerified())
        <div class="glass rounded-2xl p-4 mb-6 text-sm" data-pass-last-order>
            <p class="font-bold text-white mb-1">آخر طلب لك: {{ $last->statusLabel() }}</p>
            @if ($last->status === \App\Models\DigitalPurchase::STATUS_REJECTED && $last->decision_message)
                <p class="text-slate-300">سبب الرفض: {{ $last->decision_message }}</p>
            @endif
            <a href="{{ route('pass.orders.show', $last->public_id) }}" class="text-xs font-bold text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">عرض تفاصيل الطلب</a>
        </div>
    @endif

    <section class="glass rounded-2xl p-5 mb-6 anim-fade-up d-2" data-pass-premium-terms>
        <h2 class="font-display font-black text-lg text-white mb-2">ماذا تحصل عليه وماذا لا؟</h2>
        <ul class="text-sm text-slate-300 space-y-1.5 list-disc ps-5">
            <li>شراء واحد لهذا المسار فقط. ليس اشتراكًا ولا يتجدد تلقائيًا، ولا شراء شرائح أو نقاط.</li>
            <li>بأثر رجعي: ما بلغته من شرائح تُفتح مكافآته المميزة للاستلام فور تأكيد الدفع.</li>
            <li>مكافآت تجميلية أو وصولية فقط. لا نقد ولا جواهر ولا جوائز حقيقية، ولا أفضلية في الأحجيات أو الترتيب أو المنافسات أو السمعة.</li>
            @if ($hostedDefault ?? false)
                <li>الدفع عبر صفحة مزوّد الدفع نفسه: لا نطلب بيانات بطاقتك ولا رموزًا سرية أبدًا. يتأكد الدفع من المزوّد وحده، وليس من عودتك إلى الموقع.</li>
            @else
                <li>الدفع يدوي: تحوّل المبلغ ثم ترسل مرجع التحويل (وإثباتًا إن طُلب)، وتراجعه الإدارة. لا نطلب بيانات بطاقتك ولا رموزًا سرية أبدًا.</li>
            @endif
            <li>إن رُفض طلبك ستعرف السبب. الاسترداد يُدار من الإدارة وفق مراجعة؛ ما استلمته من مكافآت يخضع لمراجعة مالية ولا يُسحب آليًا.</li>
        </ul>
    </section>

    @if ($premium['rewards_count'] > 0)
        <section aria-labelledby="pass-premium-list-h" class="anim-fade-up d-3">
            <x-section-header title="مكافآت المسار المميز" id="pass-premium-list-h" />
            <ul class="grid gap-3 sm:grid-cols-2" data-pass-premium-rewards>
                @foreach ($premium['rewards'] as $r)
                    <li class="glass rounded-xl px-4 py-3 flex items-center justify-between gap-3 text-sm">
                        <span class="min-w-0 line-clamp-2 break-words font-bold text-white">{{ $r['item_name'] }}@if ($r['quantity'] > 1) <span class="text-slate-400 font-normal">×{{ $r['quantity'] }}</span>@endif</span>
                        <span class="shrink-0 text-xs text-slate-400">شريحة {{ $r['tier'] }} · {{ $typeLabels[$r['item_type']] ?? 'عنصر' }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
