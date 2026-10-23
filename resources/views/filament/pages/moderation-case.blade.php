<x-filament-panels::page>
    @php
        $d = $this->data();
        $case = $d['case'];
        $active = $case->isActive();
        $inputClass = 'rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm w-full';
        $btn = 'rounded-lg bg-primary-600 text-white px-4 py-2 text-sm font-bold';
        $btnAlt = 'rounded-lg border border-gray-300 dark:border-white/10 px-4 py-2 text-sm';
        $eventLabels = [
            'case_opened' => 'فُتحت الحالة', 'sources_attached' => 'رُبطت بلاغات', 'source_detached' => 'فُصل مصدر', 'claimed' => 'حجز الحالة',
            'released' => 'تخلٍّ عن الحالة', 'reassigned' => 'إعادة تعيين', 'unassigned' => 'إلغاء التعيين', 'status_changed' => 'تغيير الحالة',
            'priority_changed' => 'تغيير الأولوية', 'note_added' => 'ملاحظة', 'action_applied' => 'إجراء منفَّذ', 'action_reversed' => 'إجراء معكوس',
            'resolved' => 'حسم الحالة', 'closed' => 'إغلاق', 'reopened' => 'إعادة فتح', 'appeal_filed' => 'اعتراض مقدَّم', 'appeal_claimed' => 'اعتراض قيد المراجعة',
            'appeal_decided' => 'قرار في اعتراض', 'appeal_withdrawn' => 'سحب اعتراض', 'conflict_override' => 'تجاوز تضارب (مشرف أعلى)',
        ];
        $slaLabels = ['none' => '—', 'ok' => 'ضمن الهدف', 'due_soon' => 'يستحق قريبًا', 'overdue' => 'متأخرة'];
        $who = fn ($id) => $id === null ? 'النظام' : ($d['names'][$id] ?? 'مستخدم محذوف');
    @endphp

    <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-2" data-case-header>
        <div class="flex flex-wrap items-center gap-3">
            <span class="font-bold text-lg" dir="ltr">{{ $case->case_number }}</span>
            <span class="rounded bg-gray-100 dark:bg-white/10 px-2 py-0.5 text-xs">{{ $case->statusLabel() }}</span>
            <span class="rounded bg-gray-100 dark:bg-white/10 px-2 py-0.5 text-xs">الأولوية: {{ $case->priorityLabel() }}</span>
            <span class="rounded bg-gray-100 dark:bg-white/10 px-2 py-0.5 text-xs">{{ $case->categoryLabel() }}</span>
            <span class="text-xs text-gray-500">هدف المعالجة: {{ $slaLabels[$d['sla']] }} (اقتراح تشغيلي داخلي)</span>
        </div>
        <div class="text-sm">
            الموضوع: <strong>{{ $d['subject']['label'] ?? ($case->subject_label ?? '—') }}</strong>
            @if ($d['subject'] && ! $d['subject']['exists'])<span class="text-gray-400">(محذوف)</span>@endif
            @if ($d['subject_user_name']) · صاحب المحتوى/الحساب: {{ $d['subject_user_name'] }}@endif
        </div>
        @if ($case->summary)<div class="text-sm text-gray-700 dark:text-gray-300">{{ $case->summary }}</div>@endif
        <div class="text-xs text-gray-500">المعيَّن: {{ $d['assignee_name'] ?? 'غير معيَّنة' }} · أُنشئت {{ $case->created_at?->format('Y-m-d H:i') }}
            @if ($case->resolution) · القرار: {{ \App\Models\ModerationCase::RESOLUTIONS[$case->resolution] ?? $case->resolution }}@endif</div>
        <div class="flex flex-wrap gap-2 pt-1">
            @if ($active && $d['canManage'] && $case->assigned_to === null)
                <button type="button" wire:click="claim" class="{{ $btn }}">حجز الحالة</button>
            @endif
            @if ($active && $d['isMine'])
                <button type="button" wire:click="release" class="{{ $btnAlt }}">التخلّي عن الحالة</button>
            @endif
        </div>
        @if ($active && $d['canSupervise'] && $d['assignable'])
            <form wire:submit.prevent="assign" class="flex flex-wrap gap-2 items-center pt-1">
                <select wire:model="assigneeId" class="{{ $inputClass }} !w-64">
                    <option value="">— إلغاء التعيين —</option>
                    @foreach ($d['assignable'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <button type="submit" class="{{ $btnAlt }}">إعادة التعيين</button>
            </form>
        @endif
    </div>

    <div>
        <h3 class="font-bold mb-2">البلاغات المرتبطة</h3>
        <p class="text-xs text-gray-500 mb-2">البلاغ ليس دليلًا بذاته. هوية المبلِّغ للمشرفين فقط ولا تُعرض للمستخدم المعني.</p>
        <table class="w-full text-sm" data-case-sources>
            <thead><tr class="text-gray-500"><th class="text-start">المصدر</th><th>التصنيف</th><th>الحالة</th><th>المبلِّغ</th><th>التفاصيل</th><th></th></tr></thead>
            <tbody>
            @forelse ($d['sources'] as $s)
                <tr class="border-t border-gray-100 dark:border-white/10 align-top {{ $s['active'] ? '' : 'opacity-60' }}">
                    <td>{{ $s['label'] }}@if ($s['system'])<span class="ms-1 rounded bg-amber-100 text-amber-800 px-1.5 py-0.5 text-xs font-bold">إشارة نظام</span>@endif
                        @if ($s['detached'])<span class="ms-1 text-xs text-gray-400">(مفصول)</span>@endif</td>
                    <td class="text-center">{{ $s['category_label'] }}</td>
                    <td class="text-center">{{ $s['status_label'] }}</td>
                    <td class="text-center">{{ $s['system'] ? '—' : ($s['reporter_name'] ?? 'محذوف') }}</td>
                    <td class="text-xs">{{ $s['details'] }}</td>
                    <td>
                        @if ($active && $d['canManage'] && $s['active'])
                            <button type="button" wire:click="detachSource({{ (int) $s['id'] }})" class="text-xs text-red-600">فصل</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-gray-500 py-3">لا مصادر.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($active && $d['canManage'])
        <div class="grid md:grid-cols-2 gap-6">
            <div class="space-y-2" data-case-status-form>
                <h3 class="font-bold">الحالة والأولوية</h3>
                <form wire:submit.prevent="changeStatus" class="flex gap-2">
                    <select wire:model="statusValue" class="{{ $inputClass }}">
                        <option value="">— اختر حالة —</option>
                        @foreach (['open', 'investigating', 'awaiting_user'] as $s)<option value="{{ $s }}">{{ \App\Models\ModerationCase::STATUSES[$s] }}</option>@endforeach
                    </select>
                    <button type="submit" class="{{ $btnAlt }}">تغيير</button>
                </form>
                <form wire:submit.prevent="changePriority" class="flex gap-2">
                    <select wire:model="priorityValue" class="{{ $inputClass }}">
                        <option value="">— اختر أولوية —</option>
                        @foreach (\App\Models\ModerationCase::PRIORITIES as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                    </select>
                    <button type="submit" class="{{ $btnAlt }}">تغيير</button>
                </form>
                <input type="text" wire:model="statusNote" maxlength="1000" placeholder="ملاحظة اختيارية للتغيير" class="{{ $inputClass }}">
            </div>
            <div class="space-y-2" data-case-note-form>
                <h3 class="font-bold">ملاحظة مشرف (داخلية، نص عادي)</h3>
                <textarea wire:model="noteText" rows="3" maxlength="1000" class="{{ $inputClass }}"></textarea>
                <button type="button" wire:click="addNote" class="{{ $btnAlt }}">إضافة ملاحظة</button>
            </div>
        </div>
    @endif

    <div data-case-actions>
        <h3 class="font-bold mb-2">الإجراءات المنفَّذة</h3>
        <p class="text-xs text-gray-500 mb-2">كل إجراء يمرّ بخدمة المجال الرسمية. السبب الداخلي لا يُعرض للمستخدم؛ يرى فقط التصنيف والرسالة العامة.</p>
        <table class="w-full text-sm">
            <thead><tr class="text-gray-500"><th class="text-start">الإجراء</th><th>المنفِّذ</th><th class="text-start">السبب الداخلي</th><th class="text-start">الرسالة العامة</th><th>الاعتراض</th><th>الوضع</th></tr></thead>
            <tbody>
            @forelse ($d['actions'] as $a)
                <tr class="border-t border-gray-100 dark:border-white/10 align-top">
                    <td>{{ $a->label() }}<div class="text-xs text-gray-500">{{ $a->created_at?->format('Y-m-d H:i') }}</div></td>
                    <td class="text-center">{{ $who($a->performed_by) }}</td>
                    <td class="text-xs">{{ $a->reason }}</td>
                    <td class="text-xs">{{ $a->public_note ?? '—' }}</td>
                    <td class="text-center text-xs">
                        @forelse ($a->appeals as $ap){{ $ap->statusLabel() }}@empty — @endforelse
                    </td>
                    <td class="text-center text-xs">{{ $a->isReversed() ? 'مُعاد' : ($a->active_marker ? 'فعّال' : '—') }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-gray-500 py-3">لا إجراءات بعد.</td></tr>
            @endforelse
            </tbody>
        </table>

        @if ($active && count($d['actionsAvailable']) > 0)
            <form wire:submit.prevent="applyAction" class="mt-3 space-y-2 rounded-xl border border-gray-200 dark:border-white/10 p-4" data-case-apply-form>
                <h4 class="font-bold text-sm">تنفيذ إجراء</h4>
                <select wire:model.live="actionKey" class="{{ $inputClass }}">
                    <option value="">— اختر إجراءً —</option>
                    @foreach ($d['actionsAvailable'] as $a)<option value="{{ $a['key'] }}">{{ $a['label'] }}{{ $a['severe'] ? ' (شديد)' : '' }}</option>@endforeach
                </select>
                <textarea wire:model="actionReason" rows="2" maxlength="400" placeholder="السبب الداخلي (للمشرفين فقط)" class="{{ $inputClass }}"></textarea>
                <textarea wire:model="actionPublicNote" rows="2" maxlength="500" placeholder="رسالة عامة للمستخدم المعني (نص عادي، بلا هوية مبلِّغ)" class="{{ $inputClass }}"></textarea>
                <label class="text-sm flex items-center gap-2"><input type="checkbox" wire:model="actionConfirm"> أؤكد تنفيذ الإجراء (مطلوب للإجراءات الشديدة)</label>
                <button type="submit" class="{{ $btn }}">تنفيذ</button>
            </form>
        @endif
    </div>

    <div data-case-evidence>
        <h3 class="font-bold mb-2">الأدلة والملاحظات (إلحاقية)</h3>
        @forelse ($d['evidence'] as $e)
            <div class="border-t border-gray-100 dark:border-white/10 py-2 text-sm">
                <div class="text-xs text-gray-500">{{ $e->kind === 'content_snapshot' ? 'لقطة محتوى' : 'ملاحظة' }} · {{ $who($e->added_by) }} · {{ $e->created_at?->format('Y-m-d H:i') }}</div>
                <div class="whitespace-pre-line">{{ $e->body }}</div>
            </div>
        @empty
            <p class="text-sm text-gray-500">لا أدلة بعد.</p>
        @endforelse
    </div>

    <div data-case-timeline>
        <h3 class="font-bold mb-2">التسلسل الزمني (لا يُعدَّل ولا يُحذف)</h3>
        <ol class="space-y-1 text-sm">
            @foreach ($d['events'] as $ev)
                <li class="border-t border-gray-100 dark:border-white/10 py-1">
                    <span class="text-xs text-gray-500">{{ $ev->created_at?->format('Y-m-d H:i') }} · {{ $who($ev->actor_id) }}</span>
                    — <strong>{{ $eventLabels[$ev->type] ?? $ev->type }}</strong>
                    @if ($ev->from_value || $ev->to_value) <span class="text-xs">({{ $ev->from_value ?? '—' }} ← {{ $ev->to_value ?? '—' }})</span>@endif
                    @if ($ev->note)<div class="text-xs text-gray-600 dark:text-gray-400">{{ $ev->note }}</div>@endif
                </li>
            @endforeach
        </ol>
    </div>

    @if ($d['canManage'])
        <div class="grid md:grid-cols-3 gap-4" data-case-close-forms>
            @if ($active)
                <form wire:submit.prevent="resolve" class="space-y-2 rounded-xl border border-gray-200 dark:border-white/10 p-4">
                    <h4 class="font-bold text-sm">حسم الحالة</h4>
                    <select wire:model="resolution" class="{{ $inputClass }}">
                        <option value="">— اختر القرار —</option>
                        @foreach (\App\Models\ModerationCase::RESOLUTIONS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                    </select>
                    <input type="text" wire:model="resolveNote" maxlength="1000" placeholder="ملاحظة اختيارية" class="{{ $inputClass }}">
                    <button type="submit" class="{{ $btn }}">حسم</button>
                </form>
            @endif
            @if ($case->status === 'resolved')
                <form wire:submit.prevent="closeCase" class="space-y-2 rounded-xl border border-gray-200 dark:border-white/10 p-4">
                    <h4 class="font-bold text-sm">إغلاق الحالة</h4>
                    <input type="text" wire:model="closeNote" maxlength="1000" placeholder="سبب الإغلاق" class="{{ $inputClass }}">
                    <button type="submit" class="{{ $btnAlt }}">إغلاق</button>
                </form>
            @endif
            @if (! $active && $d['canSupervise'])
                <form wire:submit.prevent="reopen" class="space-y-2 rounded-xl border border-gray-200 dark:border-white/10 p-4">
                    <h4 class="font-bold text-sm">إعادة فتح الحالة (مشرف أعلى)</h4>
                    <input type="text" wire:model="reopenNote" maxlength="1000" placeholder="سبب إعادة الفتح" class="{{ $inputClass }}">
                    <button type="submit" class="{{ $btnAlt }}">إعادة فتح</button>
                </form>
            @endif
        </div>
    @endif
</x-filament-panels::page>
