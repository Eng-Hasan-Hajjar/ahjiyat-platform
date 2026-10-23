@extends('layouts.guest')

@section('title', 'تفاصيل الإجراء')
@section('width', 'max-w-2xl')

@section('content')
    {{-- E38: لا هوية مبلِّغ ولا مشرف ولا سبب داخلي. نص الاعتراض ورسالة القرار نصوص عادية مهرَّبة. --}}
    <a href="{{ route('account.safety.index') }}" class="text-sm font-bold text-amethyst hover:text-white transition">← الأمان والإشراف</a>

    <h1 class="font-display font-black text-xl text-white mt-3 mb-1">{{ $item['label'] }}</h1>
    <p class="text-xs text-slate-400 mb-4">
        {{ $item['created_at']?->format('Y-m-d H:i') }} · التصنيف: {{ $item['category_label'] }} · {{ $item['issuer'] }}
        @if ($item['reference']) · المرجع: {{ $item['reference'] }} @endif
    </p>

    @if ($item['message'])
        <div class="rounded-2xl border border-white/10 bg-white/5 p-4 mb-4">
            <p class="text-xs font-bold text-slate-400 mb-1">رسالة فريق الإشراف</p>
            <p class="text-sm text-slate-100 whitespace-pre-line">{{ $item['message'] }}</p>
        </div>
    @endif

    @if ($item['reversed_at'])
        <p class="mb-4 rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-3 text-sm font-bold">أُلغي هذا الإجراء بتاريخ {{ $item['reversed_at']->format('Y-m-d') }}.</p>
    @endif

    <p class="text-sm font-black text-white mb-2">حالة الاعتراض: <span class="text-amethyst">{{ $item['appeal_state_label'] }}</span></p>

    @if ($item['decision_message'])
        <div class="rounded-2xl border border-white/10 bg-white/5 p-4 mb-4">
            <p class="text-xs font-bold text-slate-400 mb-1">قرار الاعتراض</p>
            <p class="text-sm text-slate-100 whitespace-pre-line">{{ $item['decision_message'] }}</p>
        </div>
    @endif

    @if (session('success'))
        <div class="mb-4 rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-3 text-sm font-bold">{{ session('success') }}</div>
    @endif

    @if ($item['can_appeal'])
        <form method="POST" action="{{ route('account.safety.appeal', $item['public_id']) }}" class="space-y-3" data-appeal-form>
            @csrf
            <label for="reason" class="block text-sm font-bold text-slate-300">اشرح سبب اعتراضك (نص عادي)</label>
            <textarea id="reason" name="reason" rows="5" required minlength="{{ config('trust_safety.appeals.reason_min') }}" maxlength="{{ config('trust_safety.appeals.reason_max') }}" class="input-gem">{{ old('reason') }}</textarea>
            @error('reason')
                <span class="text-xs text-rose font-semibold block">{{ $message }}</span>
            @enderror
            <p class="text-xs text-slate-400">يمكنك الاعتراض حتى {{ $item['appeal_until']?->format('Y-m-d') }}. لا نقبل مرفقات؛ اكتب ما يلزم فقط.</p>
            <button type="submit" class="btn-gem w-full justify-center">إرسال الاعتراض</button>
        </form>
    @elseif ($item['can_withdraw'])
        <form method="POST" action="{{ route('account.safety.withdraw', $item['appeal_public_id']) }}">
            @csrf
            <button type="submit" class="text-sm font-bold text-slate-400 hover:text-white transition">سحب الاعتراض</button>
        </form>
    @endif
@endsection
