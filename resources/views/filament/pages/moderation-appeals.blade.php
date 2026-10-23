<x-filament-panels::page>
    @php
        $d = $this->data();
        $page = $d['page'];
        $o = $d['opened'];
        $inputClass = 'rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm w-full';
        $tabs = ['active' => 'الفعّالة', 'pending' => 'بانتظار المراجعة', 'in_review' => 'قيد المراجعة', 'mine' => 'لي', 'decided' => 'المحسومة', 'all' => 'الكل'];
    @endphp

    <p class="text-xs text-gray-500">لا يُراجع اعتراضٌ من نفّذ الإجراء الأصلي (إلا مشرف أعلى بتجاوز موسوم ومسجَّل). القبول يعكس الإجراء عبر خدمة المجال الرسمية دون حذف التاريخ. نص الاعتراض نص عادي.</p>

    <div class="flex flex-wrap gap-2" data-appeal-tabs>
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" class="rounded-lg px-3 py-1.5 text-sm {{ $tab === $key ? 'bg-primary-600 text-white font-bold' : 'border border-gray-300 dark:border-white/10' }}">{{ $label }}@if (in_array($key, ['pending', 'in_review', 'mine'], true)) ({{ $d['counts'][$key] ?? 0 }})@endif</button>
        @endforeach
    </div>

    <table class="w-full text-sm" data-appeal-list>
        <thead><tr class="text-gray-500"><th class="text-start">الإجراء</th><th>الحالة الإشرافية</th><th>المعترِض</th><th>الوضع</th><th>المراجِع</th><th>قُدِّم</th><th></th></tr></thead>
        <tbody>
        @forelse ($page as $ap)
            <tr class="border-t border-gray-100 dark:border-white/10">
                <td>{{ $d['labels'][$ap->action->action] ?? $ap->action->action }}</td>
                <td class="text-center" dir="ltr">{{ $ap->action->moderationCase?->case_number }}</td>
                <td class="text-center">{{ $ap->appellant?->name ?? 'محذوف' }}</td>
                <td class="text-center">{{ $ap->statusLabel() }}@if ($ap->conflict_flag)<div class="text-xs text-amber-600">تجاوز تضارب</div>@endif</td>
                <td class="text-center">{{ $ap->reviewer?->name ?? '—' }}</td>
                <td class="text-center text-xs">{{ $ap->created_at?->format('Y-m-d H:i') }}</td>
                <td><button type="button" wire:click="show('{{ $ap->public_id }}')" class="text-primary-600 text-xs font-bold">عرض</button></td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-gray-500 py-4">لا اعتراضات في هذا العرض.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div>{{ $page->links() }}</div>

    @if ($o)
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-appeal-detail>
            <div class="font-bold">اعتراض على: {{ $d['labels'][$o->action->action] ?? $o->action->action }} — <span dir="ltr">{{ $o->action->moderationCase?->case_number }}</span></div>
            <div class="text-xs text-gray-500">السبب الداخلي الأصلي: {{ $o->action->reason }} · الرسالة العامة التي رآها المستخدم: {{ $o->action->public_note ?? '—' }}</div>
            <div class="text-sm whitespace-pre-line rounded-lg bg-gray-50 dark:bg-white/5 p-3">{{ $o->reason }}</div>
            @if ($o->decision_message)<div class="text-xs text-gray-500">القرار: {{ $o->decision_message }}</div>@endif

            @if ($d['canReview'] && $o->isActive())
                @if ($o->reviewer_id === null)
                    <button type="button" wire:click="claim" class="rounded-lg bg-primary-600 text-white px-4 py-2 text-sm font-bold">حجز للمراجعة</button>
                @elseif ((int) $o->reviewer_id === (int) $d['me'])
                    <div class="space-y-2">
                        <textarea wire:model="decisionMessage" rows="2" maxlength="800" placeholder="رسالة القرار للمستخدم (نص عادي، بلا ملاحظات داخلية)" class="{{ $inputClass }}"></textarea>
                        <input type="text" wire:model="decisionNote" maxlength="800" placeholder="ملاحظة داخلية اختيارية" class="{{ $inputClass }}">
                        <div class="flex gap-2">
                            <button type="button" wire:click="decide(true)" class="rounded-lg bg-emerald-600 text-white px-4 py-2 text-sm font-bold">قبول وعكس الإجراء</button>
                            <button type="button" wire:click="decide(false)" class="rounded-lg bg-red-600 text-white px-4 py-2 text-sm font-bold">رفض</button>
                        </div>
                    </div>
                @else
                    <div class="text-xs text-gray-500">محجوز لمراجِع آخر: {{ $o->reviewer?->name }}.</div>
                @endif
            @endif
        </div>
    @endif
</x-filament-panels::page>
