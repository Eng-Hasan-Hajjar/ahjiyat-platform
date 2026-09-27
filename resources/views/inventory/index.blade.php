@extends('layouts.app')

@section('title', 'مقتنياتي')

@section('content')

    <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-8 anim-fade-up">مقتنياتي</h1>

    <h2 class="font-display font-black text-lg text-white mb-4 anim-fade-up d-1">العناصر المملوكة</h2>

    @if ($inventoryItems->isEmpty())
        <div class="glass rounded-2xl px-6 py-10 text-center anim-fade-up mb-10">
            <p class="text-slate-400 mb-4">لم تحصل على عناصر بعد.</p>
            <a href="{{ route('store.index') }}" class="btn-gem !py-2.5 !px-6 inline-block">استكشف المتجر</a>
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-10">
            @foreach ($inventoryItems as $inventoryItem)
                <div class="puzzle-card anim-fade-up flex items-center justify-between gap-3">
                    <span class="font-bold text-white text-sm">{{ $inventoryItem->item->name }}</span>
                    <span class="chip !py-1 !px-3 text-xs">× {{ $inventoryItem->quantity }}</span>
                </div>
            @endforeach
        </div>
    @endif

    <h2 class="font-display font-black text-lg text-white mb-4 anim-fade-up d-2">الامتيازات</h2>

    @if ($entitlements->isEmpty())
        <div class="glass rounded-2xl px-6 py-8 text-center anim-fade-up mb-10">
            <p class="text-slate-400">لا توجد امتيازات حالياً.</p>
        </div>
    @else
               <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-10">
            @foreach ($inventoryItems as $inventoryItem)
                <div class="puzzle-card anim-fade-up flex items-center justify-between gap-3">
                    <span class="font-bold text-white text-sm">{{ $inventoryItem->item->name }}</span>
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="chip !py-1 !px-3 text-xs">× {{ $inventoryItem->quantity }}</span>
                        @if ($inventoryItem->item->isCosmeticEquippable())
                            <a href="{{ route('profile.customize') }}" class="chip !py-1 !px-3 text-xs !text-amethyst">تخصيص</a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <h2 class="font-display font-black text-lg text-white mb-4 anim-fade-up d-3">سجل المشتريات</h2>

    <div class="glass rounded-2xl divide-y divide-white/5 anim-fade-up d-3">
        @forelse ($purchases as $purchase)
            <div class="flex items-center justify-between gap-3 px-4 md:px-5 py-4">
                <div class="min-w-0">
                    <span class="block text-sm font-bold text-white truncate">{{ $purchase->item_snapshot['name'] ?? $purchase->item?->name }}</span>
                    <span class="text-xs text-slate-500">{{ $purchase->created_at->format('Y-m-d H:i') }}</span>
                </div>
                <div class="text-left shrink-0">
                    <span class="block font-display font-black text-sm text-white" dir="ltr">{{ number_format($purchase->price_amount) }}</span>
                    <span class="text-[11px] font-bold {{ match($purchase->status) {
                        'fulfilled' => 'text-emerald', 'pending_fulfillment' => 'text-gold', 'refunded' => 'text-rose', default => 'text-slate-500'
                    } }}">
                        @switch($purchase->status)
                            @case('pending_fulfillment') بانتظار الإنجاز @break
                            @case('fulfilled') مكتمل @break
                            @case('refunded') مُسترجَع @break
                            @case('cancelled') ملغى @break
                            @default {{ $purchase->status }}
                        @endswitch
                    </span>
                </div>
            </div>
        @empty
            <p class="px-4 py-10 text-center text-slate-500 text-sm">لا توجد مشتريات بعد.</p>
        @endforelse
    </div>

    <div class="mt-6">{{ $purchases->links() }}</div>

@endsection