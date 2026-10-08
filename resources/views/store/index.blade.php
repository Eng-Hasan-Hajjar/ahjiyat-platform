@extends('layouts.app')

@section('title', 'المتجر')

@section('content')

    <x-page-header title="المتجر" subtitle="الرصيد الافتراضي داخل المنصة وليس حساباً مصرفياً." icon="store" class="anim-fade-up">
        <x-slot:actions>
            @auth
                <a href="{{ route('inventory.index') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="inventory" class="w-4 h-4" />مقتنياتي</a>
                <a href="{{ route('wallet.index') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="wallet" class="w-4 h-4" />محفظتي</a>
            @endauth
        </x-slot:actions>
    </x-page-header>

    <x-section-header title="العناصر والمكافآت" class="anim-fade-up d-1" />

    @if ($items->isEmpty())
        <x-empty-state icon="store" title="لا توجد عناصر متاحة حالياً" message="تابعنا قريبًا؛ تُضاف عناصر ومكافآت جديدة باستمرار." class="anim-fade-up mb-10" />
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-12">
            @foreach ($items as $item)
                @php
                    $soldOut = $item->isSoldOut();
                    $isOwnedCosmetic = $item->item_type === 'cosmetic' && $ownedCosmeticItemIds->contains($item->id);
                @endphp
                <a href="{{ $isOwnedCosmetic ? route('profile.customize') : route('store.items.show', $item) }}" class="puzzle-card anim-fade-up flex flex-col justify-between hover:border-amethyst/40 transition">
                    <div>
                        @if ($item->image_path)
                            <img src="{{ \Illuminate\Support\Facades\Storage::url($item->image_path) }}" alt="" class="w-full h-32 object-cover rounded-xl mb-3">
                        @endif

                        <div class="flex items-center gap-2 mb-2">
                            <span class="chip !py-0.5 !px-2 text-[10px]">
                                @if ($item->item_type === 'cosmetic')
                                    @switch($item->cosmetic_slot)
                                        @case('avatar') صورة رمزية @break
                                        @case('profile_frame') إطار @break
                                        @case('badge') شارة @break
                                        @case('title') لقب @break
                                        @case('profile_background') خلفية ملف @break
                                        @default تجميلي
                                    @endswitch
                                @else
                                    @switch($item->item_type)
                                        @case('physical') مادي @break
                                        @case('digital') رقمي @break
                                        @case('consumable') استهلاكي @break
                                        @case('access') وصول @break
                                        @default {{ $item->item_type }}
                                    @endswitch
                                @endif
                            </span>
                            @if ($item->is_featured)
                                <span class="chip !py-0.5 !px-2 text-[10px] !text-gold">مميَّز</span>
                            @endif
                        </div>

                        <h3 class="font-display font-black text-lg text-white mb-1">{{ $item->name }}</h3>
                        @if ($item->short_description)
                            <p class="text-xs text-slate-400 mb-3 line-clamp-2">{{ $item->short_description }}</p>
                        @endif

                        <div class="flex flex-wrap gap-2 mb-3">
                            @foreach ($item->activePrices as $price)
                                <span class="text-xs font-bold text-amethyst" dir="ltr">{{ number_format($price->amount) }} {{ $price->currency->code }}</span>
                            @endforeach
                        </div>
                    </div>

                    @if ($isOwnedCosmetic)
                        <span class="w-full text-center py-2.5 rounded-xl bg-emerald/10 text-emerald font-bold text-sm border border-emerald/30">مملوك - تخصيص ←</span>
                    @elseif ($soldOut)
                        <span class="w-full text-center py-2.5 rounded-xl bg-white/5 text-slate-500 font-bold text-sm border border-white/10">نفدت الكمية</span>
                    @else
                        <span class="w-full text-center py-2.5 rounded-xl btn-gem text-sm">عرض التفاصيل ←</span>
                    @endif
                </a>
            @endforeach
        </div>
    @endif

    <h2 class="font-display font-black text-lg md:text-xl text-white mb-4 anim-fade-up d-2">حزم العملات</h2>

    @if ($packs->isEmpty())
        <div class="glass rounded-2xl px-6 py-16 text-center anim-fade-up">
            <p class="text-slate-400">لا توجد حزم متاحة حالياً.</p>
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach ($packs as $pack)
                <div class="puzzle-card anim-fade-up flex flex-col justify-between">
                    <div>
                        @if ($pack->image_path)
                            <img src="{{ \Illuminate\Support\Facades\Storage::url($pack->image_path) }}" alt="" class="w-full h-28 object-cover rounded-xl mb-3">
                        @endif
                        <h3 class="font-display font-black text-lg text-white mb-1">{{ $pack->name }}</h3>
                        <p class="text-xs text-slate-400 mb-3">{{ $pack->currency->name }}</p>

                        <div class="font-display font-black text-2xl text-amethyst mb-1" dir="ltr">
                            {{ number_format($pack->base_amount) }}
                            @if ($pack->bonus_amount > 0)
                                <span class="text-emerald text-sm">+{{ number_format($pack->bonus_amount) }} 🎁</span>
                            @endif
                        </div>

                        <div class="text-slate-300 font-bold mb-4" dir="ltr">{{ $pack->priceDisplay() }}</div>
                    </div>

                    <button type="button" disabled
                        class="w-full py-3 rounded-xl bg-white/5 text-slate-400 font-bold text-sm cursor-not-allowed border border-white/10">
                        سيتم تفعيل الشراء قريباً
                    </button>
                </div>
            @endforeach
        </div>
    @endif

@endsection