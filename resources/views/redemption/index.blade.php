@extends('layouts.app')

@section('title', 'طلبات الاستبدال')

@section('content')

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 anim-fade-up">
        <h1 class="font-display font-black text-2xl md:text-3xl text-white">طلبات الاستبدال</h1>
        <a href="{{ route('redemption.create') }}" class="btn-gem !py-2.5 !px-5 text-sm self-start sm:self-auto">
            طلب استبدال جديد
        </a>
    </div>

    @unless ($eligibility['eligible'])
        <div class="mb-6 rounded-xl border border-gold/30 bg-gold/10 text-gold px-4 py-4 text-sm anim-fade-up d-1">
            <strong class="block mb-2 font-black">قبل ما تقدر تطلب استبدال:</strong>
            <ul class="list-disc pr-5 space-y-1 font-semibold">
                @foreach ($eligibility['reasons'] as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        </div>
    @endunless

    <div class="glass rounded-2xl divide-y divide-white/5 anim-fade-up d-2">
        @forelse ($requests as $request)
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
                <span class="shrink-0 text-xs font-black px-3 py-1.5 rounded-full border
                    {{ $color === 'gold' ? 'bg-gold/10 text-gold border-gold/30' : '' }}
                    {{ $color === 'emerald' ? 'bg-emerald/10 text-emerald border-emerald/30' : '' }}
                    {{ $color === 'rose' ? 'bg-rose/10 text-rose border-rose/30' : '' }}
                    {{ $color === 'slate-400' ? 'bg-white/5 text-slate-400 border-white/10' : '' }}">
                    {{ $label }}
                </span>
            </div>
        @empty
            <p class="px-4 py-10 text-center text-slate-500 text-sm">لا توجد طلبات استبدال بعد.</p>
        @endforelse
    </div>

    <div class="mt-6">{{ $requests->links() }}</div>

@endsection