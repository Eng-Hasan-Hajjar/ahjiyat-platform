<x-filament-panels::page>
    @php $d = $this->data(); $o = $d['overview']; @endphp

    <div class="flex items-center gap-2 text-sm">
        <span>الفترة (دفعة الإحالات المُسنَدة خلالها):</span>
        @foreach ([7, 30, 90] as $opt)
            <button type="button" wire:click="$set('days', {{ $opt }})" class="px-3 py-1 rounded-lg border {{ $d['days'] === $opt ? 'bg-primary-600 text-white' : 'border-gray-300' }}">{{ $opt }} يومًا</button>
        @endforeach
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-referral-admin-stats>
        @foreach ([
            'مُسنَدة' => $o['attributed'], 'بريد موثَّق' => $o['verified'], 'مؤهَّلة' => $o['qualified'], 'بانتظار المراجعة' => $o['under_review'],
            'مستبعدة' => $o['disqualified'], 'مكافَأة' => $o['rewarded'], 'تكلفة الجواهر المسلَّمة' => number_format($o['reward_cost_gems']),
            'نسبة التحويل' => $o['conversion_pct'] === null ? '—' : $o['conversion_pct'].'%',
        ] as $label => $value)
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <div class="text-xs text-gray-500">{{ $label }}</div>
                <div class="text-2xl font-bold mt-1">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <p class="text-xs text-gray-500">
        النقرات التقريبية (زائر فريد في الساعة، تراكمي مدى الحياة ولا تدخل في نسبة التحويل): {{ number_format($o['clicks_approx_lifetime']) }}
        · مشاركات آخر 7 أيام (عدّادات تقريبية): {{ number_format($o['shares_7d']['total']) }}.
        كل الأرقام أعلاه لدفعة الإحالات المُسنَدة في الفترة، فنسبة التحويل = مؤهَّلة ÷ مُسنَدة لنفس الدفعة.
    </p>

    <div class="grid md:grid-cols-2 gap-6">
        <div>
            <h3 class="font-bold mb-2">أداء الحملات</h3>
            <table class="w-full text-sm" data-referral-admin-campaigns>
                <thead><tr class="text-start text-gray-500"><th class="text-start">الحملة</th><th>مُسنَدة</th><th>مؤهَّلة</th><th>مكافَأة</th><th>جواهر</th></tr></thead>
                <tbody>
                @forelse ($d['campaigns'] as $row)
                    <tr class="border-t"><td>{{ $row['name'] }}</td><td class="text-center">{{ $row['attributed'] }}</td><td class="text-center">{{ $row['qualified'] }}</td><td class="text-center">{{ $row['rewarded'] }}</td><td class="text-center">{{ number_format($row['cost_gems']) }}</td></tr>
                @empty
                    <tr><td colspan="5" class="text-gray-500 py-3">لا بيانات في هذه الفترة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div>
            <h3 class="font-bold mb-2">أوائل المحيلين (بعدد المؤهَّلة فقط · للمشرفين)</h3>
            <table class="w-full text-sm" data-referral-admin-top>
                <thead><tr><th class="text-start">المحيل</th><th>مؤهَّلة</th></tr></thead>
                <tbody>
                @forelse ($d['top'] as $row)
                    <tr class="border-t"><td>{{ $row['name'] }}</td><td class="text-center">{{ $row['qualified'] }}</td></tr>
                @empty
                    <tr><td colspan="2" class="text-gray-500 py-3">لا إحالات مؤهَّلة في هذه الفترة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
