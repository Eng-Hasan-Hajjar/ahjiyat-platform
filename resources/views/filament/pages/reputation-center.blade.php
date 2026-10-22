<x-filament-panels::page>
    @php
        $d = $this->data();
        $found = $this->inspected();
        $canManage = auth()->user()?->can('reputation.manage');
        $canFeature = auth()->user()?->can('reputation.feature');
    @endphp

    <p class="text-xs text-gray-500">
        السمعة اعتراف مشتقّ من سجلات قائمة (تقييمات/مفضّلات/متابعات/حفظ/إعجابات/أعمال ظاهرة) ولا تتحكم بأي صلاحية أو محفظة أو جواهر أو XP أو دفع. لا تُعدَّل درجة يدويًا من هنا.
        أرقام مجمَّعة (آخر حساب: {{ $d['generated_at'] }}) والصيغة v{{ $d['formula_version'] }} {{ $d['enabled'] ? '' : '— الميزة معطّلة' }}.
    </p>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-reputation-stats>
        @foreach ([
            'لقطات' => $d['snapshots'],
            'مبدعون مؤهَّلون' => $d['eligible']['creators'],
            'صاعدون مؤهَّلون' => $d['eligible']['rising'],
            'منسّقون مؤهَّلون' => $d['eligible']['curators'],
            'مساهمون مفيدون مؤهَّلون' => $d['eligible']['helpful'],
            'لقطات قديمة (تنتظر التحديث)' => $d['stale'],
            'صيغة قديمة' => $d['outdated_formula'],
            'مستخدمون بلا لقطة'.($d['missing_capped'] ? ' (≥)' : '') => $d['missing_subjects'],
            'مبدعون مختارون' => $d['featured_active'].' / '.$d['featured_max'],
        ] as $label => $value)
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <div class="text-xs text-gray-500">{{ $label }}</div>
                <div class="text-xl font-bold mt-1">{{ is_numeric($value) ? number_format((int) $value) : $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div>
            <h3 class="font-bold mb-2">توزيع جودة المبدعين المؤهَّلين</h3>
            <table class="w-full text-sm" data-reputation-distribution>
                <thead><tr class="text-gray-500"><th class="text-start">الشريحة</th><th>العدد</th></tr></thead>
                <tbody>
                @foreach ($d['creator_quality_distribution'] as $bucket => $count)
                    <tr><td>{{ $bucket }}</td><td class="text-center">{{ number_format((int) $count) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div>
            <h3 class="font-bold mb-2">الاعتراف المفتوح (إنجازات بلا مكافآت)</h3>
            <table class="w-full text-sm" data-reputation-recognition>
                <thead><tr class="text-gray-500"><th class="text-start">الاعتراف</th><th>مفتوح</th></tr></thead>
                <tbody>
                @forelse ($d['recognition'] as $row)
                    <tr><td>{{ $row['name'] }}</td><td class="text-center">{{ number_format($row['unlocked']) }}</td></tr>
                @empty
                    <tr><td colspan="2" class="text-gray-500">لا تعريفات بعد. شغّل: php artisan db:seed --class=CommunityRecognitionSeeder</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>
        <h3 class="font-bold mb-2">فحص مستخدم</h3>
        <form wire:submit.prevent="search" class="flex flex-wrap gap-2 items-center">
            <input type="text" wire:model="lookup" dir="ltr" placeholder="slug المبدع أو المعرّف العام للاعب" class="rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm w-80" maxlength="80">
            <button type="submit" class="rounded-lg bg-primary-600 text-white px-4 py-2 text-sm font-bold">فحص</button>
        </form>

        @if ($found)
            <div class="mt-4 space-y-3" data-reputation-inspect>
                <div class="text-sm">
                    <strong>{{ $found['name'] }}</strong>
                    @if ($found['creator_slug']) · مبدع ({{ $found['creator_status'] }}) · <span dir="ltr">{{ $found['creator_slug'] }}</span> @endif
                    @if ($found['featured']) · <span class="font-bold text-amber-600">مبدع مختار</span> @endif
                </div>

                @if ($found['explain'] === null)
                    <p class="text-sm text-gray-500">لا لقطة بعد لهذا المستخدم (تُنشأ بالحساب المجدول أو بإعادة الحساب).</p>
                @else
                    @php $e = $found['explain']; @endphp
                    <p class="text-xs text-gray-500">محسوبة: {{ $e['calculated_at'] }} · صيغة v{{ $e['formula_version'] }} (الحالية v{{ $e['current_formula'] }})</p>

                    <table class="w-full text-sm" data-reputation-explain>
                        <thead><tr class="text-gray-500"><th class="text-start">البُعد</th><th>الدرجة</th><th>الأهلية للترتيب</th><th class="text-start">ما ينقص</th></tr></thead>
                        <tbody>
                        @foreach (['creator' => 'جودة المبدع', 'rising' => 'صاعد', 'curator' => 'منسّق', 'helpful' => 'مساهم مفيد'] as $key => $label)
                            <tr>
                                <td>{{ $label }}</td>
                                <td class="text-center">{{ $e[$key]['score'] }}%</td>
                                <td class="text-center">{{ $e[$key]['eligible'] ? 'مؤهَّل' : 'غير مؤهَّل' }}</td>
                                <td class="text-xs text-gray-500">{{ $e[$key]['gaps'] === [] ? '—' : implode(' · ', $e[$key]['gaps']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>

                    <div>
                        <h4 class="font-bold text-sm mb-1">مكوّنات جودة المبدع</h4>
                        <table class="w-full text-sm">
                            <tbody>
                            @foreach ($e['creator']['components'] as $c)
                                <tr><td>{{ $c['label'] }}</td><td class="text-center">{{ $c['pct'] }}%</td><td class="text-center text-gray-500">وزن {{ $c['weight'] }}</td></tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <h4 class="font-bold text-sm mb-1">مقاييس المصدر</h4>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
                            @foreach ($e['sources'] as $label => $value)
                                <div class="rounded-lg border border-gray-200 dark:border-white/10 px-3 py-2">{{ $label }}: <strong>{{ number_format($value) }}</strong></div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-2">
                    @if ($canManage)
                        <button type="button" wire:click="recalculate" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-2 text-sm font-bold" data-reputation-recalculate>إعادة حساب هذه اللقطة</button>
                    @endif
                    @if ($canFeature && $found['creator_slug'])
                        <input type="text" wire:model="note" maxlength="160" placeholder="ملاحظة داخلية (لا تُعرض علنًا)" class="rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm w-72">
                        <button type="button" wire:click="feature" class="rounded-lg bg-amber-500 text-white px-3 py-2 text-sm font-bold" data-reputation-feature>اختيار «مبدع مختار»</button>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <div>
        <h3 class="font-bold mb-2">المبدعون المختارون الآن</h3>
        <table class="w-full text-sm" data-reputation-featured>
            <thead><tr class="text-gray-500"><th class="text-start">المبدع</th><th class="text-start">ملاحظة داخلية</th><th>ينتهي</th><th></th></tr></thead>
            <tbody>
            @forelse ($this->featuredList() as $f)
                <tr>
                    <td>{{ $f['name'] }} <span dir="ltr" class="text-xs text-gray-500">{{ $f['slug'] }}</span></td>
                    <td class="text-xs text-gray-500">{{ $f['note'] ?? '—' }}</td>
                    <td class="text-center text-xs">{{ $f['until'] ?? 'مفتوح' }}</td>
                    <td class="text-center">
                        @if ($canFeature)
                            <button type="button" wire:click="unfeature('{{ $f['slug'] }}')" class="text-xs font-bold text-red-600">إنهاء</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-gray-500">لا مبدعين مختارين حاليًا.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
