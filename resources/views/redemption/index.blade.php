@extends('layouts.app')

@section('title', 'طلبات الاستبدال')

@section('content')

    <x-page-header title="طلبات الاستبدال" subtitle="تابع حالة طلباتك. كل طلب يُراجَع قبل التنفيذ." icon="gift" class="anim-fade-up">
        <x-slot:actions>
            <a href="{{ route('wallet.index') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="wallet" class="w-4 h-4" />محفظتي</a>
            <a href="{{ route('redemption.create') }}" class="btn-gem !py-2.5 !px-5 text-sm">طلب استبدال جديد</a>
        </x-slot:actions>
    </x-page-header>

    @unless ($eligibility['eligible'])
        <div class="mb-6 rounded-xl border border-gold/30 bg-gold/10 text-gold px-4 py-4 text-sm anim-fade-up d-1" role="note">
            <strong class="flex items-center gap-2 mb-2 font-black"><x-ui-icon name="info" class="w-5 h-5 shrink-0" />قبل ما تقدر تطلب استبدال:</strong>
            <ul class="list-disc pr-5 space-y-1 font-semibold">
                @foreach ($eligibility['reasons'] as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        </div>
    @endunless

    @if ($requests->isEmpty())
        <x-empty-state icon="gift" title="لا توجد طلبات استبدال بعد" message="عندما يصل رصيدك المتاح إلى الحد الأدنى يمكنك إرسال أول طلب." class="anim-fade-up d-2" />
    @else
    <div class="glass rounded-2xl divide-y divide-white/5 anim-fade-up d-2">
        @foreach ($requests as $request)
            @php
                $statusMap = [
                    'pending_review' => ['قيد المراجعة', 'gold'],
                    'approved'       => ['مقبول', 'emerald'],
                    'rejected'       => ['مرفوض', 'rose'],
                    'fulfilled'      => ['تم التنفيذ', 'emerald'],
                    'cancelled'      => ['ملغى', 'rose'],
                ];
                [$label, $color] = $statusMap[$request->status] ?? [$request->status, 'slate-400'];
            @endphp
            <div class="flex items-center justify-between gap-3 px-4 md:px-5 py-4">
                <div class="min-w-0">
                    <span class="block text-sm font-bold text-white truncate">{{ $request->reward_description }}</span>
                    <span class="text-xs text-slate-500">
                        {{ $request->created_at->format('Y-m-d') }} · {{ number_format($request->gems_amount) }} جوهرة
                    </span>
                </div>
                <x-status-badge :tone="['gold' => 'warning', 'emerald' => 'success', 'rose' => 'danger'][$color] ?? 'neutral'">{{ $label }}</x-status-badge>
            </div>
        @endforeach
    </div>
    @endif

    <div class="mt-6">{{ $requests->links() }}</div>

@endsection