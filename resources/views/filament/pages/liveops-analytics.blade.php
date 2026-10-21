<x-filament-panels::page>
    @php $d = $this->data(); $o = $d['overview']; $pct = fn ($r) => $r === null ? '—' : round($r * 100, 1).'%'; @endphp

    <div class="flex items-center gap-2 text-sm">
        <span>النافذة:</span>
        @foreach ([7, 30, 90] as $opt)
            <button type="button" wire:click="$set('days', {{ $opt }})" class="px-3 py-1 rounded-lg border {{ $d['days'] === $opt ? 'bg-primary-600 text-white' : 'border-gray-300' }}">{{ $opt }} يومًا</button>
        @endforeach
    </div>

    <p class="text-xs text-gray-500">
        "لعب ذو معنى" = محاولة أحجية رسمية صحيحة (لا تسجيل الدخول، ولا أحجيات المجتمع). مجاميع فقط بلا أسماء. اليوم = التاريخ المحلي للمنصة.
        الصف يُنشأ كسولًا عند فتح مركز المهام أو حدث لعب، فمقام الإكمال = المعرَّضون للمهمة وليس كل المسجَّلين.
    </p>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-liveops-stats>
        @foreach ([
            'إكمال المهام اليومية' => $pct($o['mission_completion']['daily']['rate']).' ('.$o['mission_completion']['daily']['completed'].'/'.$o['mission_completion']['daily']['total'].')',
            'إكمال المهام الأسبوعية' => $pct($o['mission_completion']['weekly']['rate']).' ('.$o['mission_completion']['weekly']['completed'].'/'.$o['mission_completion']['weekly']['total'].')',
            'مشاركون بالمهام (أحدث يوم)' => $o['participation']['daily']['latest'],
            'مشاركون بالمهام (أحدث أسبوع)' => $o['participation']['weekly']['latest'],
            'مسجَّلو المنافسات' => $o['event_participation']['competitive_users'],
            'مشاركو الحملات' => $o['event_participation']['campaign_users'],
            'العائدون' => $o['returners']['returners'].' من '.$o['returners']['active_in_window'],
            'نسبة العودة (غياب ≥ '.$o['returners']['min_inactive_days'].' أيام)' => $pct($o['returners']['rate']),
        ] as $label => $value)
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <div class="text-xs text-gray-500">{{ $label }}</div>
                <div class="text-xl font-bold mt-1">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div>
            <h3 class="font-bold mb-2">الاحتفاظ (يوم محدّد بالضبط، لأفواج منضجة)</h3>
            <table class="w-full text-sm" data-liveops-retention>
                <thead><tr class="text-gray-500"><th class="text-start">المؤشر</th><th>الفوج</th><th>عادوا</th><th>النسبة</th></tr></thead>
                <tbody>
                @foreach ($o['retention'] as $k => $r)
                    <tr class="border-t"><td>{{ strtoupper($k) }}</td><td class="text-center">{{ $r['cohort'] }}</td><td class="text-center">{{ $r['retained'] }}</td><td class="text-center">{{ $r['rate'] === null ? 'بيانات غير كافية' : $pct($r['rate']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div>
            <h3 class="font-bold mb-2">توزيع السلاسل الفعّالة</h3>
            <table class="w-full text-sm" data-liveops-streaks>
                <thead><tr class="text-gray-500"><th class="text-start">الشريحة (أيام)</th><th>لاعبون</th></tr></thead>
                <tbody>
                @foreach ($o['streak_distribution'] as $bucket => $n)
                    <tr class="border-t"><td>{{ $bucket === 'none_or_broken' ? 'بلا سلسلة فعّالة' : $bucket }}</td><td class="text-center">{{ $n }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
