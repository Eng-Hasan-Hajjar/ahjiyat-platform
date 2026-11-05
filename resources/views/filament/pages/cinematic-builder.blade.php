<x-filament-panels::page>
    @php
        $d = $this->data();
        $story = $d['story'];
        $rev = $d['revision'];
        $editable = $d['editable'];
        $canManage = $this->can('manage');
        $canPublish = $this->can('publish');
        $canMedia = $this->can('media');
        $inputClass = 'rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm w-full disabled:opacity-60';
        $btn = 'rounded-lg bg-primary-600 text-white px-3 py-1.5 text-sm font-bold';
        $btnGhost = 'rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm font-bold';
        $report = $d['report'];
    @endphp

    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ $d['listUrl'] }}" class="text-sm text-primary-600 font-bold">← السلاسل السينمائية</a>
        <span class="font-bold">{{ $story->title }}</span>
        <span class="text-xs rounded-full px-2 py-0.5 bg-gray-100 dark:bg-white/10">{{ $story->label_kind === 'short' ? 'قصة قصيرة' : 'قصة طويلة' }}</span>
        @if ($d['publicUrl'])<a href="{{ $d['publicUrl'] }}" target="_blank" rel="noopener" class="text-xs underline">صفحة اللاعبين</a>@endif
        <a href="{{ $d['mediaUrl'] }}" class="text-xs underline">مكتبة الوسائط</a>
    </div>

    {{-- النسخ والإجراءات --}}
    <section class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-builder-revisions>
        <div class="flex flex-wrap items-center gap-2">
            @foreach ($d['revisions'] as $r)
                <button type="button" wire:click="selectRevision('{{ $r->public_id }}')" class="rounded-full border px-3 py-1 text-xs font-bold {{ $r->id === $rev->id ? 'bg-primary-600 text-white border-primary-600' : 'border-gray-300 dark:border-white/10' }}">
                    النسخة {{ $r->revision_number }} · {{ $d['statusLabels'][$r->status] ?? $r->status }}
                </button>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($canManage)
                @if (! $d['revisions']->contains(fn ($r) => in_array($r->status, ['draft', 'in_review'], true)))
                    <button type="button" wire:click="newDraft" class="{{ $btn }}">مسودة جديدة (نسخ من المنشورة)</button>
                @endif
                @if ($editable)<button type="button" wire:click="deleteDraft" wire:confirm="حذف المسودة بكل ما فيها؟" class="{{ $btnGhost }} text-red-600">حذف المسودة</button>@endif
            @endif
            @if ($canPublish)
                @if ($rev->status === 'draft')<button type="button" wire:click="submitReview" class="{{ $btn }}">إرسال للمراجعة</button>@endif
                @if ($rev->status === 'in_review')
                    <button type="button" wire:click="requestChanges" class="{{ $btnGhost }}">إعادتها للتعديل</button>
                    <button type="button" wire:click="publish" wire:confirm="نشر هذه النسخة للاعبين؟" class="{{ $btn }}">نشر</button>
                @endif
                @if ($rev->status === 'unpublished')<button type="button" wire:click="publish" wire:confirm="إعادة نشر هذه النسخة؟" class="{{ $btn }}">إعادة النشر</button>@endif
                @if ($rev->status === 'published')<button type="button" wire:click="unpublish" wire:confirm="سحب القصة من النشر؟ لا يُحذف شيء." class="{{ $btnGhost }} text-red-600">سحب من النشر</button>@endif
            @endif
            @if ($d['previewUrl'])<a href="{{ $d['previewUrl'] }}" target="_blank" rel="noopener" class="{{ $btnGhost }}">معاينة (بلا تقدّم محفوظ)</a>@endif
        </div>
        @unless ($editable)
            <p class="text-xs text-gray-500">هذه النسخة مقفلة للتعديل. أنشئ مسودة جديدة منها للتعديل. يمكن تبديل ملفات الوسائط حتى على المنشورة دون المساس بمنطقها.</p>
        @endunless
        @if ($editable && $canManage)
            <div class="grid gap-3 md:grid-cols-3">
                <label class="text-sm">عنوان النسخة<input type="text" wire:model="revForm.title" maxlength="160" class="{{ $inputClass }}"></label>
                <label class="text-sm">ملخص<input type="text" wire:model="revForm.summary" maxlength="1000" class="{{ $inputClass }}"></label>
                <label class="text-sm">ملاحظات المراجعة<input type="text" wire:model="revForm.notes" maxlength="2000" class="{{ $inputClass }}"></label>
            </div>
            <button type="button" wire:click="saveRevision" class="{{ $btnGhost }}">حفظ بيانات النسخة</button>
        @endif
    </section>

    {{-- الجاهزية --}}
    <section class="rounded-xl border p-4 space-y-2 {{ $report['ok'] ? 'border-emerald-300 bg-emerald-50 dark:bg-emerald-500/10' : 'border-amber-300 bg-amber-50 dark:bg-amber-500/10' }}" data-builder-readiness>
        <h3 class="font-bold text-sm">{{ $report['ok'] ? 'جاهزة للنشر' : 'ما يمنع النشر الآن (' . count($report['blockers']) . ')' }}</h3>
        @if (count($report['blockers']))
            <ul class="list-disc ps-5 text-xs space-y-0.5">@foreach ($report['blockers'] as $b)<li>{{ $b['message'] }}@if ($b['scene']) <span class="text-gray-500" dir="ltr">[{{ $b['scene'] }}]</span>@endif</li>@endforeach</ul>
        @endif
        @if (count($report['warnings']))
            <details class="text-xs"><summary class="cursor-pointer font-bold">تنبيهات لا تمنع النشر ({{ count($report['warnings']) }})</summary>
                <ul class="list-disc ps-5 space-y-0.5 mt-1">@foreach ($report['warnings'] as $w)<li>{{ $w['message'] }}</li>@endforeach</ul>
            </details>
        @endif
    </section>

    {{-- إعدادات القصة --}}
    @if ($canManage)
        <details class="rounded-xl border border-gray-200 dark:border-white/10 p-4" data-builder-story>
            <summary class="cursor-pointer font-bold">إعدادات القصة (العنوان، الوصول، الغلاف)</summary>
            <div class="grid gap-3 md:grid-cols-2 mt-3">
                <label class="text-sm">العنوان<input type="text" wire:model="storyForm.title" maxlength="160" class="{{ $inputClass }}"></label>
                <label class="text-sm">النوع (تسمية تنظيمية)<select wire:model="storyForm.label_kind" class="{{ $inputClass }}"><option value="short">قصيرة</option><option value="long">طويلة</option></select></label>
                <label class="text-sm md:col-span-2">الملخص<textarea wire:model="storyForm.summary" rows="2" maxlength="1000" class="{{ $inputClass }}"></textarea></label>
                <label class="text-sm">الوصول<select wire:model="storyForm.access_mode" class="{{ $inputClass }}"><option value="free">مجانية</option><option value="premium">مميزة</option></select></label>
                <label class="text-sm">معرّف السلسلة المرتبطة (للمميزة)<input type="number" wire:model="storyForm.creator_series_id" class="{{ $inputClass }}" dir="ltr"></label>
                <label class="text-sm md:col-span-2">الغلاف (ملصق من المكتبة)
                    <select wire:model="cover" class="{{ $inputClass }}"><option value="">بلا غلاف</option>@foreach ($d['assets']['poster'] as $a)<option value="{{ $a->public_id }}">{{ $a->original_name }}</option>@endforeach</select>
                </label>
            </div>
            <button type="button" wire:click="saveStory" class="{{ $btn }} mt-3">حفظ القصة</button>
        </details>
    @endif

    {{-- البنية --}}
    <section class="space-y-3" data-builder-tree>
        <h3 class="font-bold">المراحل والفصول والمشاهد</h3>
        @foreach ($d['tree'] as $stage)
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-3 space-y-2" data-stage="{{ $stage->stable_key }}">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-black">المرحلة {{ $stage->position }}: {{ $stage->title }}</span>
                    <span class="text-xs text-gray-500" dir="ltr">{{ $stage->stable_key }}</span>
                    @if ($stage->is_optional)<span class="text-xs rounded-full bg-gray-100 dark:bg-white/10 px-2">اختيارية</span>@endif
                    @if ($editable && $canManage)
                        <button type="button" wire:click="moveStage('{{ $stage->public_id }}', 'up')" class="text-xs underline">↑</button>
                        <button type="button" wire:click="moveStage('{{ $stage->public_id }}', 'down')" class="text-xs underline">↓</button>
                        <button type="button" wire:click="toggleStageOptional('{{ $stage->public_id }}')" class="text-xs underline">{{ $stage->is_optional ? 'إلزامية' : 'اختيارية' }}</button>
                        <button type="button" wire:click="removeStage('{{ $stage->public_id }}')" wire:confirm="حذف المرحلة بكل محتواها؟" class="text-xs underline text-red-600">حذف</button>
                    @endif
                </div>

                @foreach ($stage->chapters as $chapter)
                    <div class="rounded-lg bg-gray-50 dark:bg-white/5 p-3 space-y-2" data-chapter="{{ $chapter->stable_key }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-bold text-sm">الفصل {{ $chapter->position }}: {{ $chapter->title }}</span>
                            @if ($chapter->is_optional)<span class="text-xs rounded-full bg-gray-200 dark:bg-white/10 px-2">اختياري</span>@endif
                            @if ($editable && $canManage)
                                <button type="button" wire:click="moveChapter('{{ $chapter->public_id }}', 'up')" class="text-xs underline">↑</button>
                                <button type="button" wire:click="moveChapter('{{ $chapter->public_id }}', 'down')" class="text-xs underline">↓</button>
                                <button type="button" wire:click="toggleChapterOptional('{{ $chapter->public_id }}')" class="text-xs underline">{{ $chapter->is_optional ? 'إلزامي' : 'اختياري' }}</button>
                                <button type="button" wire:click="removeChapter('{{ $chapter->public_id }}')" wire:confirm="حذف الفصل بمشاهده؟" class="text-xs underline text-red-600">حذف</button>
                            @endif
                        </div>
                        <ul class="space-y-1">
                            @foreach ($chapter->scenes as $scene)
                                <li class="flex flex-wrap items-center gap-2 rounded-md border border-gray-200 dark:border-white/10 bg-white dark:bg-transparent px-2 py-1.5 text-sm {{ $editingScene === $scene->public_id ? 'ring-2 ring-primary-500' : '' }}" data-scene="{{ $scene->stable_key }}">
                                    <span class="text-xs rounded bg-primary-100 text-primary-800 px-1.5">{{ $d['types'][$scene->type] ?? $scene->type }}</span>
                                    <span class="font-bold">{{ $scene->title }}</span>
                                    <span class="text-xs text-gray-500" dir="ltr">{{ $scene->stable_key }}</span>
                                    @if ($scene->type === 'video')
                                        <span class="text-xs rounded px-1.5 {{ $scene->video && $scene->video->status === 'ready' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ $scene->video ? ($scene->video->status === 'ready' ? 'فيديو جاهز' : 'فيديو مسحوب') : 'ينتظر إنتاج الفيديو' }}</span>
                                    @endif
                                    @if ($scene->is_optional)<span class="text-xs text-gray-500">اختياري</span>@endif
                                    <span class="ms-auto flex flex-wrap items-center gap-2">
                                        <button type="button" wire:click="editScene('{{ $scene->public_id }}')" class="text-xs underline text-primary-600 font-bold">{{ $editable && $canManage ? 'تحرير' : 'عرض' }}</button>
                                        @if ($editable && $canManage)
                                            <button type="button" wire:click="moveScene('{{ $scene->public_id }}', 'up')" class="text-xs underline">↑</button>
                                            <button type="button" wire:click="moveScene('{{ $scene->public_id }}', 'down')" class="text-xs underline">↓</button>
                                            <button type="button" wire:click="removeScene('{{ $scene->public_id }}')" wire:confirm="حذف المشهد؟" class="text-xs underline text-red-600">حذف</button>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                        @if ($editable && $canManage)
                            <div class="flex flex-wrap items-end gap-2">
                                <label class="text-xs">النوع<select wire:model="newScene.{{ $chapter->public_id }}.type" class="{{ $inputClass }}"><option value="narrative">سرد</option>@foreach ($d['types'] as $k => $l)@if ($k !== 'narrative')<option value="{{ $k }}">{{ $l }}</option>@endif @endforeach</select></label>
                                <label class="text-xs">العنوان<input type="text" wire:model="newScene.{{ $chapter->public_id }}.title" maxlength="160" class="{{ $inputClass }}"></label>
                                <label class="text-xs">المفتاح (اختياري، لاتيني)<input type="text" wire:model="newScene.{{ $chapter->public_id }}.key" maxlength="64" class="{{ $inputClass }}" dir="ltr"></label>
                                <button type="button" wire:click="addScene('{{ $chapter->public_id }}')" class="{{ $btn }}">إضافة مشهد</button>
                            </div>
                        @endif
                    </div>
                @endforeach

                @if ($editable && $canManage)
                    <div class="flex flex-wrap items-end gap-2">
                        <label class="text-xs">فصل جديد<input type="text" wire:model="newChapter.{{ $stage->public_id }}" maxlength="160" class="{{ $inputClass }}"></label>
                        <button type="button" wire:click="addChapter('{{ $stage->public_id }}')" class="{{ $btnGhost }}">إضافة فصل</button>
                    </div>
                @endif
            </div>
        @endforeach

        @if ($editable && $canManage)
            <div class="flex flex-wrap items-end gap-2">
                <label class="text-sm">مرحلة جديدة<input type="text" wire:model="newStage" maxlength="160" class="{{ $inputClass }}"></label>
                <button type="button" wire:click="addStage" class="{{ $btn }}">إضافة مرحلة</button>
            </div>
        @endif
    </section>

    {{-- محرّر المشهد --}}
    @if ($editingScene && ! empty($sf))
        @php($type = $sf['type'])
        <section class="rounded-xl border-2 border-primary-500 p-4 space-y-4" data-scene-editor>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-bold">تحرير المشهد: <span dir="ltr" class="text-sm">{{ $sf['key'] }}</span> ({{ $d['types'][$type] ?? $type }})</h3>
                <button type="button" wire:click="closeScene" class="{{ $btnGhost }}">إغلاق</button>
            </div>

            <fieldset class="space-y-3" @disabled(! $editable || ! $canManage)>
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="text-sm">العنوان<input type="text" wire:model="sf.title" maxlength="160" class="{{ $inputClass }}"></label>
                    <label class="text-sm">وصف قصير<input type="text" wire:model="sf.description" maxlength="800" class="{{ $inputClass }}"></label>
                    <label class="text-sm">مدة تقديرية (ثوانٍ)<input type="number" wire:model="sf.duration" min="1" max="3600" class="{{ $inputClass }}" dir="ltr"></label>
                    <label class="text-sm">شرط الفتح: مفاتيح مشاهد يجب إنجازها قبل هذا المشهد (مفصولة بفواصل)<input type="text" wire:model="sf.requires" class="{{ $inputClass }}" dir="ltr"></label>
                    <label class="text-sm flex items-center gap-2"><input type="checkbox" wire:model="sf.optional">مشهد اختياري (لا يحجب ما بعده)</label>
                    <label class="text-sm flex items-center gap-2"><input type="checkbox" wire:model="sf.replay">يسمح بإعادة المشهد بعد إتمامه</label>
                    @if ($type === 'video')
                        <label class="text-sm flex items-center gap-2"><input type="checkbox" wire:model="sf.skippable">يسمح بتخطي الفيديو</label>
                        <label class="text-sm flex items-center gap-2"><input type="checkbox" wire:model="sf.awaiting">بانتظار إنتاج الفيديو</label>
                    @endif
                </div>

                @if ($type === 'video')
                    <label class="text-sm block">النص البديل / الحوار (سطر لكل جملة: «المتحدث|النص»)<textarea wire:model="sf.transcript" rows="5" class="{{ $inputClass }}"></textarea></label>
                @elseif ($type === 'narrative')
                    <label class="text-sm block">النص (سطر لكل فقرة: «المتحدث|النص» أو النص وحده)<textarea wire:model="sf.lines" rows="6" class="{{ $inputClass }}"></textarea></label>
                @elseif ($type === 'transition')
                    <div class="grid gap-3 md:grid-cols-3">
                        <label class="text-sm">العنوان<input type="text" wire:model="sf.t_title" maxlength="160" class="{{ $inputClass }}"></label>
                        <label class="text-sm">العنوان الفرعي<input type="text" wire:model="sf.t_sub" maxlength="300" class="{{ $inputClass }}"></label>
                        <label class="text-sm">عنوان المرحلة التالية<input type="text" wire:model="sf.t_next" maxlength="160" class="{{ $inputClass }}"></label>
                    </div>
                @else
                    <label class="text-sm block">تمهيد قبل المشهد (سطر لكل جملة)<textarea wire:model="sf.intro" rows="3" class="{{ $inputClass }}"></textarea></label>
                    <div class="grid gap-3 md:grid-cols-3">
                        <label class="text-sm">أقصى عدد محاولات (اختياري)<input type="number" wire:model="sf.max_attempts" min="1" max="50" class="{{ $inputClass }}" dir="ltr"></label>
                        <label class="text-sm">رسالة الخطأ<input type="text" wire:model="sf.wrong_message" maxlength="300" class="{{ $inputClass }}"></label>
                        <label class="text-sm">رسالة النجاح<input type="text" wire:model="sf.success_message" maxlength="300" class="{{ $inputClass }}"></label>
                    </div>
                @endif

                @if ($type === 'puzzle')
                    <label class="text-sm block">الأحجية (رسمية، مفعّلة، كلاسيكية فقط)
                        <select wire:model="sf.puzzle_id" class="{{ $inputClass }}"><option value="">—</option>@foreach ($d['puzzles'] as $p)<option value="{{ $p->id }}">{{ $p->title }}</option>@endforeach</select>
                    </label>
                @endif

                @if (in_array($type, ['interaction', 'exploration'], true))
                    <div class="rounded-lg border border-gray-200 dark:border-white/10 p-3 space-y-3" data-interaction-editor>
                        <label class="text-sm block">نوع التفاعل
                            <select wire:model.live="sf.kind" class="{{ $inputClass }}">@foreach ($d['kinds'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                        </label>
                        @php($kind = $sf['kind'] ?? 'choose_item')
                        <div class="grid gap-3 md:grid-cols-2">
                            <label class="text-sm">السؤال/التعليمات الظاهرة<input type="text" wire:model="sf.ix.prompt" maxlength="600" class="{{ $inputClass }}"></label>
                            <label class="text-sm">تعليمات إضافية<input type="text" wire:model="sf.ix.instructions" maxlength="600" class="{{ $inputClass }}"></label>
                            <label class="text-sm">وصف بديل للمشهد (للقارئات الصوتية)<input type="text" wire:model="sf.ix.alt_text" maxlength="600" class="{{ $inputClass }}"></label>
                            <label class="text-sm">رسالة الشرط غير المتحقق<input type="text" wire:model="sf.ix.locked_message" maxlength="600" class="{{ $inputClass }}"></label>
                        </div>

                        @if (in_array($kind, ['hotspot', 'container'], true))
                            <label class="text-sm block">المناطق — سطر لكل منطقة: <span dir="ltr">id|الاسم|x|y|العرض|الارتفاع</span> (نسب 0–100)<textarea wire:model="sf.ix.areas" rows="4" class="{{ $inputClass }}" dir="ltr"></textarea></label>
                        @endif
                        @if (in_array($kind, ['sequence', 'drag_drop', 'choose_item', 'compare'], true))
                            <label class="text-sm block">{{ $kind === 'compare' ? 'الخيارات' : 'العناصر' }} — سطر لكل عنصر: <span dir="ltr">id|الاسم|الرمز(اختياري)</span><textarea wire:model="sf.ix.items" rows="4" class="{{ $inputClass }}" dir="ltr"></textarea></label>
                        @endif
                        @if ($kind === 'drag_drop')
                            <label class="text-sm block">الأهداف — <span dir="ltr">id|الاسم</span><textarea wire:model="sf.ix.targets" rows="3" class="{{ $inputClass }}" dir="ltr"></textarea></label>
                        @endif
                        @if ($kind === 'flashlight')
                            <div class="grid gap-3 md:grid-cols-3">
                                <label class="text-sm">نصف قطر الضوء (3–60)<input type="number" wire:model="sf.ix.light_radius" class="{{ $inputClass }}" dir="ltr"></label>
                                <label class="text-sm">عدد المخفيات المطلوب العثور عليها<input type="number" wire:model="sf.ix.required" class="{{ $inputClass }}" dir="ltr"></label>
                            </div>
                        @endif
                        @if ($kind === 'reveal_code')
                            <label class="text-sm block">نص زر الكشف<input type="text" wire:model="sf.ix.reveal_label" maxlength="600" class="{{ $inputClass }}"></label>
                        @endif

                        @if ($canManage)
                            <div class="rounded-lg border border-amber-300 bg-amber-50 dark:bg-amber-500/10 p-3 space-y-3" data-solution-editor>
                                <p class="text-xs font-bold">الحل — يُحفظ مشفّرًا على الخادم ولا يصل اللاعبين أبدًا</p>
                                @if ($kind === 'hotspot')<label class="text-sm block">المناطق الصحيحة (معرّفات مفصولة بفواصل)<input type="text" wire:model="sf.ix.correct" class="{{ $inputClass }}" dir="ltr"></label>@endif
                                @if (in_array($kind, ['container', 'choose_item', 'compare'], true))<label class="text-sm block">المعرّف الصحيح<input type="text" wire:model="sf.ix.correct" class="{{ $inputClass }}" dir="ltr"></label>@endif
                                @if ($kind === 'sequence')<label class="text-sm block">الترتيب الصحيح (معرّفات مفصولة بفواصل)<input type="text" wire:model="sf.ix.order" class="{{ $inputClass }}" dir="ltr"></label>@endif
                                @if ($kind === 'drag_drop')<label class="text-sm block">الربط الصحيح — سطر: <span dir="ltr">item=target</span><textarea wire:model="sf.ix.map" rows="3" class="{{ $inputClass }}" dir="ltr"></textarea></label>@endif
                                @if ($kind === 'reveal_code')<label class="text-sm block">الرمز الذي يُكشف<input type="text" wire:model="sf.ix.code" maxlength="64" class="{{ $inputClass }}" dir="ltr"></label>@endif
                                @if (in_array($kind, ['container', 'reveal_code'], true))<label class="text-sm block">لا يعمل إلا بعد إنجاز المشاهد (مفاتيح مفصولة بفواصل)<input type="text" wire:model="sf.ix.requires" class="{{ $inputClass }}" dir="ltr"></label>@endif
                                @if ($kind === 'flashlight')<label class="text-sm block">المخفيات — سطر: <span dir="ltr">id|x|y|العرض|الارتفاع</span><textarea wire:model="sf.ix.hidden" rows="3" class="{{ $inputClass }}" dir="ltr"></textarea></label>@endif
                            </div>
                        @endif
                    </div>
                @endif

                @if ($editable && $canManage)<button type="button" wire:click="saveScene" class="{{ $btn }}">حفظ المشهد</button>@endif
            </fieldset>

            {{-- الوسائط: تبديل الملفات مسموح بصلاحية media حتى على النسخة المنشورة --}}
            <fieldset class="space-y-3 rounded-lg border border-gray-200 dark:border-white/10 p-3" data-scene-media @disabled(! $canMedia)>
                <p class="text-sm font-bold">وسائط المشهد (من المكتبة)</p>
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach (['video' => 'الفيديو', 'poster' => ($type === 'video' ? 'الملصق (صورة مصغّرة)' : 'صورة المشهد'), 'subtitle' => 'الترجمة (WebVTT)', 'audio' => 'صوت إضافي'] as $role => $label)
                        @continue($role === 'video' && $type !== 'video')
                        <label class="text-sm">{{ $label }}
                            <select wire:model="sf.media.{{ $role }}" class="{{ $inputClass }}"><option value="">— بلا —</option>@foreach ($d['assets'][$role] as $a)<option value="{{ $a->public_id }}">{{ $a->original_name }}@if ($a->duration_ms) ({{ round($a->duration_ms / 1000) }}ث)@endif</option>@endforeach</select>
                        </label>
                    @endforeach
                </div>
                @if ($canMedia && in_array($rev->status, ['draft', 'in_review', 'published'], true))<button type="button" wire:click="saveMedia" class="{{ $btn }}">حفظ الوسائط</button>@endif
            </fieldset>
        </section>
    @endif
</x-filament-panels::page>
