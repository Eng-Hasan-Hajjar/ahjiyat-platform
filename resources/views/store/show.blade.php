@extends('layouts.app')

@section('title', $item->name)

@section('content')

    <div class="max-w-3xl mx-auto">
        <a href="{{ route('store.index') }}"
           class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-white transition mb-4 anim-fade-up">
            → المتجر
        </a>

        <div class="puzzle-card !p-6 md:!p-9 anim-fade-up d-1">

            @if ($item->image_path)
                <img src="{{ \Illuminate\Support\Facades\Storage::url($item->image_path) }}" alt="" class="w-full h-56 object-cover rounded-xl mb-5">
            @endif

            <div class="flex flex-wrap items-center gap-2 mb-4">
                <span class="chip !py-1 !px-3">
                    @switch($item->item_type)
                        @case('physical') مادي @break
                        @case('digital') رقمي @break
                        @case('cosmetic') تجميلي @break
                        @case('consumable') استهلاكي @break
                        @case('access') وصول @break
                        @default {{ $item->item_type }}
                    @endswitch
                </span>
                @if ($item->is_featured)
                    <span class="chip !py-1 !px-3 !text-gold">مميَّز</span>
                @endif
            </div>

            <h1 class="font-display font-black text-2xl md:text-4xl text-white mb-4 leading-tight">{{ $item->name }}</h1>

            @if ($item->description)
                <p class="text-slate-400 mb-6 leading-relaxed">{{ $item->description }}</p>
            @endif

            @if (session('error'))
                <div class="rounded-xl bg-rose/10 border border-rose/30 text-rose text-sm font-bold px-4 py-3 mb-5">
                    {{ session('error') }}
                </div>
            @endif

            @if (session('success'))
                <div class="rounded-xl bg-emerald/10 border border-emerald/30 text-emerald text-sm font-bold px-4 py-3 mb-5">
                    {{ session('success') }}
                </div>
            @endif

            @php
                $soldOut = $item->isSoldOut();
                $permanentlyOwned = $hasEntitlement === true && $item->entitlement_duration_days === null;
            @endphp

            @if ($item->fulfillment_type === 'inventory' && $ownedQuantity !== null && $ownedQuantity > 0)
                <div class="rounded-xl bg-white/5 border border-white/10 text-sm font-bold px-4 py-3 mb-5">
                    تمتلك حالياً: {{ $ownedQuantity }}
                </div>
            @endif

            @if ($permanentlyOwned)
                <div class="rounded-xl bg-emerald/10 border border-emerald/30 text-emerald text-sm font-bold px-4 py-3 mb-5">
                    ✓ مملوك بالفعل
                </div>
            @elseif ($soldOut)
                <div class="rounded-xl bg-white/5 border border-white/10 text-slate-400 text-sm font-bold px-4 py-3 mb-5">
                    نفدت الكمية المتاحة من هذا العنصر.
                </div>
            @elseif (! auth()->check())
                <div class="rounded-xl bg-white/5 border border-white/10 text-center px-4 py-6 mb-2">
                    <p class="text-slate-300 font-bold mb-3">سجّل الدخول للشراء</p>
                    <a href="{{ route('login') }}" class="btn-gem !py-2.5 !px-6 inline-block">تسجيل الدخول</a>
                </div>
            @else
                <div class="space-y-3">
                    @forelse ($item->activePrices as $price)
                        <form method="POST" action="{{ route('store.items.purchase', $item) }}"
                              onsubmit="return confirm('تأكيد شراء «{{ $item->name }}» مقابل {{ number_format($price->amount) }} {{ $price->currency->code }}؟')"
                              class="flex items-center justify-between gap-3 rounded-xl bg-white/5 border border-white/10 px-4 py-3">
                            @csrf
                            <input type="hidden" name="price_id" value="{{ $price->id }}">
                            <input type="hidden" name="request_key" value="{{ $requestKey }}">
                            <span class="font-black text-white" dir="ltr">{{ number_format($price->amount) }} {{ $price->currency->code }}</span>
                            <button type="submit" class="btn-gem !py-2 !px-5 text-sm">شراء</button>
                        </form>
                    @empty
                        <p class="text-slate-500 text-sm">لا توجد خيارات سعر متاحة لهذا العنصر حالياً.</p>
                    @endforelse
                </div>
            @endif

            @if ($item->stock_limit !== null && ! $soldOut)
                <p class="text-xs text-slate-500 mt-4">متبقٍّ من المخزون: {{ $item->remainingStock() }}</p>
            @endif

            @if ($item->per_user_limit !== null)
                <p class="text-xs text-slate-500 mt-1">الحد المسموح لكل مستخدم: {{ $item->per_user_limit }}</p>
            @endif

        </div>
    </div>

@endsection