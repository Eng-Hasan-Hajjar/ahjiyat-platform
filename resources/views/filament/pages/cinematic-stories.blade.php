<x-filament-panels::page>
    @php
        $d = $this->data();
        $inputClass = 'rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm w-full';
        $btn = 'rounded-lg bg-primary-600 text-white px-4 py-2 text-sm font-bold';
    @endphp

    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ $d['mediaUrl'] }}" class="text-sm text-primary-600 font-bold">مكتبة الوسائط ←</a>
        @if ($d['canManage'])
            <button type="button" wire:click="$toggle('showCreate')" class="{{ $btn }}">{{ $showCreate ? 'إغلاق' : 'قصة جديدة' }}</button>
        @endif
    </div>

    <p class="text-xs text-gray-500">تُحرَّر القصص في مسودات؛ النشر يمر بالمراجعة وبفحص الجاهزية، ولا تُنشر قصة وفيها وسائط ناقصة. لا شيء هنا يمنح جواهر أو خبرة.</p>

    @if ($showCreate && $d['canManage'])
        <section class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-cinematic-create>
            <h3 class="font-bold">قصة جديدة</h3>
            <div class="grid gap-3 md:grid-cols-2">
                <label class="text-sm">العنوان<input type="text" wire:model="form.title" maxlength="160" class="{{ $inputClass }}"></label>
                <label class="text-sm">العنوان في الرابط (اختياري، حروف لاتينية)<input type="text" wire:model="form.slug" maxlength="100" class="{{ $inputClass }}" dir="ltr"></label>
                <label class="text-sm md:col-span-2">الملخص<textarea wire:model="form.summary" rows="2" maxlength="1000" class="{{ $inputClass }}"></textarea></label>
                <label class="text-sm">النوع (تسمية تنظيمية فقط)
                    <select wire:model="form.label_kind" class="{{ $inputClass }}"><option value="short">قصة قصيرة</option><option value="long">قصة طويلة</option></select>
                </label>
                <label class="text-sm">الوصول
                    <select wire:model.live="form.access_mode" class="{{ $inputClass }}"><option value="free">مجانية</option><option value="premium">مميزة (ملكية سلسلة مبدع)</option></select>
                </label>
                @if (($form['access_mode'] ?? 'free') === 'premium')
                    <label class="text-sm md:col-span-2">السلسلة المرتبطة (الملكية تُقرأ منها)
                        <select wire:model="form.creator_series_id" class="{{ $inputClass }}">
                            <option value="">—</option>
                            @foreach ($d['series'] as $series)<option value="{{ $series->id }}">{{ $series->published_title ?: $series->public_id }}</option>@endforeach
                        </select>
                    </label>
                @endif
            </div>
            <button type="button" wire:click="create" class="{{ $btn }}">إنشاء</button>
        </section>
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10" data-cinematic-table>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-white/5 text-xs">
                <tr class="text-start"><th class="p-3 text-start">القصة</th><th class="p-3 text-start">النوع</th><th class="p-3 text-start">الوصول</th><th class="p-3 text-start">المنشورة</th><th class="p-3 text-start">المسودة</th><th class="p-3"></th></tr>
            </thead>
            <tbody>
                @forelse ($d['rows'] as $row)
                    @php($s = $row['story'])
                    <tr class="border-t border-gray-100 dark:border-white/10">
                        <td class="p-3"><div class="font-bold">{{ $s->title }}</div><div class="text-xs text-gray-500" dir="ltr">{{ $s->slug }}</div>@if ($s->archived_at)<span class="text-xs rounded-full bg-gray-200 px-2 py-0.5">مؤرشفة</span>@endif</td>
                        <td class="p-3">{{ $s->label_kind === 'short' ? 'قصيرة' : 'طويلة' }}</td>
                        <td class="p-3">{{ $s->access_mode === 'premium' ? 'مميزة' : 'مجانية' }}</td>
                        <td class="p-3">{{ $row['live'] ? 'النسخة '.$row['live'] : '—' }}</td>
                        <td class="p-3">{{ $row['draft'] ? 'النسخة '.$row['draft'].' ('.($row['draft_status'] === 'in_review' ? 'قيد المراجعة' : 'مسودة').')' : '—' }}</td>
                        <td class="p-3 flex flex-wrap gap-2">
                            <a href="{{ $row['url'] }}" class="text-primary-600 font-bold">فتح المنشئ</a>
                            @if ($d['canPublish'])
                                <button type="button" wire:click="toggleArchive('{{ $s->public_id }}')" class="text-xs underline">{{ $s->archived_at ? 'إلغاء الأرشفة' : 'أرشفة' }}</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-6 text-center text-gray-500">لا توجد قصص بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
