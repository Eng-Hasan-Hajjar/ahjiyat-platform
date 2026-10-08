@extends('layouts.app')

@section('title', 'مقتنياتي')

@section('content')

    <x-page-header title="مقتنياتي" subtitle="العناصر والامتيازات التي حصلت عليها وسجل مشترياتك." icon="inventory" class="anim-fade-up">
        <x-slot:actions>
            <a href="{{ route('profile.customize') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="palette" class="w-4 h-4" />تخصيص الهوية</a>
            <a href="{{ route('store.index') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="store" class="w-4 h-4" />المتجر</a>
        </x-slot:actions>
    </x-page-header>

    <x-section-header title="العناصر المملوكة" class="anim-fade-up d-1" />

    @if ($inventoryItems->isEmpty())
        <x-empty-state icon="inventory" title="لم تحصل على عناصر بعد" message="اشترِ عناصر من المتجر أو اربحها من المنافسات والإنجازات." :action="route('store.index')" actionLabel="استكشف المتجر" class="anim-fade-up mb-10" />
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-10" data-inventory="items">
            @foreach ($inventoryItems as $inventoryItem)
                <div class="puzzle-card anim-fade-up flex items-center justify-between gap-3">
                    <span class="font-bold text-white text-sm min-w-0 break-words">{{ $inventoryItem->item->name }}</span>
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="chip !py-1 !px-3 text-xs">× {{ $inventoryItem->quantity }}</span>
                        @if ($inventoryItem->item->isCosmeticEquippable())
                            <a href="{{ route('profile.customize') }}" class="chip !py-1 !px-3 text-xs !text-amethyst focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">تخصيص</a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <x-section-header title="الامتيازات" class="anim-fade-up d-2" />

    @if ($entitlements->isEmpty())
        <x-empty-state icon="key" title="لا توجد امتيازات حاليًا" message="الامتيازات تظهر هنا عند شراء عناصر تمنح ميزة مؤقتة أو دائمة." class="anim-fade-up mb-10" />
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-10" data-inventory="entitlements">
            @foreach ($entitlements as $entitlement)
                @php
                    [$stateLabel, $stateClass] = match (true) {
                        $entitlement->revoked_at !== null => ['ملغى', 'text-rose'],
                        $entitlement->isActive() => ['فعّال', 'text-emerald'],
                        $entitlement->starts_at?->isFuture() => ['لم يبدأ بعد', 'text-gold'],
                        default => ['منتهٍ', 'text-slate-500'],
                    };
                @endphp
                <div class="puzzle-card anim-fade-up flex items-center justify-between gap-3" data-entitlement>
                    <div class="min-w-0">
                        <span class="block font-bold text-white text-sm break-words">{{ $entitlement->item?->name ?? 'امتياز' }}</span>
                        @if ($entitlement->expires_at && $entitlement->isActive())
                            <span class="text-[11px] text-slate-500">ينتهي {{ $entitlement->expires_at->format('Y-m-d') }}</span>
                        @endif
                    </div>
                    <span class="shrink-0 text-xs font-black {{ $stateClass }}">{{ $stateLabel }}</span>
                </div>
            @endforeach
        </div>
    @endif

    <x-section-header title="سجل المشتريات" class="anim-fade-up d-3" />

    @if ($purchases->isEmpty())
        <x-empty-state icon="store" title="لا توجد مشتريات بعد" message="ستظهر مشترياتك هنا مع حالة كل طلب." class="anim-fade-up" />
    @else
        <div class="glass rounded-2xl divide-y divide-white/5 anim-fade-up d-3">
            @foreach ($purchases as $purchase)
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
            @endforeach
        </div>
    @endif

    <div class="mt-6">{{ $purchases->links() }}</div>

@endsection
