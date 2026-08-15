@extends('layouts.app')

@section('title', 'محفظتي')

@section('content')
    <h1 class="font-display font-bold text-2xl text-ink mb-6">محفظتي</h1>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <div class="bg-white border border-ink/10 rounded-xl p-5">
            <span class="text-xs text-ink/50 block mb-1">الرصيد المتاح</span>
            <span class="font-display font-extrabold text-2xl text-emerald-600">{{ number_format($wallet->available_balance ?? 0) }}</span>
        </div>
        <div class="bg-white border border-ink/10 rounded-xl p-5">
            <span class="text-xs text-ink/50 block mb-1">الرصيد المعلّق</span>
            <span class="font-display font-extrabold text-2xl text-gold">{{ number_format($wallet->pending_balance ?? 0) }}</span>
        </div>
        <div class="bg-white border border-ink/10 rounded-xl p-5">
            <span class="text-xs text-ink/50 block mb-1">إجمالي ما تم كسبه</span>
            <span class="font-display font-extrabold text-2xl text-ink">{{ number_format($wallet->lifetime_earned ?? 0) }}</span>
        </div>
    </div>

    <h2 class="font-display font-bold text-lg text-ink mb-3">سجل المعاملات</h2>
    <div class="bg-white border border-ink/10 rounded-xl divide-y divide-ink/5">
        @forelse ($transactions as $transaction)
            <div class="flex items-center justify-between px-4 py-3 text-sm">
                <div>
                    <span class="block text-ink">{{ $transaction->reason }}</span>
                    <span class="text-xs text-ink/40">{{ $transaction->created_at->format('Y-m-d H:i') }}</span>
                </div>
                <span class="font-bold {{ $transaction->amount >= 0 ? 'text-emerald-600' : 'text-rose' }}">
                    {{ $transaction->amount >= 0 ? '+' : '' }}{{ number_format($transaction->amount) }}
                </span>
            </div>
        @empty
            <p class="px-4 py-6 text-center text-ink/50 text-sm">لا توجد معاملات بعد.</p>
        @endforelse
    </div>

    <div class="mt-4">{{ $transactions->links() }}</div>
@endsection
