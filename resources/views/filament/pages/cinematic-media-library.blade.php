<x-filament-panels::page>
    @php
        $d = $this->data();
        $ctx = $d['context'];
        $role = $d['role'];
        $fmtSize = fn ($b) => $b === null ? '' : ($b >= 1048576 ? number_format($b / 1048576, 1).' م.ب' : max(1, (int) round($b / 1024)).' ك.ب');
        $fmtDur = fn ($ms) => $ms ? (($s = (int) round($ms / 1000)) >= 60 ? intdiv($s, 60).':'.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT).' د' : $s.' ث') : '';
        $accept = ['video' => 'video/mp4', 'poster' => 'image/jpeg,image/png,image/webp', 'subtitle' => '.vtt,text/vtt', 'audio' => 'audio/mpeg,audio/mp4'][$role];
        $statusLabels = ['draft' => 'مسودة', 'in_review' => 'قيد المراجعة', 'published' => 'منشورة', 'superseded' => 'مستبدلة', 'unpublished' => 'مسحوبة'];
        $typeLabels = ['video' => 'فيديو', 'narrative' => 'سرد', 'puzzle' => 'أحجية', 'interaction' => 'تفاعل', 'exploration' => 'استكشاف', 'transition' => 'انتقال'];
        $limits = $d['limits'];
        $maxMb = ! empty($limits['max_bytes']) ? number_format($limits['max_bytes'] / 1048576, 0).' ميغابايت' : null;
    @endphp

    @include('filament.pages.partials.cinematic-admin-styles')
    @include('filament.pages.partials.cinematic-admin-scripts')

    <div class="cin-admin" data-cin-root>

        {{-- سياق المشهد: عودة سريعة بعد الرفع --}}
        @if ($ctx)
            <div class="cin-note is-ok" role="status" data-media-context>
                <div class="cin-row" style="justify-content:space-between;align-items:center">
                    <div>
                        <strong>تعمل الآن على وسائط المشهد: «{{ $ctx['title'] }}»</strong>
                        <span class="cin-key">{{ $ctx['key'] }}</span>
                        <div class="cin-help">{{ $ctx['storyTitle'] }}@if ($ctx['stage']) · {{ $ctx['stage'] }}@endif @if ($ctx['chapter']) › {{ $ctx['chapter'] }}@endif — {{ $typeLabels[$ctx['type']] ?? $ctx['type'] }}</div>
                    </div>
                    @if ($ctx['builderUrl'])
                        <a href="{{ $ctx['builderUrl'] }}" class="cin-btn cin-btn-primary" data-return-to-scene>العودة إلى المشهد</a>
                    @endif
                </div>
            </div>
        @endif

        {{-- كيف تعمل الوسائط: رفع ثم ربط --}}
        <div class="cin-card" data-media-howto>
            <h3 class="cin-section-title">رفع الملف شيء، وربطه بالمشهد شيء آخر</h3>
            <ol class="cin-help" style="padding-inline-start:1.2rem;list-style:decimal">
                <li><strong>الرفع</strong> (هنا): يضيف الملف إلى المكتبة بعد فحصه على الخادم، ولا يربطه بأي مشهد.</li>
                <li><strong>الربط</strong> (من محرّر المشهد ← قسم «الوسائط»): تختار الملف من القائمة وتحفظ. الربط مجرد إشارة إلى الملف الموجود، فلا يُنسخ الملف ولا يُرفع مرة ثانية.</li>
                <li>بعد الحفظ يظهر «فيديو جاهز»، ويمكنك فتح «معاينة هذا المشهد» مباشرة من المحرّر.</li>
            </ol>
        </div>

        <nav class="cin-row" aria-label="نوع الوسيط" data-media-roles>
            @foreach ($d['roles'] as $key => $label)
                <a href="{{ \App\Filament\Pages\CinematicMediaLibraryPage::getUrl(array_merge(['role' => $key], $ctx['query'] ?? [])) }}" class="cin-btn {{ $role === $key ? 'cin-btn-primary' : '' }}" @if ($role === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        <p class="cin-help">
            تُفحص الملفات على الخادم (النوع الحقيقي لا الامتداد، الحجم، وخصائص الفيديو). الحدود الحالية: {{ $maxMb ?? '—' }}@if (! empty($limits['max_duration_seconds'])) · المدة حتى {{ $limits['max_duration_seconds'] }} ثانية @endif @if (! empty($limits['max_width'])) · الأبعاد حتى {{ $limits['max_width'] }}×{{ $limits['max_height'] }} @endif.
            حدود PHP (upload_max_filesize / post_max_size) يضبطها مسؤول الاستضافة.
            @if ($role === 'video') الأفضل: MP4 (H.264 + AAC) بنسبة 16:9. @elseif ($role === 'poster') الأفضل: صورة بنسبة 16:9 (تُستعمل أيضًا كصورة خلفية للمشاهد التفاعلية). @endif
        </p>

        @if ($d['canUpload'])
            <section class="cin-card" data-media-upload wire:ignore
                x-data="cinUploader({ url: '{{ $d['uploadUrl'] }}', role: '{{ $role }}' })">
                <h3 class="cin-section-title">رفع {{ $d['roles'][$role] }}</h3>
                <div class="cin-dropzone" :class="dragOver ? 'is-over' : ''"
                     x-on:dragover.prevent="dragOver = true" x-on:dragleave.prevent="dragOver = false" x-on:drop.prevent="drop($event)">
                    <div class="cin-row" style="align-items:center">
                        <input type="file" x-ref="file" accept="{{ $accept }}" multiple x-on:change="pick($event)" :disabled="busy" aria-label="اختيار ملف للرفع">
                        <span class="cin-help">أو اسحب الملف وأفلته هنا.</span>
                    </div>
                </div>

                <template x-if="files.length">
                    <ul class="cin-help" data-upload-files>
                        <template x-for="f in files" :key="f.name + f.size"><li><span x-text="f.name"></span> — <span x-text="(f.size >= 1048576 ? (f.size / 1048576).toFixed(1) + ' م.ب' : Math.max(1, Math.round(f.size / 1024)) + ' ك.ب')"></span></li></template>
                    </ul>
                </template>

                <div class="cin-row" style="align-items:center">
                    <button type="button" class="cin-btn cin-btn-primary" x-on:click="start()" :disabled="busy || !files.length" data-upload-start>
                        <span x-text="busy ? 'جارٍ الرفع…' : 'رفع وفحص'"></span>
                    </button>
                    <span class="cin-help" x-show="files.length && !busy" x-text="'الحجم الكلي: ' + totalText"></span>
                </div>

                <div x-show="busy" x-cloak data-upload-progress>
                    <div class="cin-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="percent" aria-label="تقدّم رفع الملف"><span :style="'width:' + percent + '%'"></span></div>
                    <p class="cin-help" role="status" aria-live="polite"><span x-text="percent + '% — '"></span><span x-text="phase"></span></p>
                </div>

                <ul class="cin-help" style="display:flex;flex-direction:column;gap:.35rem" aria-live="polite" data-upload-results>
                    <template x-for="(r, i) in results" :key="i">
                        <li class="cin-note" :class="!r.ok ? 'is-err' : (r.duplicate ? 'is-warn' : 'is-ok')">
                            <strong x-text="r.name"></strong>
                            <span x-show="r.ok && !r.duplicate"> — رُفع وفُحص بنجاح وأُضيف إلى المكتبة (لم يُربط بأي مشهد بعد).</span>
                            <span x-show="r.ok && r.duplicate"> — موجود مسبقًا في المكتبة بالمحتوى نفسه (بصمة SHA-256) فلم يُكرَّر؛ استعمل النسخة الموجودة.</span>
                            <span x-show="!r.ok" x-text="' — ' + r.message"></span>
                            <template x-if="r.ok && r.warnings && r.warnings.length"><span x-text="' تنبيهات: ' + r.warnings.join(' — ')"></span></template>
                        </li>
                    </template>
                </ul>

                @if ($ctx && $ctx['builderUrl'])
                    <p class="cin-note" x-show="results.some(r => r.ok)" x-cloak data-next-step>
                        الخطوة التالية: ارجع إلى المشهد «{{ $ctx['title'] }}»، واختر الملف من قائمة «{{ $d['roles'][$role] }}» في قسم الوسائط ثم اضغط «حفظ وسائط المشهد».
                        <a href="{{ $ctx['builderUrl'] }}" class="cin-btn cin-btn-sm cin-btn-primary">العودة إلى المشهد</a>
                    </p>
                @endif
            </section>
        @endif

        <div class="cin-row" style="align-items:flex-end">
            <label class="cin-field" style="flex:1 1 16rem">بحث باسم الملف
                <input type="search" wire:model.live.debounce.400ms="search" maxlength="80" placeholder="مثال: V02 أو arrival" data-media-search>
            </label>
            <span class="cin-help" wire:loading wire:target="search">جارٍ البحث…</span>
        </div>

        <div class="cin-card cin-table-wrap" data-media-table>
            <table class="cin-table">
                <thead><tr><th>الملف</th><th>معاينة</th><th>الخصائص</th><th>أين يُستعمل</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                    @forelse ($d['assets'] as $asset)
                        @php
                            $uses = $d['usage'][$asset->id] ?? [];
                            $src = $d['canPreviewFiles'] ? $this->libraryUrl($asset) : null;
                        @endphp
                        <tr data-media-row="{{ $asset->public_id }}">
                            <td>
                                <div class="cin-asset-name">{{ $asset->original_name }}</div>
                                <div class="cin-key" title="المعرّف الداخلي">{{ $asset->public_id }}</div>
                                <div class="cin-help">بصمة المحتوى: <span class="cin-key" style="unicode-bidi:isolate">{{ substr($asset->sha256, 0, 12) }}</span> · أُضيف <span dir="ltr" style="unicode-bidi:isolate;white-space:nowrap">{{ $asset->created_at?->format('Y-m-d') }}</span></div>
                            </td>
                            <td>
                                @if ($src && $role === 'poster')
                                    <img src="{{ $src }}" alt="معاينة {{ $asset->original_name }}" loading="lazy" style="width:8rem;height:auto;max-height:6rem;object-fit:contain;background:#000;border-radius:.3rem;border:1px solid var(--cin-border-soft)">
                                @elseif ($src && $role === 'video')
                                    <details class="cin-more" data-media-preview><summary>معاينة الفيديو</summary>
                                        <video controls preload="none" playsinline controlslist="nodownload" src="{{ $src }}" style="width:min(18rem,100%);aspect-ratio:16/9;background:#000;border-radius:.4rem" aria-label="معاينة {{ $asset->original_name }}"></video>
                                    </details>
                                @elseif ($src && $role === 'audio')
                                    <details class="cin-more" data-media-preview><summary>معاينة الصوت</summary><audio controls preload="none" src="{{ $src }}" style="width:min(18rem,100%)"></audio></details>
                                @else
                                    <span class="cin-help">—</span>
                                @endif
                            </td>
                            <td class="cin-help">
                                <div style="display:flex;flex-wrap:wrap;gap:.2rem .7rem" data-media-props>
                                    <span dir="ltr" class="cin-ltr" style="unicode-bidi:isolate">{{ $asset->mime }}</span>
                                    <span>{{ $fmtSize($asset->size_bytes) }}</span>
                                    @if ($asset->duration_ms)<span>{{ $fmtDur($asset->duration_ms) }}</span>@endif
                                    @if ($asset->width)<span dir="ltr" class="cin-ltr" style="unicode-bidi:isolate">{{ $asset->width }}×{{ $asset->height }}</span>@endif
                                    @if ($asset->video_codec)<span dir="ltr" class="cin-ltr" style="unicode-bidi:isolate">{{ $asset->video_codec }}/{{ $asset->audio_codec ?: '—' }}</span>@endif
                                </div>
                                @if ($role === 'poster' && $asset->width && $asset->height)
                                    <div>@if (abs($asset->width / $asset->height - 16 / 9) < 0.02) نسبة 16:9 ✓ @else نسبة غير 16:9 (تُعرض بنسبتها الحقيقية) @endif</div>
                                @endif
                            </td>
                            <td>
                                @if (count($uses))
                                    <ul style="display:flex;flex-direction:column;gap:.25rem" data-media-usage>
                                        @foreach (array_slice($uses, 0, 4) as $u)
                                            <li class="cin-help">
                                                <strong>{{ $u['title'] }}</strong> <span class="cin-key">{{ $u['key'] }}</span>
                                                <span>({{ $statusLabels[$u['status']] ?? $u['status'] }})</span>
                                                @if ($u['editUrl']) <a href="{{ $u['editUrl'] }}" class="cin-link-btn">تحرير</a> @endif
                                                @if ($u['previewUrl']) <a href="{{ $u['previewUrl'] }}" class="cin-link-btn" target="_blank" rel="noopener">معاينة</a> @endif
                                            </li>
                                        @endforeach
                                        @if (count($uses) > 4) <li class="cin-help">+ {{ count($uses) - 4 }} مشهد آخر</li> @endif
                                    </ul>
                                    @if (count($uses) > 1 && $role === 'video')
                                        <p class="cin-note is-warn" data-media-shared>هذا الفيديو مربوط بأكثر من مشهد. تأكد أنه مقصود.</p>
                                    @endif
                                @else
                                    <span class="cin-badge is-warn">غير مربوط بأي مشهد</span>
                                @endif
                            </td>
                            <td>
                                @if ($asset->status === 'ready')
                                    <span class="cin-badge is-ok">جاهز للاستعمال</span>
                                    <div class="cin-help">اجتاز فحص الخادم</div>
                                @else
                                    <span class="cin-badge is-err">مسحوب</span>
                                    <div class="cin-help">لا يظهر للاعبين</div>
                                @endif
                            </td>
                            <td>
                                @if ($d['canUpload'])
                                    @if ($asset->status === 'ready')
                                        <button type="button" wire:click="retire('{{ $asset->public_id }}')" wire:confirm="سحب الملف؟ تظهر المشاهد المرتبطة به «غير متاحة» ولا يُحذف الملف." class="cin-link-btn is-danger">سحب</button>
                                    @else
                                        <button type="button" wire:click="restore('{{ $asset->public_id }}')" class="cin-link-btn">استعادة</button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="cin-help" style="text-align:center;padding:1.5rem">لا توجد ملفات من هذا النوع{{ trim($this->search) !== '' ? ' تطابق البحث' : '' }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
