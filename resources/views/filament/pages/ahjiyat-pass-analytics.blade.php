<x-filament-panels::page>
    @php
        $d = $this->data();
        $s = $d['stats'];
        $card = 'rounded-xl border border-gray-200 dark:border-white/10 p-3';
        $funnelLabels = ['awaiting_payment' => 'بانتظار الدفع', 'submitted' => 'قيد التحقق', 'verified' => 'مؤكَّد', 'rejected' => 'مرفوض', 'cancelled' => 'ملغى', 'expired' => 'منتهٍ', 'refunded' => 'مسترد'];
    @endphp

    <p class="text-xs text-gray-500">أرقام تجميعية فقط (تُحدَّث كل دقائق قليلة). إيراد المميز إيراد منصة، ولا علاقة له بأرباح المبدعين أو صندوق الرعاية. لا قائمة أفراد حسب الإنفاق.</p>

    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="passId" class="rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm">
            @foreach ($d['passes'] as $p)<option value="{{ $p->public_id }}" @selected($d['selected'] && $d['selected']->id === $p->id)>{{ $p->name }} ({{ $p->starts_at->format('Y-m-d') }})</option>@endforeach
        </select>
        <button type="button" wire:click="runCheck" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-2 text-sm" data-run-check>فحص سلامة (قراءة فقط)</button>
    </div>

    @if (! $s)
        <p class="text-sm text-gray-500">لا مسارات منشورة بعد.</p>
    @else
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-pass-stats>
            <div class="{{ $card }}"><div class="text-xs text-gray-500">لاعبون</div><div class="text-xl font-bold">{{ number_format($s['players']) }}</div></div>
            <div class="{{ $card }}"><div class="text-xs text-gray-500">نشطون (7 أيام)</div><div class="text-xl font-bold">{{ number_format($s['active_7d']) }}</div></div>
            <div class="{{ $card }}"><div class="text-xs text-gray-500">مالكو المميز / التحويل</div><div class="text-xl font-bold">{{ number_format($s['premium_owners']) }} <span class="text-sm text-gray-500">({{ $s['conversion'] }}%)</span></div></div>
            <div class="{{ $card }}"><div class="text-xs text-gray-500">متوسط الشريحة</div><div class="text-xl font-bold">{{ $s['avg_tier'] }}</div></div>
            <div class="{{ $card }}"><div class="text-xs text-gray-500">بلغوا آخر شريحة</div><div class="text-xl font-bold">{{ number_format($s['at_max_tier']) }} <span class="text-sm text-gray-500">({{ $s['completion_rate'] }}%)</span></div></div>
            <div class="{{ $card }}"><div class="text-xs text-gray-500">نقاط ممنوحة</div><div class="text-xl font-bold">{{ number_format($s['points_granted']) }}</div></div>
            <div class="{{ $card }}"><div class="text-xs text-gray-500">مكافآت مستلَمة (مجاني / مميز)</div><div class="text-xl font-bold">{{ number_format($s['claims']['free']) }} / {{ number_format($s['claims']['premium']) }}</div></div>
            <div class="{{ $card }}"><div class="text-xs text-gray-500">مستردّات</div><div class="text-xl font-bold">{{ number_format($s['refunded']) }}</div></div>
        </div>

        <div class="grid md:grid-cols-2 gap-3">
            <div class="{{ $card }}" data-pass-revenue>
                <div class="font-bold text-sm mb-2">إيراد المميز المؤكَّد (صافي المستردّ)</div>
                @forelse ($s['revenue'] as $r)<div class="text-sm" dir="ltr">{{ $r['label'] }} <span class="text-gray-500">({{ $r['count'] }})</span></div>@empty<div class="text-xs text-gray-500">لا إيراد بعد.</div>@endforelse
            </div>
            <div class="{{ $card }}" data-pass-funnel>
                <div class="font-bold text-sm mb-2">قمع الطلبات</div>
                @forelse ($s['funnel'] as $status => $n)<div class="text-sm flex justify-between"><span>{{ $funnelLabels[$status] ?? $status }}</span><span>{{ number_format($n) }}</span></div>@empty<div class="text-xs text-gray-500">لا طلبات.</div>@endforelse
            </div>
        </div>

        <div class="{{ $card }}" data-pass-distribution>
            <div class="font-bold text-sm mb-2">توزيع اللاعبين على الشرائح</div>
            @php($peak = max(1, max($s['distribution'] ?: [1])))
            @forelse ($s['distribution'] as $tier => $n)
                <div class="flex items-center gap-2 text-xs"><span class="w-10 text-center">{{ $tier }}</span><div class="flex-1 h-2 bg-gray-100 dark:bg-white/10 rounded"><div class="h-2 rounded bg-primary-600" style="width: {{ (int) round($n / $peak * 100) }}%"></div></div><span class="w-12">{{ number_format($n) }}</span></div>
            @empty<div class="text-xs text-gray-500">لا لاعبين بعد.</div>@endforelse
        </div>

        <div class="{{ $card }}" data-pass-missions-stats>
            <div class="font-bold text-sm mb-2">أداء المهام</div>
            <table class="w-full text-xs"><thead><tr class="text-gray-500"><th class="text-start">المهمة</th><th>إكمالات محتسبة</th><th>لاعبون</th></tr></thead><tbody>
                @forelse ($s['missions'] as $missionId => $m)<tr class="border-t border-gray-100 dark:border-white/10"><td class="text-start">{{ $d['missionNames'][$missionId] ?? '#'.$missionId }}</td><td class="text-center">{{ number_format($m['completions']) }}</td><td class="text-center">{{ number_format($m['players']) }}</td></tr>@empty<tr><td colspan="3" class="py-2 text-gray-500">لا إكمالات بعد.</td></tr>@endforelse
            </tbody></table>
        </div>
    @endif

    @if ($d['report'])
        <div class="{{ $card }} space-y-1" data-pass-check-report>
            <div class="font-bold text-sm">نتيجة فحص السلامة: {{ count($d['report']['findings']) === 0 ? 'سليم' : count($d['report']['findings']).' ملاحظة' }}@if ($d['report']['truncated']) (مقتطع)@endif</div>
            @foreach ($d['report']['findings'] as $f)
                <div class="text-xs"><span class="font-bold {{ $f['severity'] === 'critical' ? 'text-red-600' : ($f['severity'] === 'warning' ? 'text-amber-600' : 'text-gray-500') }}">{{ $f['severity'] }}</span> · {{ $f['code'] }} · <span dir="ltr">{{ $f['subject'] }}</span> — {{ $f['message'] }}</div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
