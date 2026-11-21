<x-filament-panels::page>
    @php
        $d = $this->data();
        $story = $d['story'];
        $rev = $d['revision'];
        $editable = $d['editable'];
        $canManage = $this->can('manage');
        $canPublish = $this->can('publish');
        $canMedia = $this->can('media');
        $report = $d['report'];
        $counts = $d['counts'];
        $editing = $d['editing'];
        $fmtSize = fn ($b) => $b === null ? '' : ($b >= 1048576 ? number_format($b / 1048576, 1).' م.ب' : max(1, (int) round($b / 1024)).' ك.ب');
        $fmtDur = fn ($ms) => $ms ? (int) round($ms / 1000).' ث' : '';
        $btn = 'cin-btn cin-btn-primary';
        $btnGhost = 'cin-btn';
    @endphp

    @include('filament.pages.partials.cinematic-admin-styles')
    @include('filament.pages.partials.cinematic-admin-scripts')
    {{-- المكوّن المشترك للقوائم القابلة للبحث: أنماطه وسكربته مرة واحدة على مستوى الصفحة (Livewire لا ينفّذ سكربتًا يُحقن لاحقًا). --}}
    <x-searchable-select.assets />

    <div class="cin-admin" data-cin-root
         x-data="cinWorkspace({ editing: @js($editingScene) })"
         x-on:input.capture="markDirty($event)" x-on:change.capture="markDirty($event)" x-on:click.capture="guard($event)"
         x-on:cin-scene-opened.window="opened($event.detail)" x-on:cin-scene-closed.window="closed()" x-on:cin-scene-saved.window="saved($event.detail)">

        {{-- الرأس والإحصاءات --}}
        <header class="cin-card" data-builder-header>
            <div class="cin-row">
                <a href="{{ $d['listUrl'] }}" data-cin-guard>← السلاسل السينمائية</a>
                <h2 class="cin-title">{{ $story->title }}</h2>
                <span class="cin-badge">{{ $story->label_kind === 'short' ? 'قصة قصيرة' : 'قصة طويلة' }}</span>
                <span class="cin-badge">{{ $story->access_mode === 'premium' ? 'مميزة' : 'مجانية' }}</span>
                <span class="cin-row-end cin-row">
                    @if ($d['publicUrl'])<a href="{{ $d['publicUrl'] }}" target="_blank" rel="noopener">صفحة اللاعبين</a>@endif
                    <a href="{{ $d['mediaUrl'] }}" data-cin-guard>مكتبة الوسائط</a>
                </span>
            </div>
            <div class="cin-row" data-builder-counts>
                <div class="cin-stat"><b>{{ $counts['scenes'] }}</b><span>مشهد</span></div>
                <div class="cin-stat"><b>{{ $counts['video_ready'] }} / {{ $counts['video'] }}</b><span>فيديو جاهز</span></div>
                <div class="cin-stat"><b>{{ $counts['video_missing'] }}</b><span>فيديو ناقص أو ينتظر الإنتاج</span></div>
                <div class="cin-stat"><b>{{ $counts['interaction'] }}</b><span>مشهد تفاعلي ({{ $counts['interaction_no_image'] }} بلا صورة)</span></div>
                <div class="cin-stat"><b>{{ $d['statusLabels'][$rev->status] ?? $rev->status }}</b><span>حالة النسخة {{ $rev->revision_number }}</span></div>
            </div>
            <div wire:loading.delay wire:target="saveScene,saveMedia,editScene,closeScene,moveScene,removeScene,addScene,selectRevision" class="cin-note" role="status" data-builder-loading>
                <span class="cin-spin" aria-hidden="true"></span> جارٍ التنفيذ…
            </div>
        </header>

        {{-- النسخ ومراجعة النشر --}}
        <section class="cin-card" data-builder-revisions>
            <h3 class="cin-section-title">النسخ وحالة المراجعة</h3>
            <div class="cin-row">
                @foreach ($d['revisions'] as $r)
                    <button type="button" wire:click="selectRevision('{{ $r->public_id }}')" data-cin-guard class="cin-btn cin-btn-sm {{ $r->id === $rev->id ? 'cin-btn-primary' : '' }}" @if ($r->id === $rev->id) aria-current="true" @endif>
                        النسخة {{ $r->revision_number }} · {{ $d['statusLabels'][$r->status] ?? $r->status }}
                    </button>
                @endforeach
            </div>
            <div class="cin-row">
                @if ($canManage)
                    @if (! $d['revisions']->contains(fn ($r) => in_array($r->status, ['draft', 'in_review'], true)))
                        <button type="button" wire:click="newDraft" class="{{ $btn }}">مسودة جديدة (نسخ من المنشورة)</button>
                    @endif
                    @if ($editable)<button type="button" wire:click="deleteDraft" wire:confirm="حذف المسودة بكل ما فيها؟" class="cin-btn cin-btn-danger">حذف المسودة</button>@endif
                @endif
                @if ($canPublish)
                    @if ($rev->status === 'draft')<button type="button" wire:click="submitReview" class="{{ $btn }}">إرسال للمراجعة</button>@endif
                    @if ($rev->status === 'in_review')
                        <button type="button" wire:click="requestChanges" class="{{ $btnGhost }}">إعادتها للتعديل</button>
                        <button type="button" wire:click="publish" wire:confirm="نشر هذه النسخة للاعبين؟" class="{{ $btn }}">نشر</button>
                    @endif
                    @if ($rev->status === 'unpublished')<button type="button" wire:click="publish" wire:confirm="إعادة نشر هذه النسخة؟" class="{{ $btn }}">إعادة النشر</button>@endif
                    @if ($rev->status === 'published')<button type="button" wire:click="unpublish" wire:confirm="سحب القصة من النشر؟ لا يُحذف شيء." class="cin-btn cin-btn-danger">سحب من النشر</button>@endif
                @endif
                @if ($d['previewUrl'])<a href="{{ $d['previewUrl'] }}" target="_blank" rel="noopener" class="{{ $btnGhost }}">معاينة كل المسودة (بلا تقدّم محفوظ)</a>@endif
                @if ($d['playerPreviewUrl'])<a href="{{ $d['playerPreviewUrl'] }}" target="_blank" rel="noopener" class="{{ $btnGhost }}" data-player-preview-all>معاينة كما يراها اللاعب (شاشة غامرة)</a>@endif
            </div>
            @unless ($editable)
                <p class="cin-note">هذه النسخة مقفلة للتعديل. أنشئ مسودة جديدة منها للتعديل. يمكن تبديل ملفات الوسائط حتى على المنشورة دون المساس بمنطقها.</p>
            @endunless
            @if ($editable && $canManage)
                <details>
                    <summary class="cin-link-btn">بيانات النسخة (العنوان، الملخص، ملاحظات المراجعة)</summary>
                    <div class="cin-grid cin-grid-3" style="margin-top:.6rem">
                        <label class="cin-field">عنوان النسخة<input type="text" wire:model="revForm.title" maxlength="160"></label>
                        <label class="cin-field">ملخص<input type="text" wire:model="revForm.summary" maxlength="1000"></label>
                        <label class="cin-field">ملاحظات المراجعة<input type="text" wire:model="revForm.notes" maxlength="2000"></label>
                    </div>
                    <button type="button" wire:click="saveRevision" class="{{ $btnGhost }}" style="margin-top:.6rem">حفظ بيانات النسخة</button>
                </details>
            @endif
        </section>

        {{-- الجاهزية --}}
        <section class="cin-note {{ $report['ok'] ? 'is-ok' : 'is-warn' }}" data-builder-readiness>
            <h3 class="cin-section-title" style="color:inherit">{{ $report['ok'] ? 'جاهزة للنشر' : 'ما يمنع النشر الآن ('.count($report['blockers']).')' }}</h3>
            @if (count($report['blockers']))
                <details>
                    <summary class="cin-link-btn" style="color:inherit">عرض ما يمنع النشر</summary>
                    <ul>@foreach ($report['blockers'] as $b)<li>{{ $b['message'] }}@if ($b['scene']) <span class="cin-key">[{{ $b['scene'] }}]</span>@endif</li>@endforeach</ul>
                </details>
            @endif
            @if (count($report['warnings']))
                <details>
                    <summary class="cin-link-btn" style="color:inherit">تنبيهات لا تمنع النشر ({{ count($report['warnings']) }})</summary>
                    <ul>@foreach ($report['warnings'] as $w)<li>{{ $w['message'] }}</li>@endforeach</ul>
                </details>
            @endif
        </section>

        {{-- إعدادات القصة --}}
        @if ($canManage)
            <details class="cin-card" data-builder-story>
                <summary class="cin-section-title" style="cursor:pointer">إعدادات القصة (العنوان، الوصول، الغلاف)</summary>
                <div class="cin-grid cin-grid-2" style="margin-top:.75rem">
                    <label class="cin-field">العنوان<input type="text" wire:model="storyForm.title" maxlength="160"></label>
                    <label class="cin-field">النوع (تسمية تنظيمية)<select wire:model="storyForm.label_kind"><option value="short">قصيرة</option><option value="long">طويلة</option></select></label>
                    <label class="cin-field cin-span-2">الملخص<textarea wire:model="storyForm.summary" rows="2" maxlength="1000"></textarea></label>
                    <label class="cin-field">الوصول<select wire:model="storyForm.access_mode"><option value="free">مجانية</option><option value="premium">مميزة</option></select></label>
                    <label class="cin-field">معرّف السلسلة المرتبطة (للمميزة)<input type="number" wire:model="storyForm.creator_series_id" dir="ltr"></label>
                    <div class="cin-field cin-span-2" data-cover-picker>
                        <label id="cin-cover-label" for="cin-cover">الغلاف (ملصق من المكتبة)</label>
                        <x-searchable-select id="cin-cover" label-id="cin-cover-label" wire:model="cover" wire:key="ss-cover"
                            method="searchMedia" :context="['kind' => 'cover']" :value="$cover" :selected="$d['coverPicked']" allow-none
                            placeholder="ابحث باسم الملف…" none="بلا غلاف" remove-label="إزالة الغلاف" />
                        <small>اختيار الغلاف لا يحفظ شيئًا حتى تضغط «حفظ القصة».</small>
                    </div>
                </div>
                <button type="button" wire:click="saveStory" class="{{ $btn }}" style="margin-top:.75rem">حفظ القصة</button>
            </details>
        @endif

        {{-- مساحة العمل: قائمة المشاهد + محرّر المشهد المختار --}}
        <div class="cin-workspace">
            <section class="cin-tree" data-builder-tree aria-label="المراحل والفصول والمشاهد">
                <div class="cin-card" style="margin-bottom:.75rem">
                    <div class="cin-row">
                        <h3 class="cin-section-title">المراحل والفصول والمشاهد</h3>
                        <span class="cin-row-end cin-row">
                            <button type="button" class="cin-btn cin-btn-sm" x-on:click="collapseChapters()">طيّ الفصول</button>
                            <button type="button" class="cin-btn cin-btn-sm" x-on:click="expandAll()">فتح الكل</button>
                        </span>
                    </div>
                    <label class="cin-field" data-no-dirty>انتقال سريع إلى مشهد
                        <select x-on:change="quickNav($event)" data-quick-nav>
                            <option value="">— اختر مشهدًا لتحريره —</option>
                            @foreach ($d['flat'] as $i => $f)
                                <option value="{{ $f['id'] }}" @selected($editingScene === $f['id'])>{{ $i + 1 }}. {{ $f['chapter'] }} › {{ $f['title'] }} ({{ $d['types'][$f['type']] ?? $f['type'] }})</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                @foreach ($d['tree'] as $stage)
                    <div class="cin-stage" data-stage="{{ $stage->stable_key }}" wire:key="stage-{{ $stage->public_id }}" style="margin-bottom:.75rem">
                        <div class="cin-row" data-fold-key="stage:{{ $stage->stable_key }}">
                            <button type="button" class="cin-fold" x-on:click="toggle('stage:{{ $stage->stable_key }}')" :aria-expanded="!isClosed('stage:{{ $stage->stable_key }}')" aria-label="طيّ/فتح المرحلة">
                                @include('filament.pages.partials.cinematic-icon', ['name' => 'chevron'])
                                <span>المرحلة {{ $stage->position }}: {{ $stage->title }}</span>
                            </button>
                            <span class="cin-key">{{ $stage->stable_key }}</span>
                            @if ($stage->is_optional)<span class="cin-badge">اختيارية</span>@endif
                            @if ($editable && $canManage)
                                <span class="cin-scene-actions">
                                    <button type="button" wire:click="moveStage('{{ $stage->public_id }}', 'up')" class="cin-link-btn" aria-label="تحريك المرحلة للأعلى">↑</button>
                                    <button type="button" wire:click="moveStage('{{ $stage->public_id }}', 'down')" class="cin-link-btn" aria-label="تحريك المرحلة للأسفل">↓</button>
                                    <button type="button" wire:click="toggleStageOptional('{{ $stage->public_id }}')" class="cin-link-btn">{{ $stage->is_optional ? 'إلزامية' : 'اختيارية' }}</button>
                                    <button type="button" wire:click="removeStage('{{ $stage->public_id }}')" wire:confirm="حذف المرحلة بكل محتواها؟" class="cin-link-btn is-danger">حذف</button>
                                </span>
                            @endif
                        </div>

                        <div x-show="!isClosed('stage:{{ $stage->stable_key }}')">
                            @foreach ($stage->chapters as $chapter)
                                @php
                                    $chScenes = $chapter->scenes;
                                    $chVideos = $chScenes->where('type', 'video');
                                    $chReady = $chVideos->filter(fn ($s) => $s->video && $s->video->status === 'ready')->count();
                                @endphp
                                <div class="cin-chapter" data-chapter="{{ $chapter->stable_key }}" wire:key="chapter-{{ $chapter->public_id }}" style="margin-top:.6rem">
                                    <div class="cin-row" data-fold-key="chapter:{{ $chapter->stable_key }}">
                                        <button type="button" class="cin-fold" x-on:click="toggle('chapter:{{ $chapter->stable_key }}')" :aria-expanded="!isClosed('chapter:{{ $chapter->stable_key }}')">
                                            @include('filament.pages.partials.cinematic-icon', ['name' => 'chevron'])
                                            <span>الفصل {{ $chapter->position }}: {{ $chapter->title }}</span>
                                        </button>
                                        <span class="cin-badge">{{ $chScenes->count() }} مشهد</span>
                                        @if ($chVideos->count())<span class="cin-badge {{ $chReady === $chVideos->count() ? 'is-ok' : 'is-warn' }}">فيديو {{ $chReady }}/{{ $chVideos->count() }}</span>@endif
                                        @if ($chapter->is_optional)<span class="cin-badge">اختياري</span>@endif
                                        @if ($editable && $canManage)
                                            <span class="cin-scene-actions">
                                                <button type="button" wire:click="moveChapter('{{ $chapter->public_id }}', 'up')" class="cin-link-btn" aria-label="تحريك الفصل للأعلى">↑</button>
                                                <button type="button" wire:click="moveChapter('{{ $chapter->public_id }}', 'down')" class="cin-link-btn" aria-label="تحريك الفصل للأسفل">↓</button>
                                                <button type="button" wire:click="toggleChapterOptional('{{ $chapter->public_id }}')" class="cin-link-btn">{{ $chapter->is_optional ? 'إلزامي' : 'اختياري' }}</button>
                                                <button type="button" wire:click="removeChapter('{{ $chapter->public_id }}')" wire:confirm="حذف الفصل بمشاهده؟" class="cin-link-btn is-danger">حذف</button>
                                            </span>
                                        @endif
                                    </div>

                                    <div x-show="!isClosed('chapter:{{ $chapter->stable_key }}')">
                                        <ul style="display:flex;flex-direction:column;gap:.4rem;margin-top:.5rem">
                                            @foreach ($chScenes as $scene)
                                                @php
                                                    $selected = $editingScene === $scene->public_id;
                                                    $videoOk = $scene->video && $scene->video->status === 'ready';
                                                    $isInteractive = in_array($scene->type, ['interaction', 'exploration'], true);
                                                    $imageOk = $scene->poster && $scene->poster->status === 'ready';
                                                @endphp
                                                <li class="cin-scene {{ $selected ? 'is-selected' : '' }}" id="scene-row-{{ $scene->public_id }}" wire:key="scene-{{ $scene->public_id }}" data-scene="{{ $scene->stable_key }}" data-scene-id="{{ $scene->public_id }}" data-type="{{ $scene->type }}" @if ($selected) aria-current="true" @endif>
                                                    <span class="cin-badge cin-t-{{ $scene->type }}">@include('filament.pages.partials.cinematic-icon', ['name' => $scene->type]){{ $d['types'][$scene->type] ?? $scene->type }}</span>
                                                    <button type="button" class="cin-scene-name" wire:click="editScene('{{ $scene->public_id }}')" data-cin-guard data-scene-open>{{ $scene->title }}</button>
                                                    <span class="cin-key">{{ $scene->stable_key }}</span>
                                                    @if ($scene->type === 'video')
                                                        <span class="cin-badge {{ $videoOk ? 'is-ok' : 'is-warn' }}">{{ $scene->video ? ($videoOk ? 'فيديو جاهز' : 'فيديو مسحوب') : ($scene->awaiting_media ? 'ينتظر إنتاج الفيديو' : 'بلا فيديو') }}</span>
                                                    @elseif ($isInteractive)
                                                        <span class="cin-badge {{ $imageOk ? 'is-ok' : 'is-warn' }}">{{ $imageOk ? 'صورة المشهد مربوطة' : 'بلا صورة مشهد' }}</span>
                                                    @elseif ($imageOk)
                                                        <span class="cin-badge is-ok">صورة</span>
                                                    @endif
                                                    @if ($selected)<span class="cin-badge is-sel">قيد التحرير</span>@endif
                                                    @if ($scene->is_optional)<span class="cin-badge">اختياري</span>@endif
                                                    <span class="cin-scene-actions">
                                                        <button type="button" wire:click="editScene('{{ $scene->public_id }}')" class="cin-link-btn" data-cin-guard>{{ $editable && $canManage ? 'تحرير' : 'عرض' }}</button>
                                                        @if ($editable && $canManage)
                                                            <button type="button" wire:click="moveScene('{{ $scene->public_id }}', 'up')" class="cin-link-btn" aria-label="تحريك المشهد للأعلى">↑</button>
                                                            <button type="button" wire:click="moveScene('{{ $scene->public_id }}', 'down')" class="cin-link-btn" aria-label="تحريك المشهد للأسفل">↓</button>
                                                            <button type="button" wire:click="removeScene('{{ $scene->public_id }}')" wire:confirm="حذف المشهد؟" class="cin-link-btn is-danger">حذف</button>
                                                        @endif
                                                    </span>
                                                </li>
                                            @endforeach
                                        </ul>
                                        @if ($editable && $canManage)
                                            <details class="cin-more" style="margin-top:.6rem" wire:ignore.self wire:key="add-scene-{{ $chapter->public_id }}" data-no-dirty data-add-scene>
                                                <summary>+ إضافة مشهد إلى هذا الفصل</summary>
                                                <div class="cin-add-scene" style="margin-top:.5rem">
                                                    <label class="cin-field">النوع<select wire:model="newScene.{{ $chapter->public_id }}.type"><option value="narrative">سرد</option>@foreach ($d['types'] as $k => $l)@if ($k !== 'narrative')<option value="{{ $k }}">{{ $l }}</option>@endif @endforeach</select></label>
                                                    <label class="cin-field">العنوان<input type="text" wire:model="newScene.{{ $chapter->public_id }}.title" maxlength="160"></label>
                                                    <label class="cin-field">المفتاح (اختياري، لاتيني)<input type="text" wire:model="newScene.{{ $chapter->public_id }}.key" maxlength="64" dir="ltr"></label>
                                                    <button type="button" wire:click="addScene('{{ $chapter->public_id }}')" class="{{ $btn }}" data-cin-guard>إضافة مشهد</button>
                                                </div>
                                            </details>
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            @if ($editable && $canManage)
                                <div class="cin-row" style="margin-top:.6rem;align-items:flex-end" data-no-dirty>
                                    <label class="cin-field" style="flex:1 1 14rem">فصل جديد<input type="text" wire:model="newChapter.{{ $stage->public_id }}" maxlength="160"></label>
                                    <button type="button" wire:click="addChapter('{{ $stage->public_id }}')" class="{{ $btnGhost }}">إضافة فصل</button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach

                @if ($editable && $canManage)
                    <div class="cin-row" style="align-items:flex-end" data-no-dirty>
                        <label class="cin-field" style="flex:1 1 14rem">مرحلة جديدة<input type="text" wire:model="newStage" maxlength="160"></label>
                        <button type="button" wire:click="addStage" class="{{ $btn }}">إضافة مرحلة</button>
                    </div>
                @endif
            </section>

            {{-- لوحة المحرّر --}}
            <div class="cin-editor-col" data-editor-col>
                @if ($editing && $editingScene && ! empty($sf))
                    @php
                        $type = $sf['type'];
                        $model = $editing['model'];
                        $kind = $sf['kind'] ?? 'choose_item';
                        $isInteractionType = in_array($type, ['interaction', 'exploration'], true);
                        $mediaRoles = ['video' => 'الفيديو', 'poster' => ($type === 'video' ? 'الملصق (صورة مصغّرة)' : 'صورة المشهد (خلفية التفاعل)'), 'subtitle' => 'الترجمة (WebVTT)', 'audio' => 'صوت إضافي'];
                    @endphp
                    <section class="cin-editor" data-scene-editor wire:key="scene-editor-{{ $editingScene }}" aria-labelledby="cin-editor-heading">
                        <div class="cin-editor-head">
                            <div class="cin-row">
                                <h3 id="cin-editor-heading" class="cin-title" tabindex="-1" data-scene-heading>{{ $editable && $canManage ? 'تحرير المشهد' : 'عرض المشهد' }}: {{ $model->title }}</h3>
                                <span class="cin-badge cin-t-{{ $type }}">@include('filament.pages.partials.cinematic-icon', ['name' => $type]){{ $d['types'][$type] ?? $type }}</span>
                                <span class="cin-key" data-scene-key>{{ $sf['key'] }}</span>
                            </div>
                            <p class="cin-muted" style="margin-top:.25rem">{{ $editing['stage'] }} › {{ $editing['chapter'] }} · المشهد {{ $editing['index'] }} من {{ $counts['scenes'] }}</p>
                            <div class="cin-row" style="margin-top:.5rem">
                                <button type="button" class="cin-btn cin-btn-sm" x-on:click="backToList()">↺ موضع المشهد في القائمة</button>
                                @if ($editing['prev'])<button type="button" class="cin-btn cin-btn-sm" wire:click="editScene('{{ $editing['prev']['id'] }}')" data-cin-guard title="{{ $editing['prev']['title'] }}">→ المشهد السابق</button>@endif
                                @if ($editing['next'])<button type="button" class="cin-btn cin-btn-sm" wire:click="editScene('{{ $editing['next']['id'] }}')" data-cin-guard title="{{ $editing['next']['title'] }}">المشهد التالي ←</button>@endif
                                @if ($editing['previewUrl'])<a href="{{ $editing['previewUrl'] }}" target="_blank" rel="noopener" class="cin-btn cin-btn-sm" data-scene-preview-link>معاينة هذا المشهد</a>@endif
                                @if ($editing['playerPreviewUrl'])<a href="{{ $editing['playerPreviewUrl'] }}" target="_blank" rel="noopener" class="cin-btn cin-btn-sm" data-scene-player-preview-link>معاينة كما يراها اللاعب</a>@endif
                                <button type="button" wire:click="closeScene" class="cin-btn cin-btn-sm cin-row-end" data-cin-guard>إغلاق المحرّر</button>
                            </div>
                            <p class="cin-note is-warn" x-show="dirty" x-cloak style="margin-top:.5rem" role="status" data-dirty-note>لديك تغييرات غير محفوظة في هذا المشهد. اضغط «حفظ المشهد» قبل الانتقال إلى مشهد آخر.</p>
                        </div>

                        <div class="cin-editor-body">
                            @if ($editing['blockers'] || $editing['warnings'])
                                <div class="cin-note {{ $editing['blockers'] ? 'is-warn' : '' }}" data-scene-readiness>
                                    <b>جاهزية هذا المشهد:</b>
                                    <ul>
                                        @foreach ($editing['blockers'] as $b)<li>{{ $b['message'] }}</li>@endforeach
                                        @foreach ($editing['warnings'] as $w)<li>{{ $w['message'] }}</li>@endforeach
                                    </ul>
                                </div>
                            @else
                                <div class="cin-note is-ok" data-scene-readiness>لا توجد ملاحظات جاهزية على هذا المشهد.</div>
                            @endif

                            <fieldset class="cin-block" @disabled(! $editable || ! $canManage)>
                                <legend class="cin-block-title">بيانات المشهد</legend>
                                <div class="cin-grid cin-grid-2">
                                    <label class="cin-field">العنوان<input type="text" wire:model="sf.title" maxlength="160"></label>
                                    <label class="cin-field">وصف قصير<input type="text" wire:model="sf.description" maxlength="800"></label>
                                    <label class="cin-field">مدة تقديرية (ثوانٍ)<input type="number" wire:model="sf.duration" min="1" max="3600" dir="ltr"></label>
                                    <label class="cin-field">شرط الفتح: مفاتيح مشاهد يجب إنجازها قبل هذا المشهد (مفصولة بفواصل)<input type="text" wire:model="sf.requires" dir="ltr"></label>
                                    <label class="cin-check"><input type="checkbox" wire:model="sf.optional">مشهد اختياري (لا يحجب ما بعده)</label>
                                    <label class="cin-check"><input type="checkbox" wire:model="sf.replay">يسمح بإعادة المشهد بعد إتمامه</label>
                                    @if ($type === 'video')
                                        <label class="cin-check"><input type="checkbox" wire:model="sf.skippable">يسمح بتخطي الفيديو</label>
                                        <label class="cin-check"><input type="checkbox" wire:model="sf.awaiting">بانتظار إنتاج الفيديو</label>
                                    @endif
                                </div>
                            </fieldset>

                            <fieldset class="cin-block" @disabled(! $editable || ! $canManage)>
                                <legend class="cin-block-title">{{ $type === 'video' ? 'الحوار البديل' : ($type === 'narrative' ? 'نص السرد' : ($type === 'transition' ? 'نص الانتقال' : ($isInteractionType ? 'التفاعل' : 'الأحجية'))) }}</legend>
                                @if ($type === 'video')
                                    <label class="cin-field">النص البديل / الحوار (سطر لكل جملة: «المتحدث|النص»)<textarea wire:model="sf.transcript" rows="5"></textarea></label>
                                @elseif ($type === 'narrative')
                                    <label class="cin-field">النص (سطر لكل فقرة: «المتحدث|النص» أو النص وحده)<textarea wire:model="sf.lines" rows="6"></textarea></label>
                                @elseif ($type === 'transition')
                                    <div class="cin-grid cin-grid-3">
                                        <label class="cin-field">العنوان<input type="text" wire:model="sf.t_title" maxlength="160"></label>
                                        <label class="cin-field">العنوان الفرعي<input type="text" wire:model="sf.t_sub" maxlength="300"></label>
                                        <label class="cin-field">عنوان المرحلة التالية<input type="text" wire:model="sf.t_next" maxlength="160"></label>
                                    </div>
                                @else
                                    <label class="cin-field">تمهيد قبل المشهد (سطر لكل جملة)<textarea wire:model="sf.intro" rows="3"></textarea></label>
                                    <div class="cin-grid cin-grid-3">
                                        <label class="cin-field">أقصى عدد محاولات (اختياري)<input type="number" wire:model="sf.max_attempts" min="1" max="50" dir="ltr"></label>
                                        @if ($type === 'puzzle')
                                            <label class="cin-field">رسالة الخطأ<input type="text" wire:model="sf.wrong_message" maxlength="300"></label>
                                            <label class="cin-field">رسالة النجاح<input type="text" wire:model="sf.success_message" maxlength="300"></label>
                                        @endif
                                    </div>
                                @endif

                                @if ($type === 'puzzle')
                                    <div class="cin-field" data-puzzle-picker>
                                        <label id="cin-puzzle-label" for="cin-puzzle">الأحجية (رسمية، مفعّلة، كلاسيكية فقط)</label>
                                        <x-searchable-select id="cin-puzzle" label-id="cin-puzzle-label" wire:model="sf.puzzle_id" wire:key="ss-puzzle-{{ $editingScene }}"
                                            method="searchPuzzles" :value="(string) ($sf['puzzle_id'] ?? '')" :selected="$d['puzzlePicked']" allow-none name-dir="auto"
                                            placeholder="ابحث بعنوان الأحجية…" none="— بلا أحجية —" remove-label="إزالة الأحجية" empty-all="لا توجد أحجيات صالحة للربط." />
                                    </div>
                                @endif

                                @if ($isInteractionType)
                                    <div class="cin-block" data-interaction-editor>
                                        <label class="cin-field">نوع التفاعل
                                            <select wire:model.live="sf.kind">@foreach ($d['kinds'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                                        </label>
                                        <div class="cin-grid cin-grid-2">
                                            <label class="cin-field">السؤال/التعليمات الظاهرة<input type="text" wire:model="sf.ix.prompt" maxlength="600"></label>
                                            <label class="cin-field">تعليمات إضافية<input type="text" wire:model="sf.ix.instructions" maxlength="600"></label>
                                            <label class="cin-field">وصف بديل للمشهد (للقارئات الصوتية)<input type="text" wire:model="sf.ix.alt_text" maxlength="600"></label>
                                            <label class="cin-field">رسالة الشرط غير المتحقق<input type="text" wire:model="sf.ix.locked_message" maxlength="600"></label>
                                            <label class="cin-field" data-ix-success-message>رسالة النجاح (بأسلوب القصة — تظهر للاعب عند الحل)<input type="text" wire:model="sf.ix.success_message" maxlength="300" placeholder="مثال: تحرّك المقبض بين أصابعك، وانفتح الباب ببطء."></label>
                                            <label class="cin-field" data-ix-wrong-message>رسالة الخطأ (اختيارية)<input type="text" wire:model="sf.ix.wrong_message" maxlength="300" placeholder="مثال: لا شيء هنا… ابحث في مكان آخر."></label>
                                        </div>

                                        @if (in_array($kind, ['hotspot', 'container'], true))
                                            {{-- معايرة بصرية: تحريك/تحجيم المنطقة مباشرة فوق صورة المشهد، وتكتب في الحقل النصي أدناه بنفس صيغته --}}
                                            <div class="cin-block" data-calibrator-block>
                                                <div class="cin-block-title">معايرة مناطق النقر فوق الصورة</div>
                                                @if ($editing['imageUrl'])
                                                    @include('filament.pages.partials.cinematic-calibrator', ['format' => 'areas', 'source' => 'cin-src-areas', 'editing' => $editing, 'pickedPoster' => $sf['media']['poster'] ?? '', 'prefix' => 'area', 'canEdit' => $editable && $canManage])
                                                @elseif (($sf['media']['poster'] ?? '') !== '')
                                                    <p class="cin-note is-warn" data-calibrator-noaccess>صورة المشهد مربوطة، لكن عرضها داخل المحرّر يحتاج صلاحية «الوسائط» أو «المعاينة». يمكنك تعديل الإحداثيات يدويًا من الحقل أدناه.</p>
                                                @else
                                                    <p class="cin-note is-warn" data-calibrator-noimage>لا توجد صورة مختارة لهذا المشهد. اختر «صورة المشهد» في قسم الوسائط أدناه (أو ارفع صورة جديدة) لتظهر هنا ويمكن ضبط المناطق عليها.</p>
                                                @endif
                                                <details>
                                                    <summary class="cin-link-btn">تحرير الإحداثيات يدويًا (متقدّم)</summary>
                                                    <label class="cin-field" style="margin-top:.5rem">المناطق — سطر لكل منطقة: <span class="cin-ltr">id|الاسم|x|y|العرض|الارتفاع</span> (نسب 0–100 من الصورة)
                                                        <textarea id="cin-src-areas" wire:model="sf.ix.areas" rows="4" dir="ltr"></textarea>
                                                    </label>
                                                </details>
                                            </div>
                                        @endif
                                        @if (in_array($kind, ['sequence', 'drag_drop', 'choose_item', 'compare'], true))
                                            <label class="cin-field">{{ $kind === 'compare' ? 'الخيارات' : 'العناصر' }} — سطر لكل عنصر: <span class="cin-ltr">id|الاسم|الرمز(اختياري)</span><textarea wire:model="sf.ix.items" rows="4" dir="ltr"></textarea></label>
                                        @endif
                                        @if ($kind === 'drag_drop')
                                            <label class="cin-field">الأهداف — <span class="cin-ltr">id|الاسم</span><textarea wire:model="sf.ix.targets" rows="3" dir="ltr"></textarea></label>
                                        @endif
                                        @if ($kind === 'flashlight')
                                            <div class="cin-grid cin-grid-3">
                                                <label class="cin-field">نصف قطر الضوء (3–60)<input type="number" wire:model="sf.ix.light_radius" dir="ltr"></label>
                                                <label class="cin-field">عدد المخفيات المطلوب العثور عليها<input type="number" wire:model="sf.ix.required" dir="ltr"></label>
                                            </div>
                                        @endif
                                        @if ($kind === 'reveal_code')
                                            <label class="cin-field">نص زر الكشف<input type="text" wire:model="sf.ix.reveal_label" maxlength="600"></label>
                                        @endif
                                        @if ($kind === 'answer')
                                            <div class="cin-grid cin-grid-3" data-ix-answer-public>
                                                <label class="cin-field">نوع الإدخال
                                                    <select wire:model="sf.ix.input"><option value="number">رقم (يقبل ٠-٩ و0-9)</option><option value="text">نص</option></select>
                                                </label>
                                                <label class="cin-field">نص توضيحي داخل الحقل<input type="text" wire:model="sf.ix.placeholder" maxlength="80"></label>
                                                <label class="cin-field">وحدة تُعرض بجانب الحقل (مثل: دقيقة)<input type="text" wire:model="sf.ix.unit_label" maxlength="12"></label>
                                            </div>
                                            <label class="cin-field">معطيات تُعرض أمام اللاعب — سطر: <span class="cin-ltr">الاسم|القيمة</span> (مثل: المكتوب في الدفتر|11:40)<textarea wire:model="sf.ix.facts" rows="3"></textarea></label>
                                        @endif
                                        @if ($kind === 'inspect')
                                            {{-- CIN-2: المعايرة البصرية نفسها لمناطق الفحص (الصيغة النصية نفسها id|الاسم|x|y|العرض|الارتفاع؛ لا مسار حفظ جديد). --}}
                                            <div class="cin-block" data-calibrator-block>
                                                <div class="cin-block-title">معايرة مناطق الفحص فوق الصورة</div>
                                                @if ($editing['imageUrl'])
                                                    @include('filament.pages.partials.cinematic-calibrator', ['format' => 'areas', 'source' => 'cin-src-inspect', 'editing' => $editing, 'pickedPoster' => $sf['media']['poster'] ?? '', 'prefix' => 'area', 'canEdit' => $editable && $canManage])
                                                @elseif (($sf['media']['poster'] ?? '') !== '')
                                                    <p class="cin-note is-warn" data-calibrator-noaccess>صورة المشهد مربوطة، لكن عرضها داخل المحرّر يحتاج صلاحية «الوسائط» أو «المعاينة». يمكنك تعديل الإحداثيات يدويًا من الحقل أدناه.</p>
                                                @else
                                                    <p class="cin-note is-warn" data-calibrator-noimage>لا توجد صورة مختارة لهذا المشهد. اختر «صورة المشهد» في قسم الوسائط أدناه (أو ارفع صورة جديدة) لتظهر هنا ويمكن ضبط المناطق عليها.</p>
                                                @endif
                                            </div>
                                            <label class="cin-field">مناطق الفحص — سطر: <span class="cin-ltr">id|الاسم|x|y|العرض|الارتفاع</span> (نسب 0–100 من الصورة؛ عدّلها بالأرقام ثم تحقّق بالمعاينة)<textarea id="cin-src-inspect" wire:model="sf.ix.areas" rows="5" dir="ltr"></textarea></label>
                                            <label class="cin-field">ملاحظة تظهر عند فحص كل منطقة — سطر: <span class="cin-ltr">id|النص</span><textarea wire:model="sf.ix.notes" rows="4"></textarea></label>
                                            <div class="cin-grid cin-grid-2">
                                                <label class="cin-field">«قلب الصورة»: اسم الزر/الوجه الخلفي (اختياري)<input type="text" wire:model="sf.ix.back_label" maxlength="40" placeholder="خلف الصورة"></label>
                                                <label class="cin-field">نص الوجه الخلفي (يظهر عند قلب الصورة؛ يُحتسب كمنطقة فحص بالمعرّف <span class="cin-ltr">back</span>)<textarea wire:model="sf.ix.back_text" rows="2" maxlength="600"></textarea></label>
                                            </div>
                                        @endif
                                        @if ($kind === 'multi_lock')
                                            <label class="cin-field">الأقفال — سطر لكل قفل: <span class="cin-ltr">id|الاسم|المواصفات</span> — المواصفات: <span class="cin-ltr">D2</span> خانتان رقميتان، <span class="cin-ltr">L(أ,ب,ج)</span> حرف من خيارات، ويمكن الجمع بـ <span class="cin-ltr">+</span>
                                                <textarea wire:model="sf.ix.locks" rows="3" dir="ltr"></textarea></label>
                                        @endif

                                        @if ($canManage)
                                            <div class="cin-block" data-solution-editor>
                                                <p class="cin-block-title">الحل — يُحفظ مشفّرًا على الخادم ولا يصل اللاعبين أبدًا</p>
                                                @if ($kind === 'hotspot')<label class="cin-field">المناطق الصحيحة (معرّفات مفصولة بفواصل)<input type="text" wire:model="sf.ix.correct" dir="ltr"></label>@endif
                                                @if (in_array($kind, ['container', 'choose_item', 'compare'], true))<label class="cin-field">المعرّف الصحيح<input type="text" wire:model="sf.ix.correct" dir="ltr"></label>@endif
                                                @if ($kind === 'sequence')<label class="cin-field">الترتيب الصحيح (معرّفات مفصولة بفواصل)<input type="text" wire:model="sf.ix.order" dir="ltr"></label>@endif
                                                @if ($kind === 'drag_drop')<label class="cin-field">الربط الصحيح — سطر: <span class="cin-ltr">item=target</span><textarea wire:model="sf.ix.map" rows="3" dir="ltr"></textarea></label>@endif
                                                @if ($kind === 'reveal_code')<label class="cin-field">الرمز الذي يُكشف<input type="text" wire:model="sf.ix.code" maxlength="64" dir="ltr"></label>@endif
                                                @if (in_array($kind, ['container', 'reveal_code', 'answer', 'inspect', 'multi_lock'], true))<label class="cin-field">لا يعمل إلا بعد إنجاز المشاهد (مفاتيح مفصولة بفواصل)<input type="text" wire:model="sf.ix.requires" dir="ltr"></label>@endif
                                                @if ($kind === 'answer')
                                                    <label class="cin-field">الإجابات المقبولة — سطر لكل إجابة (تُقارَن بعد توحيد الأرقام العربية/الإنجليزية)<textarea wire:model="sf.ix.accepted" rows="3" dir="ltr"></textarea></label>
                                                    <label class="cin-field">وحدات تُهمَل إن كتبها اللاعب (مفصولة بفواصل)<input type="text" wire:model="sf.ix.units" dir="auto" placeholder="دقيقة,دقائق,min"></label>
                                                @endif
                                                @if ($kind === 'inspect')<label class="cin-field">المناطق التي يجب فحصها قبل إكمال المشهد (معرّفات مفصولة بفواصل)<input type="text" wire:model="sf.ix.inspect_required" dir="ltr"></label>@endif
                                                @if ($kind === 'multi_lock')
                                                    <label class="cin-field">الرمز الصحيح لكل قفل — سطر: <span class="cin-ltr">id=الرمز</span> (مثل: lock_b=ب04)<textarea wire:model="sf.ix.codes" rows="3" dir="auto"></textarea></label>
                                                    <label class="cin-field">تلميح لكل قفل عند الخطأ — سطر: <span class="cin-ltr">id=التلميح</span><textarea wire:model="sf.ix.lock_hints" rows="3" dir="auto"></textarea></label>
                                                @endif
                                                <label class="cin-field">نص يُكشف بعد النجاح فقط (من الخادم) — اختياري<textarea wire:model="sf.ix.reveal" rows="2" maxlength="600"></textarea></label>
                                                <label class="cin-field">تلميحات تُعرض بالتتابع عند الخطأ — سطر لكل تلميح<textarea wire:model="sf.ix.hints" rows="3"></textarea></label>
                                                @if ($kind === 'flashlight')
                                                    @if ($editing['imageUrl'])
                                                        @include('filament.pages.partials.cinematic-calibrator', ['format' => 'hidden', 'source' => 'cin-src-hidden', 'editing' => $editing, 'pickedPoster' => $sf['media']['poster'] ?? '', 'prefix' => 'hidden', 'canEdit' => $editable && $canManage])
                                                    @endif
                                                    <label class="cin-field">المخفيات — سطر: <span class="cin-ltr">id|x|y|العرض|الارتفاع</span><textarea id="cin-src-hidden" wire:model="sf.ix.hidden" rows="3" dir="ltr"></textarea></label>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </fieldset>

                            {{-- CIN-2: بطاقة «سجل الأدلة» لهذا المشهد (تظهر للاعب فقط بعد إنجاز المشهد) + علامة الاعتماد التحريري --}}
                            <fieldset class="cin-block" data-scene-evidence @disabled(! ($editable && $canManage))>
                                <legend class="cin-block-title">سجل الأدلة (CIN-2)</legend>
                                <p class="cin-help">تُعرض هذه البطاقة للاعب في «سجل الأدلة» بعد إنجاز المشهد فقط. اتركها فارغة إن لم يكن للمشهد دليل. لا تكتب هنا حلولًا لمشاهد لم تُنجَز بعد.</p>
                                <div class="cin-grid cin-grid-2">
                                    <label class="cin-field">عنوان الدليل<input type="text" wire:model="sf.ev.title" maxlength="120"></label>
                                    <label class="cin-field">رقم الدليل (1–9، اختياري)<input type="number" wire:model="sf.ev.clue" min="1" max="9" dir="ltr"></label>
                                </div>
                                <label class="cin-field">ملخص الدليل<textarea wire:model="sf.ev.summary" rows="3" maxlength="600"></textarea></label>
                                <div class="cin-grid cin-grid-2">
                                    <label class="cin-check"><input type="checkbox" wire:model="sf.ev.image"> إظهار صورة المشهد ضمن بطاقة الدليل</label>
                                    <label class="cin-field">علامة على الصورة — <span class="cin-ltr">x|y|الاسم</span> (نسب 0–100، اختياري)<input type="text" wire:model="sf.ev.marker" dir="ltr"></label>
                                </div>
                                <label class="cin-field">حالة الاعتماد التحريري
                                    <select wire:model="sf.editorial">
                                        <option value="">— غير محددة —</option>
                                        <option value="proposal">مقترح تنفيذي — يحتاج اعتماد ياسمين/المالك</option>
                                        <option value="approved">معتمد</option>
                                    </select>
                                </label>
                            </fieldset>

                            {{-- الوسائط: تبديل الملفات مسموح بصلاحية media حتى على النسخة المنشورة --}}
                            <fieldset class="cin-block" data-scene-media @disabled(! $canMedia)>
                                <legend class="cin-block-title">وسائط المشهد</legend>
                                <p class="cin-help">«الرفع» يضع الملف في مكتبة الوسائط فقط، و«الربط» (هنا) يجعل هذا المشهد يستعمل ملفًا موجودًا في المكتبة — دون نسخه من جديد.
                                    @if ($editing['uploadUrl'])<a href="{{ $editing['uploadUrl'] }}" data-cin-guard data-upload-from-scene>رفع ملف جديد ثم العودة إلى هذا المشهد</a>@endif</p>
                                @if (! $canMedia)<p class="cin-note">ليس لديك صلاحية الوسائط: يمكنك رؤية الارتباطات الحالية فقط.</p>@endif

                                @foreach ($mediaRoles as $role => $label)
                                    @continue($role === 'video' && $type !== 'video')
                                    @php
                                        $pickedId = (string) ($sf['media'][$role] ?? '');
                                        $savedAsset = $model->{$role};
                                        $savedId = (string) $savedAsset?->public_id;
                                        $pickedAsset = $d['picked'][$role]['model'] ?? null;
                                        $others = $pickedAsset ? collect($d['usage'][$pickedAsset->id] ?? [])->reject(fn ($u) => $u['id'] === $editingScene)->values() : collect();
                                        $assetUrl = $pickedAsset ? $this->libraryUrl($pickedAsset) : null;
                                    @endphp
                                    <div class="cin-field" data-media-role="{{ $role }}">
                                        <label id="cin-media-{{ $role }}-label" for="cin-media-{{ $role }}">{{ $label }}</label>
                                        <x-searchable-select id="cin-media-{{ $role }}" label-id="cin-media-{{ $role }}-label" wire:model.live="sf.media.{{ $role }}" wire:key="ss-media-{{ $role }}-{{ $editingScene }}"
                                            method="searchMedia" :context="['kind' => $role]" :value="$pickedId" :selected="$d['picked'][$role]['desc'] ?? null" allow-none :disabled="! $canMedia"
                                            placeholder="ابحث باسم الملف…" none="— بلا —" remove-label="إزالة الوسيط من المشهد" />

                                        <div class="cin-row">
                                            @if ($pickedId === $savedId)
                                                <span class="cin-badge {{ $savedAsset ? ($savedAsset->status === 'ready' ? 'is-ok' : 'is-err') : '' }}" data-media-state="saved">{{ $savedAsset ? ($savedAsset->status === 'ready' ? ($role === 'video' ? 'فيديو جاهز ومربوط' : 'مربوط') : 'الملف المربوط مسحوب من المكتبة') : 'غير مربوط' }}</span>
                                            @else
                                                <span class="cin-badge is-warn" data-media-state="pending">اختيار غير محفوظ — اضغط «حفظ الوسائط»</span>
                                            @endif
                                        </div>

                                        @if ($pickedAsset)
                                            <div class="cin-asset" data-media-card>
                                                @if ($role === 'poster' && $assetUrl)<img src="{{ $assetUrl }}" alt="معاينة {{ $pickedAsset->original_name }}" loading="lazy">@endif
                                                <div class="cin-asset-meta">
                                                    <span class="cin-asset-name cin-ltr" dir="ltr">{{ $pickedAsset->original_name }}</span>
                                                    <span class="cin-muted">@foreach (\App\Services\Cinematic\Media\CinematicMediaPicker::segments(implode(' · ', array_filter([$fmtSize($pickedAsset->size_bytes), $fmtDur($pickedAsset->duration_ms), $pickedAsset->width && $pickedAsset->height ? $pickedAsset->width.'×'.$pickedAsset->height : null, $pickedAsset->mime]))) as $seg)@if ($seg['ltr'])<bdi dir="ltr">{{ $seg['t'] }}</bdi>@else{{ $seg['t'] }}@endif @endforeach</span>
                                                </div>
                                                @if ($assetUrl && $role !== 'poster')<a href="{{ $assetUrl }}" target="_blank" rel="noopener" class="cin-btn cin-btn-sm">@include('filament.pages.partials.cinematic-icon', ['name' => 'eye']) معاينة الملف</a>@endif
                                            </div>
                                            @if ($others->isNotEmpty())
                                                <p class="cin-note {{ $role === 'video' ? 'is-warn' : '' }}" data-media-shared>
                                                    @if ($role === 'video')⚠ هذا الفيديو مربوط أيضًا بمشهد آخر في هذه النسخة: @else يُستعمل أيضًا في: @endif
                                                    {{ $others->map(fn ($u) => '«'.$u['title'].'»')->implode('، ') }}.
                                                    @if ($role === 'video') ربط الفيديو نفسه بمشهدين يعرضه مرتين للاعب؛ إن كان الملف يجمع مشهدين فاقسمه قبل الرفع إلى ملفين.@endif
                                                </p>
                                            @endif
                                        @endif
                                    </div>
                                @endforeach

                                @if ($canMedia && in_array($rev->status, ['draft', 'in_review', 'published'], true))
                                    <div class="cin-row">
                                        <button type="button" wire:click="saveMedia" wire:loading.attr="disabled" wire:target="saveMedia" class="{{ $btn }}" data-save-media>حفظ الوسائط</button>
                                        <span wire:loading wire:target="saveMedia" class="cin-spin" aria-hidden="true"></span>
                                    </div>
                                @endif
                            </fieldset>
                        </div>

                        <div class="cin-savebar" data-savebar>
                            @if ($editable && $canManage)
                                <button type="button" wire:click="saveScene" wire:loading.attr="disabled" wire:target="saveScene" class="{{ $btn }}" data-save-scene>حفظ المشهد</button>
                                <span wire:loading wire:target="saveScene" class="cin-spin" aria-hidden="true"></span>
                            @endif
                            <span class="cin-badge is-warn" x-show="dirty" x-cloak>تغييرات غير محفوظة</span>
                            <span class="cin-muted" x-show="!dirty" x-cloak>لا تغييرات معلّقة</span>
                            @if ($editing['previewUrl'])<a href="{{ $editing['previewUrl'] }}" target="_blank" rel="noopener" class="cin-btn cin-row-end">معاينة هذا المشهد</a>@endif
                        </div>
                    </section>
                @else
                    <div class="cin-placeholder" data-editor-placeholder>
                        <p class="cin-title">اختر مشهدًا لتحريره</p>
                        <p>اضغط «تحرير» أو اسم المشهد في القائمة، فيظهر محرّره هنا مع حقوله ووسائطه وأداة معايرة مناطق النقر.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
