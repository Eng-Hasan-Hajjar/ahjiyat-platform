@extends('layouts.guest')

@section('title', 'الأمان والإشراف')
@section('width', 'max-w-2xl')

@section('content')
    {{-- E38: كل النصوص مهرَّبة ({{ }}). لا مبلِّغ ولا اسم مشرف ولا سبب داخلي هنا؛ المُصدِر دائمًا «فريق الإشراف». --}}
    <h1 class="font-display font-black text-xl text-white mb-2 text-center">الأمان والإشراف</h1>
    <p class="text-sm text-slate-400 text-center mb-6">هنا تجد الإجراءات التي اتخذها فريق الإشراف بشأن حسابك أو محتواك، ويمكنك الاعتراض على ما يقبل الاعتراض.</p>

    @if (session('error'))
        <div class="mb-5 rounded-xl border border-rose/30 bg-rose/10 text-rose px-4 py-3 text-sm font-semibold">{{ session('error') }}</div>
    @endif

    @if ($overview['frozen'])
        <div class="mb-5 rounded-xl border border-gold/30 bg-gold/10 px-4 py-3 text-sm font-semibold" data-frozen-banner>
            <p class="font-black text-white mb-1">حسابك مجمَّد حاليًا</p>
            <p class="text-slate-300">لا يمكنك استخدام بقية المنصة أثناء التجميد. يمكنك هنا الاطلاع على سبب الإجراء وتقديم اعتراض.</p>
            @unless ($overview['has_freeze_action'])
                <p class="text-slate-400 mt-2">لا يظهر أدناه إجراء قابل للاعتراض على التجميد؛ تواصل مع الدعم لمزيد من التفاصيل.</p>
            @endunless
        </div>
    @endif

    @forelse ($overview['items'] as $item)
        <a href="{{ route('account.safety.show', $item['public_id']) }}" class="block rounded-2xl border border-white/10 bg-white/5 p-4 mb-3 hover:border-amethyst/40 transition" data-safety-item>
            <div class="flex items-center justify-between gap-3 mb-1">
                <span class="font-black text-white">{{ $item['label'] }}</span>
                <span class="text-xs font-bold text-slate-400">{{ $item['created_at']?->format('Y-m-d') }}</span>
            </div>
            <p class="text-xs text-slate-400 mb-2">التصنيف: {{ $item['category_label'] }} · {{ $item['issuer'] }}@if ($item['reference']) · المرجع: {{ $item['reference'] }}@endif</p>
            @if ($item['message'])
                <p class="text-sm text-slate-200 mb-2 whitespace-pre-line">{{ $item['message'] }}</p>
            @endif
            <p class="text-xs font-bold {{ $item['can_appeal'] ? 'text-amethyst' : 'text-slate-400' }}">{{ $item['appeal_state_label'] }}</p>
        </a>
    @empty
        <p class="text-center text-sm text-slate-400 py-6" data-safety-empty>لا توجد إجراءات إشرافية على حسابك.</p>
    @endforelse

    <div class="mt-6 flex items-center justify-center gap-4 text-sm">
        @if ($restricted)
            <form method="POST" action="{{ route('account.safety.leave') }}">
                @csrf
                <button type="submit" class="text-slate-400 hover:text-white font-bold transition">إنهاء هذه الجلسة</button>
            </form>
        @else
            <a href="{{ route('home') }}" class="text-amethyst font-bold hover:text-white transition">العودة للرئيسية</a>
        @endif
    </div>
@endsection
