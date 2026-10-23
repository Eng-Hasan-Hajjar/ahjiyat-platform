<x-filament-panels::page>
    @php
        $d = $this->data();
        $page = $d['page'];
        $inputClass = 'rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm';
        $tabs = ['open' => 'المفتوحة', 'mine' => 'لي', 'unassigned' => 'غير معيَّنة', 'urgent' => 'عاجلة', 'overdue' => 'متأخرة', 'resolved' => 'المحسومة والمغلقة', 'all' => 'الكل'];
        $slaLabels = ['none' => '', 'ok' => '', 'due_soon' => 'يستحق قريبًا', 'overdue' => 'متأخرة'];
    @endphp

    <p class="text-xs text-gray-500">أهداف المعالجة الزمنية اقتراحات تشغيلية داخلية وليست وعدًا للمستخدم. الأولوية لا تتأثر بعدد البلاغات ولا بشهرة المحتوى أو السمعة أو الدعم.</p>

    <div class="flex flex-wrap gap-2" data-case-tabs>
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" class="rounded-lg px-3 py-1.5 text-sm {{ $tab === $key ? 'bg-primary-600 text-white font-bold' : 'border border-gray-300 dark:border-white/10' }}">
                {{ $label }}@if (isset($d['counts'][$key])) ({{ $d['counts'][$key] }})@endif
            </button>
        @endforeach
        <select wire:model.live="priority" class="{{ $inputClass }}">
            <option value="">كل الأولويات</option>
            @foreach ($d['priorities'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="category" class="{{ $inputClass }}">
            <option value="">كل التصنيفات</option>
            @foreach ($d['categories'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
    </div>

    <table class="w-full text-sm" data-case-list>
        <thead><tr class="text-gray-500"><th class="text-start">الحالة</th><th class="text-start">الموضوع</th><th>التصنيف</th><th>الأولوية</th><th>الوضع</th><th>المعيَّن</th><th>المصادر</th><th>أُنشئت</th><th></th></tr></thead>
        <tbody>
        @forelse ($page as $case)
            @php $sla = \App\Services\TrustSafety\CaseService::slaState($case); @endphp
            <tr class="border-t border-gray-100 dark:border-white/10 align-top">
                <td><a class="text-primary-600 font-bold" href="{{ \App\Filament\Pages\ModerationCasePage::getUrl(['record' => $case->public_id]) }}">{{ $case->case_number }}</a></td>
                <td>{{ $case->subject_label ?? '—' }}</td>
                <td class="text-center">{{ $case->categoryLabel() }}</td>
                <td class="text-center">{{ $case->priorityLabel() }}</td>
                <td class="text-center">{{ $case->statusLabel() }}@if ($slaLabels[$sla] ?? '')<div class="text-xs {{ $sla === 'overdue' ? 'text-red-600' : 'text-amber-600' }}">{{ $slaLabels[$sla] }}</div>@endif</td>
                <td class="text-center">{{ $case->assignee?->name ?? '—' }}</td>
                <td class="text-center">{{ $case->active_sources_count }}</td>
                <td class="text-center text-xs">{{ $case->created_at?->format('Y-m-d H:i') }}</td>
                <td>
                    @if ($d['canClaim'] && $case->isActive() && $case->assigned_to === null)
                        <button type="button" wire:click="claim('{{ $case->public_id }}')" class="rounded-lg border border-primary-600 text-primary-600 px-3 py-1 text-xs font-bold">حجز</button>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-gray-500 py-4">لا حالات في هذا العرض.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div>{{ $page->links() }}</div>
</x-filament-panels::page>
