@extends('layouts.app')

@section('title', 'طلبات دعمي')
@section('content_width', 'max-w-4xl')

{{-- E32: سجل طلبات الدعم للمستخدم الحالي فقط (ترقيم 15). لا ملاحظات إدارية ولا تفاصيل معادل محاسبي ولا مراجع داخلية. --}}
@section('content')
    <div class="space-y-6">
        <x-page-header title="طلبات دعمي" icon="wallet" :back="route('support.index')" backLabel="ادعم المنصة" class="anim-fade-up !mb-0" />

        @if ($payments->isEmpty())
            <x-empty-state icon="gift" title="لا طلبات دعم بعد" message="عند إنشاء طلب دعم سيظهر هنا مع حالته." />
        @else
            <div class="grid gap-2 anim-fade-up">
                @foreach ($payments as $payment)
                    <a href="{{ route('support.payments.show', ['payment' => $payment->public_id]) }}" class="glass rounded-2xl px-4 py-3 flex flex-wrap items-center gap-3 hover:bg-white/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                        <span class="font-mono font-black text-white" dir="ltr">{{ $payment->reference }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm text-slate-300">{{ $payment->program?->title }}</span>
                        <span class="font-black text-white">{{ $payment->amountLabel() }}</span>
                        <span class="text-xs text-slate-400">{{ $payment->is_anonymous ? 'مجهول' : 'باسمي' }}</span>
                        <span class="text-xs text-slate-400">{{ $payment->created_at?->format('Y-m-d') }}</span>
                        @include('support._status', ['small' => true])
                    </a>
                @endforeach
            </div>
            {{ $payments->links() }}
        @endif
    </div>
@endsection
