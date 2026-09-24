@extends('layouts.app')

@section('title', 'المتجر')

@section('content')

    <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-2 anim-fade-up">المتجر</h1>
    <p class="text-slate-400 text-sm mb-8 anim-fade-up d-1">
        الرصيد الافتراضي داخل المنصة وليس حساباً مصرفياً.
    </p>

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