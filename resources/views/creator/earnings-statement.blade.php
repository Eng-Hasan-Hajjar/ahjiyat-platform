@extends('layouts.app')

@section('title', 'كشف فترة')

@section('content')
    @php
        $fmt = fn (int $minor) => \App\Services\CreatorFinance\CreatorMoney::format($minor, $allocation->currency);
    @endphp
    <div class="space-y-6">
        <x-page-header :title="$period->label" icon="chart" subtitle="كشف حصتك من هذه الفترة." :back="route('creator.earnings')" backLabel="الأرباح" class="anim-fade-up !mb-0" />

        <section class="rounded-2xl border border-white/10 bg-white/5 p-5 anim-fade-up">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div><dt class="text-xs text-slate-400">الفترة</dt><dd class="font-bold text-white">{{ $period->starts_at?->format('Y-m-d H:i') }} → {{ $period->ends_at?->format('Y-m-d H:i') }}</dd></div>
                <div><dt class="text-xs text-slate-400">حصتك</dt><dd class="font-black text-gold" data-statement-amount>{{ $fmt((int) $allocation->allocated_minor) }}</dd></div>
                <div><dt class="text-xs text-slate-400">وحداتك المحتسبة</dt><dd class="font-bold text-white">{{ number_format($allocation->qualified_units) }}</dd></div>
                <div><dt class="text-xs text-slate-400">لاعبون فريدون</dt><dd class="font-bold text-white">{{ number_format($allocation->unique_players) }}</dd></div>
                <div><dt class="text-xs text-slate-400">نصيبك من وحدات الفترة</dt><dd class="font-bold text-white">{{ number_format($share_bps / 100, 2) }}%</dd></div>
            </dl>
            @if ($allocation->remainder_bonus_minor > 0)
                <p class="text-xs text-slate-400 mt-4">أُضيفت إلى حصتك وحدة صغرى واحدة من توزيع الكسور، حتى يطابق مجموع الحصص المجمّع تمامًا.</p>
            @endif
        </section>

        <section class="rounded-2xl border border-white/10 bg-white/5 p-5 text-sm text-slate-300 space-y-2 anim-fade-up">
            <h2 class="font-display font-black text-white">كيف حُسبت حصتك؟</h2>
            <p>الوحدة المحتسبة = لاعب مختلف أجاب إجابة صحيحة عن أحد أعمالك داخل الفترة، وتُحتسب مرة واحدة لكل (لاعب، عمل).</p>
            <p>يُحتسب لكل لاعب حتى {{ number_format((int) ($policy['per_user_per_creator_cap'] ?? 0)) }} وحدات لدى المبدع الواحد. لا يدخل في الحساب عدد المتابعين ولا السمعة ولا أي دعم مالي.</p>
            <p>تُوزَّع حصة المبدعين بنسبة وحداتهم من مجموع وحدات الفترة. يتأهل المبدع بحدّ أدنى {{ number_format((int) ($policy['min_units_per_creator'] ?? 0)) }} وحدات و{{ number_format((int) ($policy['min_unique_players_per_creator'] ?? 0)) }} لاعبين.</p>
        </section>
    </div>
@endsection
