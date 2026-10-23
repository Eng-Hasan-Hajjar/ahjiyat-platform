<x-filament-panels::page>
    @php
        $d = $this->data();
        $o = $d['opened'];
        $fmt = fn (int $minor, string $c) => \App\Services\CreatorFinance\CreatorMoney::format($minor, $c);
        $inputClass = 'rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm w-full';
        $reasons = ['not_enrolled' => 'غير مُفعَّل للتسييل', 'paused' => 'تسييل موقوف', 'currency_mismatch' => 'عملة الحساب تخالف الفترة', 'profile_not_approved' => 'ملف غير معتمَد', 'creator_frozen' => 'حساب مجمَّد', 'below_min_units' => 'دون حدّ الوحدات', 'below_min_players' => 'دون حدّ اللاعبين', 'profile_missing' => 'ملف مفقود'];
    @endphp

    <p class="text-xs text-gray-500">المجمّع قرار إداري صريح بعملة محاسبة واحدة. الإقفال يوزّع المجمّع على المبدعين المؤهَّلين بنسبة وحداتهم (لاعب × عمل إجابة صحيحة، بسقف لكل لاعب) ويكتب الدفتر؛ <strong>لا رجعة بعده</strong> ولا إقفال تلقائي. الشهرة والسمعة والدعم لا تدخل في الحساب.</p>

    @if ($d['canManage'])
        <div><button type="button" wire:click="startCreate" class="rounded-lg bg-primary-600 text-white px-4 py-2 text-sm font-bold">فترة جديدة</button></div>
    @endif

    @if ($creating)
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-period-form>
            <input type="text" wire:model="label" maxlength="120" placeholder="عنوان الفترة" class="{{ $inputClass }}">
            <div class="grid md:grid-cols-3 gap-3">
                <label class="text-xs text-gray-500">البداية<input type="datetime-local" wire:model="startsAt" class="{{ $inputClass }}"></label>
                <label class="text-xs text-gray-500">النهاية (حصرية)<input type="datetime-local" wire:model="endsAt" class="{{ $inputClass }}"></label>
                <label class="text-xs text-gray-500">المجمّع ({{ \App\Services\CreatorFinance\CreatorMoney::currency() }})<input type="text" wire:model="pool" dir="ltr" inputmode="decimal" placeholder="0.00" class="{{ $inputClass }}"></label>
            </div>
            <input type="text" wire:model="note" maxlength="500" placeholder="ملاحظة داخلية اختيارية" class="{{ $inputClass }}">
            <button type="button" wire:click="save" class="rounded-lg bg-primary-600 text-white px-4 py-2 text-sm font-bold">حفظ كمسودة</button>
        </div>
    @endif

    <table class="w-full text-sm" data-period-list>
        <thead><tr class="text-gray-500"><th class="text-start">العنوان</th><th>الفترة</th><th>المجمّع</th><th>الحالة</th><th></th></tr></thead>
        <tbody>
        @forelse ($d['periods'] as $p)
            <tr class="border-t border-gray-100 dark:border-white/10">
                <td>{{ $p->label }}</td>
                <td class="text-center text-xs" dir="ltr">{{ $p->starts_at?->format('Y-m-d') }} → {{ $p->ends_at?->format('Y-m-d') }}</td>
                <td class="text-center">{{ $fmt((int) $p->pool_minor, $p->currency) }}</td>
                <td class="text-center">{{ $p->statusLabel() }}</td>
                <td><button type="button" wire:click="show('{{ $p->public_id }}')" class="text-primary-600 text-xs font-bold">عرض</button></td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-gray-500 py-4">لا فترات بعد.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div>{{ $d['periods']->links() }}</div>

    @if ($o)
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-period-detail>
            <div class="font-bold">{{ $o->label }} — {{ $o->statusLabel() }}</div>
            <div class="text-xs text-gray-500" dir="ltr">{{ $o->public_id }}</div>
            <div class="text-sm">المجمّع: {{ $fmt((int) $o->pool_minor, $o->currency) }}@if ($o->isFinalized()) — موزَّع: {{ $fmt((int) $o->allocated_minor, $o->currency) }} — غير موزَّع: {{ $fmt((int) $o->unallocated_minor, $o->currency) }} — مبدعون: {{ (int) $o->eligible_creators }} — وحدات: {{ number_format((int) $o->total_units) }}@endif</div>
            @if ($o->note)<div class="text-xs text-gray-500">ملاحظة: {{ $o->note }}</div>@endif
            @if ($o->cancel_reason)<div class="text-xs text-gray-500">سبب الإلغاء: {{ $o->cancel_reason }}</div>@endif

            <button type="button" wire:click="togglePreview" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm">{{ $showPreview ? 'إخفاء المعاينة' : 'معاينة التوزيع (قراءة فقط)' }}</button>

            @if ($d['preview'])
                @php $s = $d['preview']['summary']; @endphp
                <div class="text-sm" data-period-preview>
                    موزَّع: {{ $fmt((int) $s['allocated_minor'], $o->currency) }} — غير موزَّع: {{ $fmt((int) $s['unallocated_minor'], $o->currency) }} — مبدعون مؤهَّلون: {{ (int) $s['eligible_creators'] }} — وحدات: {{ number_format((int) $s['total_units']) }}
                    @foreach ((array) ($s['ineligible'] ?? []) as $reason => $n)
                        <div class="text-xs text-gray-500">مستبعَد ({{ $reasons[$reason] ?? $reason }}): {{ $n }}</div>
                    @endforeach
                </div>
                <table class="w-full text-xs">
                    <thead><tr class="text-gray-500"><th>مبدع #</th><th>وحدات خام</th><th>وحدات محتسبة</th><th>لاعبون</th><th>الحالة</th><th>الحصة</th></tr></thead>
                    <tbody>
                    @foreach (array_slice($d['preview']['rows'], 0, 100) as $r)
                        <tr class="border-t border-gray-100 dark:border-white/10 text-center">
                            <td>{{ $r['creator_profile_id'] }}</td><td>{{ $r['raw_units'] }}</td><td>{{ $r['qualified_units'] }}</td><td>{{ $r['unique_players'] }}</td>
                            <td>{{ $r['eligible'] ? 'مؤهَّل' : ($reasons[$r['reason']] ?? $r['reason']) }}</td><td>{{ $fmt((int) $r['allocated_minor'], $o->currency) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

            @if ($d['canManage'] && $o->isDraft())
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="startEdit" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm">تعديل</button>
                    <button type="button" wire:click="submitReview" class="rounded-lg bg-primary-600 text-white px-3 py-1.5 text-sm font-bold">إرسال للمراجعة</button>
                </div>
            @endif
            @if ($d['canManage'] && $o->status === 'review')
                <button type="button" wire:click="sendBack" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm">إعادة إلى المسودة</button>
            @endif
            @if ($d['canFinalize'] && $o->status === 'review')
                <div class="space-y-2 rounded-lg border border-amber-300 p-3" data-finalize-box>
                    <label class="text-sm"><input type="checkbox" wire:model="confirmFinalize"> أؤكد إقفال الفترة وتوزيع المجمّع (لا رجعة بعده).</label>
                    <div><button type="button" wire:click="finalizePeriod" class="rounded-lg bg-emerald-600 text-white px-4 py-2 text-sm font-bold">إقفال الفترة</button></div>
                </div>
            @endif
            @if ($d['canManage'] && in_array($o->status, ['draft', 'review'], true))
                <div class="space-y-2">
                    <input type="text" wire:model="cancelReason" maxlength="500" placeholder="سبب الإلغاء (8 أحرف فأكثر)" class="{{ $inputClass }}">
                    <button type="button" wire:click="cancelPeriod" class="rounded-lg bg-red-600 text-white px-3 py-1.5 text-sm font-bold">إلغاء الفترة</button>
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
