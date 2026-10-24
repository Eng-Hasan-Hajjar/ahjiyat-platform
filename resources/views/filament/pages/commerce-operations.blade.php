<x-filament-panels::page>
    @php
        $d = $this->data();
        $s = $d['stats'];
        $card = 'rounded-xl border border-gray-200 dark:border-white/10 p-3';
        $pct = fn ($v) => $v === null ? '—' : number_format($v * 100, 1).'%';
        $dur = fn ($sec) => $sec === null ? '—' : ($sec >= 3600 ? number_format($sec / 3600, 1).' س' : ($sec >= 60 ? number_format($sec / 60, 1).' د' : $sec.' ث'));
        $funnelLabels = \App\Models\DigitalPurchase::STATUSES;
    @endphp

    <p class="text-xs text-gray-500">قراءة فقط. لا تُعرض مفاتيح أو أسرار أي مزوّد (مُهيَّأ ✅ / ناقص ❌ فقط). المبالغ مجمَّعة <strong>حسب العملة</strong> بلا تحويل ولا مجموع مختلط، ولا ترتيب مشترين. لتنفيذ إجراء على طلب استعمل صفحة «مشتريات المميز».</p>

    <div class="{{ $card }} overflow-x-auto" data-commerce-providers>
        <div class="font-bold text-sm mb-2">صحة المزوّدين</div>
        <table class="w-full text-sm">
            <thead><tr class="text-gray-500"><th class="text-start">المزوّد</th><th>النمط</th><th>مفعَّل</th><th>مُهيَّأ</th><th>إعادة توجيه</th><th>webhook</th><th>استرداد</th><th>استعلام</th><th>آخر حدث ناجح</th><th>فشل (24 س)</th><th>بانتظار</th><th>مراجعة</th><th>استرداد معلّق</th></tr></thead>
            <tbody>
            @foreach ($d['providers'] as $p)
                <tr class="border-t border-gray-100 dark:border-white/10 text-center" data-provider="{{ $p['key'] }}">
                    <td class="text-start"><span class="font-mono" dir="ltr">{{ $p['key'] }}</span><div class="text-xs text-gray-500">{{ $p['label'] }}</div></td>
                    <td>{{ $p['mode'] }}</td>
                    <td>{{ $p['enabled'] ? '✅' : '❌' }}</td>
                    <td data-configured="{{ $p['configured'] ? '1' : '0' }}">{{ $p['configured'] ? 'مُهيَّأ ✅' : 'ناقص ❌' }}</td>
                    <td>{{ $p['redirect'] ? '✅' : '—' }}</td>
                    <td>{{ $p['webhook'] ? ($p['accepts_webhooks'] ? '✅' : 'متوقف') : '—' }}</td>
                    <td>{{ $p['refund'] ? '✅' : '—' }}</td>
                    <td>{{ $p['status_query'] ? '✅' : '—' }}</td>
                    <td class="text-xs">{{ $p['last_event_at']?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td>{{ number_format($p['recent_failures']) }}</td>
                    <td>{{ number_format($p['awaiting']) }}</td>
                    <td>{{ number_format($p['needs_review']) }}</td>
                    <td>{{ number_format($p['refund_pending']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="grid md:grid-cols-2 gap-3">
        <div class="{{ $card }}" data-commerce-currencies>
            <div class="font-bold text-sm mb-2">المبيعات والاستردادات حسب العملة (آخر {{ $s['window_days'] }} يومًا)</div>
            @forelse ($s['currencies'] as $cur => $c)
                <div class="text-sm border-t border-gray-100 dark:border-white/10 py-1.5" data-currency="{{ $cur }}">
                    <div class="font-bold" dir="ltr">{{ $cur }}</div>
                    <div class="flex justify-between"><span>مبيعات مؤكَّدة ({{ number_format($c['verified_count']) }})</span><span dir="ltr">{{ $c['gross_label'] }}</span></div>
                    <div class="flex justify-between"><span>استردادات ({{ number_format($c['refund_count']) }})</span><span dir="ltr">{{ $c['refund_label'] }}</span></div>
                    <div class="flex justify-between font-bold"><span>الصافي</span><span dir="ltr">{{ $c['net_label'] }}</span></div>
                </div>
            @empty
                <div class="text-xs text-gray-500">لا مبيعات في هذه الفترة.</div>
            @endforelse
        </div>

        <div class="{{ $card }}" data-commerce-funnel>
            <div class="font-bold text-sm mb-2">قمع الطلبات والتحويل</div>
            <div class="text-sm flex justify-between"><span>طلبات أُنشئت</span><span>{{ number_format($s['created']) }}</span></div>
            <div class="text-sm flex justify-between"><span>نسبة التحويل</span><span>{{ $pct($s['conversion_rate']) }}</span></div>
            <div class="text-sm flex justify-between"><span>مستضاف / يدوي</span><span>{{ number_format($s['mix']['hosted']) }} / {{ number_format($s['mix']['manual']) }}</span></div>
            <div class="text-sm flex justify-between"><span>متوسط زمن التحقق (مستضاف / يدوي)</span><span>{{ $dur($s['latency_seconds']['hosted']) }} / {{ $dur($s['latency_seconds']['manual']) }}</span></div>
            <div class="mt-2 border-t border-gray-100 dark:border-white/10 pt-2">
                @foreach ($s['funnel'] as $status => $n)<div class="text-xs flex justify-between"><span>{{ $funnelLabels[$status] ?? $status }}</span><span>{{ number_format($n) }}</span></div>@endforeach
            </div>
        </div>
    </div>

    <div class="{{ $card }} overflow-x-auto" data-commerce-rates>
        <div class="font-bold text-sm mb-2">نجاح المزوّد وتخلّي المشترين</div>
        <table class="w-full text-sm">
            <thead><tr class="text-gray-500"><th class="text-start">المزوّد</th><th>طلبات</th><th>مؤكَّد</th><th>نجاح المزوّد</th><th>فشل المزوّد</th><th>تخلٍّ</th></tr></thead>
            <tbody>
            @forelse ($s['by_provider'] as $key => $p)
                <tr class="border-t border-gray-100 dark:border-white/10 text-center"><td class="text-start font-mono" dir="ltr">{{ $key }}</td><td>{{ number_format($p['created']) }}</td><td>{{ number_format($p['verified'] + $p['refunded']) }}</td><td>{{ $pct($p['success_rate']) }}</td><td>{{ $pct($p['failure_rate']) }}</td><td>{{ $pct($p['abandonment_rate']) }}</td></tr>
            @empty
                <tr><td colspan="6" class="text-xs text-gray-500 py-2">لا بيانات.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="{{ $card }}" data-commerce-unmatched>
        <div class="font-bold text-sm mb-2">أحداث مزوّد غير مرتبطة بطلب (آخر {{ $d['unmatched']->count() }})</div>
        @forelse ($d['unmatched'] as $e)
            <div class="text-xs flex flex-wrap justify-between gap-2 border-t border-gray-100 dark:border-white/10 py-1"><span dir="ltr">{{ $e->received_at->format('Y-m-d H:i') }} · {{ $e->provider }} · {{ $e->event_type }}</span><span>{{ $e->processing_result }}@if ($e->result_detail) ({{ $e->result_detail }})@endif</span></div>
        @empty
            <div class="text-xs text-gray-500">لا أحداث غير مرتبطة.</div>
        @endforelse
    </div>

    <div class="flex items-center gap-2">
        <button type="button" wire:click="runCheck" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-2 text-sm" data-run-check>فحص مطابقة (قراءة فقط)</button>
        <span class="text-xs text-gray-500">لا يكتب في السجل المالي. للتفاصيل الكاملة: <code dir="ltr">php artisan commerce:reconcile</code></span>
    </div>

    @if ($d['report'])
        <div class="{{ $card }}" data-commerce-report>
            <div class="font-bold text-sm mb-2">نتيجة فحص المطابقة ({{ count($d['report']['findings']) }}@if ($d['report']['truncated']) — مقتطع@endif)</div>
            @forelse ($d['report']['findings'] as $f)
                <div class="text-xs border-t border-gray-100 dark:border-white/10 py-1"><strong class="{{ $f['severity'] === 'critical' ? 'text-red-600' : ($f['severity'] === 'warning' ? 'text-amber-600' : 'text-gray-500') }}">{{ $f['severity'] }}</strong> {{ $f['code'] }} <span dir="ltr">{{ $f['subject'] }}</span> — {{ $f['message'] }}</div>
            @empty
                <div class="text-xs text-emerald-600">لا انحرافات.</div>
            @endforelse
        </div>
    @endif
</x-filament-panels::page>
