<x-filament-panels::page>
    @php
        $d = $this->data();
        $a = $d['aggregate'];
        $live = $d['live'];
        $ap = $a['appeals'];
        $fmt = fn ($v, $suffix = '') => $v === null ? '—' : $v.$suffix;
    @endphp

    <p class="text-xs text-gray-500">
        أرقام مجمَّعة آخر {{ $d['window_days'] }} يومًا (حتى {{ $d['generated_at'] }}). لا ترتيب مبلِّغين ولا لوحة متصدرين للمشرفين ولا قياس أداء فردي.
        معدل قبول الاعتراضات مؤشر لمراجعة جودة القرارات الأصلية لا مقياس لشخص.
    </p>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-safety-live>
        @foreach ([
            'بلاغات مفتوحة' => $live['inbox_open'], 'إشارات نظام مفتوحة' => $live['inbox_system_signals'], 'بلاغات بلا حالة' => $live['inbox_unlinked'],
            'حالات فعّالة' => $live['cases_active'], 'غير معيَّنة' => $live['cases_unassigned'], 'عاجلة' => $live['cases_urgent'], 'متأخرة' => $live['cases_overdue'],
            'اعتراضات بانتظار المراجعة' => $live['appeals_pending'], 'اعتراضات قيد المراجعة' => $live['appeals_in_review'],
        ] as $label => $value)
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <div class="text-xs text-gray-500">{{ $label }}</div>
                <div class="text-xl font-bold mt-1">{{ number_format((int) $value) }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid md:grid-cols-3 gap-6">
        @foreach (['حالات حسب التصنيف' => $a['cases_by_category'], 'قرارات الحسم' => $a['cases_by_resolution'], 'الإجراءات المنفَّذة' => $a['actions_by_type']] as $title => $rows)
            <div>
                <h3 class="font-bold mb-2">{{ $title }}</h3>
                <table class="w-full text-sm">
                    <tbody>
                    @forelse ($rows as $label => $count)
                        <tr class="border-t border-gray-100 dark:border-white/10"><td>{{ $label }}</td><td class="text-center">{{ number_format((int) $count) }}</td></tr>
                    @empty
                        <tr><td class="text-gray-500">لا بيانات.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div data-safety-response>
            <h3 class="font-bold mb-2">أزمنة المعالجة (متوسط)</h3>
            <ul class="text-sm space-y-1">
                <li>أول استجابة: {{ $fmt($a['response']['first_response_hours'], ' ساعة') }}</li>
                <li>الحسم: {{ $fmt($a['response']['resolution_hours'], ' ساعة') }}</li>
                <li class="text-xs text-gray-500">عيّنة: {{ $a['response']['sample'] }} حالة</li>
            </ul>
        </div>
        <div data-safety-appeals>
            <h3 class="font-bold mb-2">الاعتراضات</h3>
            <ul class="text-sm space-y-1">
                <li>المقدَّمة: {{ $ap['filed'] }} · مقبولة: {{ $ap['accepted'] }} · مرفوضة: {{ $ap['rejected'] }}</li>
                <li>معدل القبول: {{ $fmt($ap['acceptance_rate'], '%') }} · متوسط زمن القرار: {{ $fmt($ap['avg_decision_hours'], ' ساعة') }}</li>
                <li>تجاوزات تضارب المراجعة: {{ $ap['conflict_overrides'] }}</li>
                @foreach ($ap['by_status'] as $label => $count)<li class="text-xs text-gray-500">{{ $label }}: {{ $count }}</li>@endforeach
            </ul>
        </div>
    </div>

    <div data-safety-repeat>
        <h3 class="font-bold mb-2">مواضيع تكرّر البلاغ عنها</h3>
        <p class="text-xs text-gray-500 mb-2">معلومة للفرز البشري فقط: تعدّد البلاغات ليس إدانة وقد يكون منسَّقًا ضد بريء.</p>
        <table class="w-full text-sm">
            <thead><tr class="text-gray-500"><th class="text-start">الموضوع</th><th>بلاغات</th><th>مبلِّغون فريدون</th></tr></thead>
            <tbody>
            @forelse ($a['repeat_subjects'] as $r)
                <tr class="border-t border-gray-100 dark:border-white/10"><td dir="ltr" class="text-start">{{ $r['subject_type'] }} #{{ $r['subject_id'] }}</td><td class="text-center">{{ $r['report_count'] }}</td><td class="text-center">{{ $r['unique_reporters'] }}</td></tr>
            @empty
                <tr><td colspan="3" class="text-gray-500 py-2">لا مواضيع متكررة.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
