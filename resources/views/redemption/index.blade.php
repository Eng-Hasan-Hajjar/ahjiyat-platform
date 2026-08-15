@extends('layouts.app')

@section('title', 'طلبات الاستبدال')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="font-display font-bold text-2xl text-ink">طلبات الاستبدال</h1>
        <a href="{{ route('redemption.create') }}"
           class="bg-amethyst text-white font-bold text-sm px-4 py-2 rounded-lg hover:bg-amethyst-700 transition">
            طلب استبدال جديد
        </a>
    </div>

    @unless ($eligibility['eligible'])
        <div class="mb-6 rounded-lg bg-gold-50 border border-gold text-ink px-4 py-3 text-sm">
            <strong class="block mb-1">قبل ما تقدر تطلب استبدال:</strong>
            <ul class="list-disc pr-5 space-y-1">
                @foreach ($eligibility['reasons'] as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        </div>
    @endunless

    <div class="bg-white border border-ink/10 rounded-xl divide-y divide-ink/5">
        @forelse ($requests as $request)
            @php
                $statusMap = [
                    'pending_review' => ['قيد المراجعة', 'gold'],
                    'approved' => ['مقبول', 'emerald'],
                    'rejected' => ['مرفوض', 'rose'],
                    'fulfilled' => ['تم التنفيذ', 'emerald'],
                    'cancelled' => ['ملغى', 'rose'],
                ];
                [$label, $color] = $statusMap[$request->status] ?? [$request->status, 'ink'];
            @endphp
            <div class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="block text-sm text-ink">{{ $request->reward_description }}</span>
                    <span class="text-xs text-ink/40">{{ $request->created_at->format('Y-m-d') }} - {{ number_format($request->gems_amount) }} جوهرة</span>
                </div>
                <span class="text-xs font-bold px-2 py-1 rounded-full bg-{{ $color }}-50 text-{{ $color }}">
                    {{ $label }}
                </span>
            </div>
        @empty
            <p class="px-4 py-6 text-center text-ink/50 text-sm">لا توجد طلبات استبدال بعد.</p>
        @endforelse
    </div>

    <div class="mt-4">{{ $requests->links() }}</div>
@endsection
