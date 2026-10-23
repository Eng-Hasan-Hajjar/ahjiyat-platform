<x-filament-panels::page>
    @php
        $d = $this->data();
        $page = $d['page'];
        $inputClass = 'rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm';
    @endphp

    <p class="text-xs text-gray-500">
        عرض قراءة فقط موحَّد فوق بلاغات الأحجيات والمجموعات والتعليقات والمحادثات وإشارات النظام؛ لا نسخ للبلاغ ولا يتغيّر مصدره من هنا.
        عدد البلاغات ليس حكمًا ولا يرفع الأولوية، و«إشارة نظام» ليست مخالفة مؤكَّدة. المبلِّغون لا يظهرون للمستخدم المعني أبدًا.
    </p>

    <div class="grid grid-cols-3 gap-3" data-inbox-summary>
        @foreach (['بلاغات مفتوحة' => $d['summary']['open'], 'إشارات نظام مفتوحة' => $d['summary']['system'], 'بلا حالة إشراف' => $d['summary']['unlinked']] as $label => $value)
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <div class="text-xs text-gray-500">{{ $label }}</div>
                <div class="text-xl font-bold mt-1">{{ number_format((int) $value) }}</div>
            </div>
        @endforeach
    </div>

    <div class="flex flex-wrap gap-2 items-end" data-inbox-filters>
        <label class="text-xs text-gray-500">الحالة
            <select wire:model.live="status" class="{{ $inputClass }} block">
                <option value="open">مفتوحة</option><option value="closed">مغلقة</option><option value="all">الكل</option>
            </select>
        </label>
        <label class="text-xs text-gray-500">المصدر
            <select wire:model.live="source" class="{{ $inputClass }} block">
                <option value="">الكل</option>
                @foreach ($d['sources'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="text-xs text-gray-500">التصنيف
            <select wire:model.live="category" class="{{ $inputClass }} block">
                <option value="">الكل</option>
                @foreach ($d['categories'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="text-xs text-gray-500">أولوية الحالة
            <select wire:model.live="priority" class="{{ $inputClass }} block">
                <option value="">الكل</option>
                @foreach ($d['priorities'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="text-xs text-gray-500">التعيين
            <select wire:model.live="assigned" class="{{ $inputClass }} block">
                <option value="">الكل</option><option value="mine">لي</option><option value="unassigned">غير معيَّنة</option>
            </select>
        </label>
        <label class="text-xs text-gray-500">حالة إشراف
            <select wire:model.live="hasCase" class="{{ $inputClass }} block">
                <option value="">الكل</option><option value="yes">لها حالة</option><option value="no">بلا حالة</option>
            </select>
        </label>
        <label class="text-xs text-gray-500">من <input type="date" wire:model.live="from" class="{{ $inputClass }} block"></label>
        <label class="text-xs text-gray-500">إلى <input type="date" wire:model.live="to" class="{{ $inputClass }} block"></label>
        <label class="text-xs text-gray-500">الترتيب
            <select wire:model.live="sort" class="{{ $inputClass }} block">
                <option value="oldest">الأقدم أولًا</option><option value="newest">الأحدث أولًا</option>
            </select>
        </label>
        <label class="text-sm flex items-center gap-2"><input type="checkbox" wire:model.live="grouped"> تجميع حسب الموضوع</label>
    </div>

    @if ($d['grouped'])
        <table class="w-full text-sm" data-inbox-grouped>
            <thead><tr class="text-gray-500 text-start"><th class="text-start">الموضوع</th><th>بلاغات</th><th>مبلِّغون فريدون</th><th>مفتوحة</th><th>أول بلاغ</th><th>آخر بلاغ</th><th>الحالة</th></tr></thead>
            <tbody>
            @forelse ($page as $row)
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td>{{ $row['subject_label'] }} @unless ($row['subject_exists'])<span class="text-gray-400">(محذوف)</span>@endunless
                        @if ($row['subject_user_name'])<div class="text-xs text-gray-500">صاحب المحتوى: {{ $row['subject_user_name'] }}</div>@endif</td>
                    <td class="text-center">{{ $row['report_count'] }}</td>
                    <td class="text-center">{{ $row['unique_reporters_count'] }}</td>
                    <td class="text-center">{{ $row['open_count'] }}</td>
                    <td class="text-center text-xs">{{ $row['first_reported_at']?->format('Y-m-d H:i') }}</td>
                    <td class="text-center text-xs">{{ $row['last_reported_at']?->format('Y-m-d H:i') }}</td>
                    <td class="text-center">
                        @if ($row['case_public_id'])
                            <a class="text-primary-600 font-bold" href="{{ \App\Filament\Pages\ModerationCasePage::getUrl(['record' => $row['case_public_id']]) }}">{{ $row['case_number'] }}</a>
                        @else — @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-gray-500 py-4">لا بلاغات مطابقة.</td></tr>
            @endforelse
            </tbody>
        </table>
    @else
        <table class="w-full text-sm" data-inbox-list>
            <thead><tr class="text-gray-500"><th></th><th class="text-start">المصدر</th><th class="text-start">الموضوع</th><th>التصنيف</th><th>الحالة</th><th>وقت البلاغ</th><th>الحالة الإشرافية</th></tr></thead>
            <tbody>
            @forelse ($page as $item)
                <tr class="border-t border-gray-100 dark:border-white/10 align-top">
                    <td class="pe-2">
                        @if ($d['canOpen'] && $item->isOpen() && ! $item->hasCase())
                            <input type="checkbox" wire:model="selected" value="{{ $item->ref() }}" aria-label="تحديد">
                        @endif
                    </td>
                    <td>
                        {{ $item->sourceLabel }}
                        @if ($item->systemSignal)<span class="ms-1 rounded bg-amber-100 text-amber-800 px-1.5 py-0.5 text-xs font-bold" data-system-signal>إشارة نظام</span>@endif
                    </td>
                    <td>
                        {{ $item->subjectLabel }} @unless ($item->subjectExists)<span class="text-gray-400">(محذوف)</span>@endunless
                        @if ($item->subjectUserName)<div class="text-xs text-gray-500">صاحب المحتوى: {{ $item->subjectUserName }}</div>@endif
                        @if ($item->excerpt)<div class="text-xs text-gray-500">{{ $item->excerpt }}</div>@endif
                    </td>
                    <td class="text-center">{{ $item->categoryLabel }}</td>
                    <td class="text-center">{{ $item->statusLabel }}</td>
                    <td class="text-center text-xs">{{ $item->reportedAt?->format('Y-m-d H:i') }}</td>
                    <td class="text-center">
                        @if ($item->hasCase())
                            <a class="text-primary-600 font-bold" href="{{ \App\Filament\Pages\ModerationCasePage::getUrl(['record' => $item->casePublicId]) }}">{{ $item->caseNumber }}</a>
                            <div class="text-xs text-gray-500">{{ \App\Models\ModerationCase::STATUSES[$item->caseStatus] ?? $item->caseStatus }}@if ($item->caseAssigneeName) · {{ $item->caseAssigneeName }}@endif</div>
                        @else — @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-gray-500 py-4">لا بلاغات مطابقة.</td></tr>
            @endforelse
            </tbody>
        </table>

        @if ($d['canOpen'])
            <div class="flex items-center gap-3">
                <button type="button" wire:click="openCase" class="rounded-lg bg-primary-600 text-white px-4 py-2 text-sm font-bold disabled:opacity-40" @disabled(count($selected) === 0)>فتح حالة من المحدَّد ({{ count($selected) }})</button>
                <span class="text-xs text-gray-500">بلاغ واحد لا يكون إلا في حالة فعّالة واحدة.</span>
            </div>
        @endif
    @endif

    <div>{{ $page->links() }}</div>
</x-filament-panels::page>
