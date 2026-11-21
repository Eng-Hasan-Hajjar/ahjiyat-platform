<x-filament-panels::page>
    @php($d = $this->data())

    {{-- CIN-1 UX: نفس أنماط المنشئ والمكتبة (محصورة بـ .cin-admin). كانت الحقول هنا بخلفية شبه شفافة فتظهر قوائم الاختيار بنص غير مقروء. --}}
    @include('filament.pages.partials.cinematic-admin-styles')
    <x-searchable-select.assets />

    <div class="cin-admin" data-cin-root>
        <div class="cin-row">
            <a href="{{ $d['mediaUrl'] }}" class="cin-link-btn">مكتبة الوسائط ←</a>
            @if ($d['canManage'])
                <button type="button" wire:click="$toggle('showCreate')" class="cin-btn cin-btn-primary">{{ $showCreate ? 'إغلاق' : 'قصة جديدة' }}</button>
            @endif
        </div>

        <p class="cin-help" style="margin-block:.75rem">تُحرَّر القصص في مسودات؛ النشر يمر بالمراجعة وبفحص الجاهزية، ولا تُنشر قصة وفيها وسائط ناقصة. لا شيء هنا يمنح جواهر أو خبرة.</p>

        @if ($showCreate && $d['canManage'])
            <section class="cin-card" style="margin-bottom:1rem" data-cinematic-create>
                <h3 class="cin-section-title">قصة جديدة</h3>
                <div class="cin-grid cin-grid-2">
                    <label class="cin-field">العنوان<input type="text" wire:model="form.title" maxlength="160"></label>
                    <label class="cin-field">العنوان في الرابط (اختياري، حروف لاتينية)<input type="text" wire:model="form.slug" maxlength="100" dir="ltr"></label>
                    <label class="cin-field" style="grid-column:1/-1">الملخص<textarea wire:model="form.summary" rows="2" maxlength="1000"></textarea></label>
                    <label class="cin-field">النوع (تسمية تنظيمية فقط)
                        <select wire:model="form.label_kind"><option value="short">قصة قصيرة</option><option value="long">قصة طويلة</option></select>
                    </label>
                    <label class="cin-field">الوصول
                        <select wire:model.live="form.access_mode"><option value="free">مجانية</option><option value="premium">مميزة (ملكية سلسلة مبدع)</option></select>
                    </label>
                    @if (($form['access_mode'] ?? 'free') === 'premium')
                        <label class="cin-field" style="grid-column:1/-1">السلسلة المرتبطة (الملكية تُقرأ منها)
                            <x-searchable-select id="cin-new-series" wire:model="form.creator_series_id" wire:key="ss-new-series" method="searchSeries"
                                :value="$form['creator_series_id'] ?? ''" :selected="null" allow-none name-dir="auto"
                                placeholder="ابحث بعنوان السلسلة…" none="— بلا سلسلة —" remove-label="مسح اختيار السلسلة" />
                        </label>
                    @endif
                </div>
                <div class="cin-row"><button type="button" wire:click="create" class="cin-btn cin-btn-primary">إنشاء</button></div>
            </section>
        @endif

        <div class="cin-card cin-table-wrap" data-cinematic-table>
            <table class="cin-table">
                <thead>
                    <tr><th>القصة</th><th>النوع</th><th>الوصول</th><th>المنشورة</th><th>المسودة</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($d['rows'] as $row)
                        @php($s = $row['story'])
                        <tr>
                            <td>
                                <div class="cin-asset-name">{{ $s->title }}</div>
                                <div class="cin-key" dir="ltr">{{ $s->slug }}</div>
                                @if ($s->archived_at)<span class="cin-badge is-warn">مؤرشفة</span>@endif
                            </td>
                            <td>{{ $s->label_kind === 'short' ? 'قصيرة' : 'طويلة' }}</td>
                            <td>{{ $s->access_mode === 'premium' ? 'مميزة' : 'مجانية' }}</td>
                            <td>{{ $row['live'] ? 'النسخة '.$row['live'] : '—' }}</td>
                            <td>{{ $row['draft'] ? 'النسخة '.$row['draft'].' ('.($row['draft_status'] === 'in_review' ? 'قيد المراجعة' : 'مسودة').')' : '—' }}</td>
                            <td>
                                <div class="cin-row">
                                    <a href="{{ $row['url'] }}" class="cin-btn cin-btn-sm cin-btn-primary">فتح المنشئ</a>
                                    @if ($d['canPublish'])
                                        <button type="button" wire:click="toggleArchive('{{ $s->public_id }}')" class="cin-link-btn">{{ $s->archived_at ? 'إلغاء الأرشفة' : 'أرشفة' }}</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="cin-placeholder">لا توجد قصص بعد.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
