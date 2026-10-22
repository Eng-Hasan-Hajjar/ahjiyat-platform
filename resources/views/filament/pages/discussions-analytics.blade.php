<x-filament-panels::page>
    @php $d = $this->data(); @endphp

    <p class="text-xs text-gray-500">
        أرقام مجمَّعة تُحدَّث كل بضع دقائق (آخر حساب: {{ $d['generated_at'] }}). لا أسماء ولا نصوص ولا هوية كاتب أو مبلِّغ. التعليق والرد والإعجاب لا تمنح أي جواهر أو نقاط أو مهام.
    </p>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-discussions-stats>
        @foreach ([
            'نقاشات' => $d['threads'],
            'نقاشات مقفلة' => $d['locked_threads'],
            'تعليقات وردود ظاهرة' => $d['live_comments'],
            'منها ردود' => $d['replies'],
            'مخفية إشرافيًا' => $d['hidden'],
            'محذوفة' => $d['deleted'],
            'إعجابات' => $d['likes'],
            'بلاغات مفتوحة' => $d['open_reports'],
        ] as $label => $value)
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <div class="text-xs text-gray-500">{{ $label }}</div>
                <div class="text-xl font-bold mt-1">{{ number_format((int) $value) }}</div>
            </div>
        @endforeach
    </div>

    <div>
        <h3 class="font-bold mb-2">النقاشات حسب نوع الموضوع</h3>
        <table class="w-full text-sm" data-discussions-by-type>
            <thead><tr class="text-gray-500"><th class="text-start">النوع</th><th>العدد</th></tr></thead>
            <tbody>
            @foreach ($d['by_type'] as $type => $count)
                <tr><td>{{ \App\Models\DiscussionThread::TYPES[$type] ?? $type }}</td><td class="text-center">{{ number_format((int) $count) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div>
        <h3 class="font-bold mb-2">التعليقات المنشورة يوميًا (آخر {{ count($d['per_day']) }} يومًا)</h3>
        <table class="w-full text-sm" data-discussions-per-day>
            <thead><tr class="text-gray-500"><th class="text-start">اليوم</th><th>تعليقات وردود</th></tr></thead>
            <tbody>
            @foreach ($d['per_day'] as $day => $count)
                <tr><td>{{ $day }}</td><td class="text-center">{{ number_format((int) $count) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
