<x-filament-panels::page>
    @php $d = $this->data(); @endphp

    <p class="text-xs text-gray-500">
        أرقام مجمَّعة تُحدَّث كل بضع دقائق (آخر حساب: {{ $d['generated_at'] }}). لا أسماء ولا هوية من حفظ مجموعة ولا مشاهدات. الحفظ لا يمنح أي مكافأة.
    </p>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-collections-stats>
        @foreach ([
            'مجموعات عامة' => $d['by_visibility']['public'] ?? 0,
            'مجموعات غير مدرجة' => $d['by_visibility']['unlisted'] ?? 0,
            'مجموعات خاصة' => $d['by_visibility']['private'] ?? 0,
            'مخفية إشرافيًا' => $d['hidden'],
            'إجمالي الحفظ' => $d['saves_total'],
            'بلاغات مفتوحة' => $d['open_reports'],
            'أرفف فعّالة' => $d['shelves']['active'] ?? 0,
            'أرفف مسودة' => $d['shelves']['draft'] ?? 0,
        ] as $label => $value)
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <div class="text-xs text-gray-500">{{ $label }}</div>
                <div class="text-xl font-bold mt-1">{{ number_format((int) $value) }}</div>
            </div>
        @endforeach
    </div>

    <div>
        <h3 class="font-bold mb-2">الأعلى حفظًا (العامة غير المخفية)</h3>
        @if ($d['top'] === [])
            <p class="text-sm text-gray-500">لا مجموعات عامة بعد.</p>
        @else
            <table class="w-full text-sm" data-collections-top>
                <thead><tr class="text-gray-500"><th class="text-start">المجموعة</th><th>الحفظ</th></tr></thead>
                <tbody>
                @foreach ($d['top'] as $row)
                    <tr><td>{{ $row['title'] }}</td><td class="text-center">{{ number_format($row['saves_count']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-filament-panels::page>
