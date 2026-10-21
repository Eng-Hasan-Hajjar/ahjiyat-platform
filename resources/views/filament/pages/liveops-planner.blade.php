<x-filament-panels::page>
    @php $d = $this->data(); @endphp

    <p class="text-xs text-gray-500">التوقيت المعتمد للمنصة: {{ $d['timezone'] }}. هذه الصفحة للقراءة فقط؛ لا تغيّر أي شيء.</p>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-liveops-planner-stats>
        @foreach ([
            'مهام يومية فعّالة' => $d['quests']['daily']['active'],
            'مهام أسبوعية فعّالة' => $d['quests']['weekly']['active'],
            'أعلى مهام متزامنة نظريًا للاعب' => $d['potential']['missions'],
            'أعلى جواهر يومية نظريًا' => number_format($d['potential']['daily_reward']),
            'أعلى جواهر أسبوعية نظريًا' => number_format($d['potential']['weekly_reward']),
        ] as $label => $value)
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <div class="text-xs text-gray-500">{{ $label }}</div>
                <div class="text-2xl font-bold mt-1">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div data-liveops-warnings>
        <h3 class="font-bold mb-2">تنبيهات الجدولة</h3>
        @forelse ($d['warnings'] as $w)
            <div class="rounded-lg border p-3 mb-2 text-sm {{ $w['level'] === 'critical' ? 'border-danger-500' : ($w['level'] === 'warning' ? 'border-warning-500' : 'border-gray-300') }}" data-warning="{{ $w['code'] }}">
                {{ $w['message'] }}
            </div>
        @empty
            <p class="text-sm text-gray-500">لا تنبيهات. الجدولة الحالية ضمن الحدود.</p>
        @endforelse
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div>
            <h3 class="font-bold mb-2">مجموعات المهام</h3>
            <table class="w-full text-sm" data-liveops-pools>
                <thead><tr class="text-gray-500"><th class="text-start">المجموعة</th><th>الفترة</th><th>الاختيار</th><th>مهام</th><th>الحالة</th></tr></thead>
                <tbody>
                @forelse ($d['pools'] as $p)
                    <tr class="border-t"><td>{{ $p->name }}</td><td class="text-center">{{ $p->period_type === 'weekly' ? 'أسبوعية' : 'يومية' }}</td><td class="text-center">{{ $p->selection_count }}</td><td class="text-center">{{ $p->quests_count }}</td><td class="text-center">{{ $p->is_active ? 'فعّالة' : 'معطّلة' }}</td></tr>
                @empty
                    <tr><td colspan="5" class="text-gray-500 py-3">لا مجموعات. المهام الفعّالة كلها عامة لكل اللاعبين.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div>
            <h3 class="font-bold mb-2">الأحداث (من التقويم نفسه)</h3>
            <table class="w-full text-sm" data-liveops-events>
                <thead><tr class="text-gray-500"><th class="text-start">الحدث</th><th>النوع</th><th>الحالة</th><th>البداية</th><th>النهاية</th></tr></thead>
                <tbody>
                @forelse ($d['events']->reject(fn ($e) => $e->status === 'ended') as $e)
                    <tr class="border-t"><td>{{ $e->title }}</td><td class="text-center">{{ $e->badge }}</td><td class="text-center">{{ $e->statusLabel() }}</td><td class="text-center">{{ $e->startsAt?->format('Y-m-d H:i') ?? '—' }}</td><td class="text-center">{{ $e->endsAt?->format('Y-m-d H:i') ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="5" class="text-gray-500 py-3">لا أحداث جارية أو قادمة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
