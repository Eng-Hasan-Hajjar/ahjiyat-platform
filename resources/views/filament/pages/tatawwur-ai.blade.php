<x-filament-panels::page>
    @php
        $d = $this->data();
        $s = $d['status'];
        $o = $d['overview'];
        $yes = fn (bool $v) => $v ? 'نعم' : 'لا';
        $outcomeLabels = ['answered' => 'أُجيب', 'abstained' => 'امتنع (لا دليل)', 'refused' => 'رُفض (أمان)', 'fallback' => 'احتياطي (دليل المنصة)', 'out_of_scope' => 'خارج النطاق', 'error' => 'خطأ', 'limited' => 'محدود (معدّل/ميزانية)'];
    @endphp

    <p class="text-xs text-gray-500" data-tatawwur-note>
        أرقام مجمَّعة فقط: لا نص أسئلة ولا ردود ولا هوية مستخدمين، ولا مفاتيح. إعدادات المزوّد والنموذج والحدود والأعلام من بيئة الخادم وليست قابلة للتعديل هنا.
    </p>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-tatawwur-status>
        @foreach ([
            'المساعد مفعّل' => $yes($s['enabled']),
            'المزوّد الخارجي مفعّل' => $yes($s['provider_enabled']),
            'المزوّد جاهز' => $yes($s['provider_ready']),
            'النموذج مضبوط' => $yes($s['model_configured']),
            'الضيوف' => $yes($s['guests_enabled']),
            'البثّ' => $yes($s['streaming_enabled']),
            'الوضع' => $s['mode'] === 'ai' ? 'ذكاء + دليل' : 'دليل المنصة فقط',
            'مدة الاحتفاظ (يوم)' => $s['retention_days'],
            'رموز اليوم / الحد' => number_format($s['tokens_today']).' / '.number_format($s['tokens_limit']),
        ] as $label => $value)
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <div class="text-xs text-gray-500">{{ $label }}</div>
                <div class="text-lg font-bold mt-1">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="flex flex-wrap items-center gap-2" data-tatawwur-range>
        <span class="text-sm text-gray-500">النافذة:</span>
        @foreach ([1 => 'يوم', 7 => '7 أيام', 30 => '30 يومًا', 90 => '90 يومًا'] as $n => $label)
            <x-filament::button size="sm" :color="$this->days === $n ? 'primary' : 'gray'" wire:click="setDays({{ $n }})">{{ $label }}</x-filament::button>
        @endforeach
        @if ($d['canPublish'])
            <x-filament::button size="sm" color="gray" wire:click="rebuild" wire:confirm="إعادة بناء فهرس المقالات المنشورة؟">إعادة بناء الفهرس</x-filament::button>
        @endif
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-tatawwur-usage>
        @foreach ([
            'الطلبات' => number_format($o['total']),
            'مبنية على الدليل %' => $o['rates']['grounded'].'%',
            'امتناع %' => $o['rates']['abstained'].'%',
            'احتياطي %' => $o['rates']['fallback'].'%',
            'أخطاء %' => $o['rates']['error'].'%',
            'رموز الإدخال' => number_format($o['tokens_in']),
            'رموز الإخراج' => number_format($o['tokens_out']),
            'زمن متوسط (مللي ثانية)' => number_format($o['latency_avg_ms']),
            'أعلى زمن (مللي ثانية)' => number_format($o['latency_max_ms']),
            'استدعاءات الأدوات' => number_format($o['tool_calls']),
            'ردود مبثوثة' => number_format($o['streamed']),
            'محادثات محفوظة نشطة' => number_format($o['active_conversations']),
        ] as $label => $value)
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <div class="text-xs text-gray-500">{{ $label }}</div>
                <div class="text-xl font-bold mt-1">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div>
            <h3 class="font-bold mb-2">النتائج</h3>
            <table class="w-full text-sm" data-tatawwur-outcomes>
                <thead><tr class="text-gray-500"><th class="text-start">النتيجة</th><th>العدد</th></tr></thead>
                <tbody>
                @forelse ($o['by_outcome'] as $outcome => $count)
                    <tr><td>{{ $outcomeLabels[$outcome] ?? $outcome }}</td><td class="text-center">{{ number_format($count) }}</td></tr>
                @empty
                    <tr><td colspan="2" class="text-gray-500">لا بيانات في هذه النافذة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div>
            <h3 class="font-bold mb-2">فجوات المعرفة (ما امتنع عنه المساعد)</h3>
            <table class="w-full text-sm" data-tatawwur-gaps>
                <thead><tr class="text-gray-500"><th class="text-start">المجال</th><th>مرات الامتناع</th></tr></thead>
                <tbody>
                @forelse ($o['gaps'] as $gap)
                    <tr><td>{{ $gap['area'] }}</td><td class="text-center">{{ number_format($gap['count']) }}</td></tr>
                @empty
                    <tr><td colspan="2" class="text-gray-500">لا فجوات مسجّلة.</td></tr>
                @endforelse
                </tbody>
            </table>
            <p class="text-xs text-gray-500 mt-2">أضف أو حسّن مقالات هذه المجالات في «معرفة تطوّر AI» ثم انشرها.</p>
        </div>
    </div>

    <div class="grid md:grid-cols-3 gap-6">
        <div>
            <h3 class="font-bold mb-2">أسباب الأعطال</h3>
            <table class="w-full text-sm" data-tatawwur-errors>
                <tbody>
                @forelse ($o['errors'] as $category => $count)
                    <tr><td>{{ $category }}</td><td class="text-center">{{ number_format($count) }}</td></tr>
                @empty
                    <tr><td class="text-gray-500">لا أعطال.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div>
            <h3 class="font-bold mb-2">التقييمات</h3>
            <table class="w-full text-sm" data-tatawwur-feedback>
                <tbody>
                    <tr><td>مفيد</td><td class="text-center">{{ number_format($o['ratings']['up'] ?? 0) }}</td></tr>
                    <tr><td>غير مفيد</td><td class="text-center">{{ number_format($o['ratings']['down'] ?? 0) }}</td></tr>
                    @foreach ($o['reasons'] as $reason => $count)
                        <tr><td class="ps-4 text-gray-500">{{ $d['reasons'][$reason] ?? $reason }}</td><td class="text-center">{{ number_format($count) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div>
            <h3 class="font-bold mb-2">مقالات المعرفة</h3>
            <table class="w-full text-sm" data-tatawwur-articles>
                <tbody>
                    <tr><td>منشورة</td><td class="text-center">{{ number_format($o['articles']['published'] ?? 0) }}</td></tr>
                    <tr><td>مسودات</td><td class="text-center">{{ number_format($o['articles']['draft'] ?? 0) }}</td></tr>
                    <tr><td>مسحوبة</td><td class="text-center">{{ number_format($o['articles']['retired'] ?? 0) }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    @if ($o['per_day'] !== [])
        <div>
            <h3 class="font-bold mb-2">الطلبات يوميًا (آخر 7 أيام)</h3>
            <table class="w-full text-sm" data-tatawwur-daily>
                <tbody>
                @foreach ($o['per_day'] as $day => $count)
                    <tr><td>{{ $day }}</td><td class="text-center">{{ number_format($count) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
