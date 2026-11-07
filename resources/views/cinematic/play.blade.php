@extends('layouts.app')

@section('title', ($preview ? 'معاينة: ' : '').$story->title)
@section('meta_description', 'مشغّل السلسلة السينمائية: '.$story->title)
@section('content_width', 'max-w-4xl')

{{--
    CIN-1: المشغّل (مشاهد فيديو/سرد/أحجية/تفاعل/انتقال). قرارات التقدّم كلها على الخادم؛ هذا القالب يعرض فقط ما يعيده الخادم.
     - الإعداد يُمرَّر بسمة data-config مهرَّبة (إخراج Blade المهرَّب فقط، لا إدراج بيانات داخل سكربت). النصوص كلها تُعرض بـx-text (نصًّا خامًّا لا HTML).
     - الفيديو: preload=metadata (تحميل كسول)، لا تشغيل تلقائي، تحكّم نظامي، نص بديل وترجمة اختيارية.
     - التفاعل يعمل باللمس والفأرة ولوحة المفاتيح (قوائم/أزرار بديلة لكل مساحة بصرية).
     - في المعاينة الإشرافية لا يُحفظ شيء، وتُقيَّم الإجابات بالحل الحقيقي على الخادم.
    كتل JS/HTML الحاوية على أقواس Alpine محاطة بتوجيه التعطيل الحرفي في Blade حتى لا يفسّرها.
--}}
@push('head')
<script>
@verbatim
document.addEventListener('alpine:init', function () {
    window.Alpine.data('cinematicPlayer', function () {
        return {
            cfg: {}, state: null, scene: null, nextScene: null,
            phase: 'loading', busy: false, error: '', result: null, showText: false,
            videoEnded: false, videoFailed: false, reloaded: false,
            pick: null, order: [], held: null, placed: {}, points: [], light: { x: 50, y: 50 }, answer: '', revealed: '',

            get preview() { return this.cfg.mode === 'preview'; },
            get meta() {
                var self = this;
                if (!this.scene || !this.state) { return null; }
                return this.state.scenes.filter(function (s) { return s.id === self.scene.id; })[0] || null;
            },
            get percent() { return this.state && this.state.progress ? this.state.progress.percent : 0; },
            get ix() { return this.scene && this.scene.interaction ? this.scene.interaction : null; },
            get kind() { return this.ix ? this.ix.kind : ''; },
            get itemCount() { return this.ix && this.ix.items ? this.ix.items.length : 0; },
            get doneNow() { return this.result && this.result.correct; },
            get previewIds() { return this.state ? this.state.scenes.map(function (s) { return s.id; }) : []; },
            get lightStyle() {
                var r = this.ix && this.ix.light_radius ? this.ix.light_radius : 15;
                return 'background: radial-gradient(circle at ' + this.light.x + '% ' + this.light.y + '%, transparent 0, transparent ' + r + '%, rgba(2,6,23,.96) ' + (r + 9) + '%);';
            },

            init() {
                try { this.cfg = JSON.parse(this.$el.dataset.config || '{}'); } catch (e) { this.cfg = {}; }
                this.state = this.cfg.state || null;
                if (!this.state || !this.state.scenes) { this.fail('تعذّر تحميل المشغّل.'); return; }
                if (this.preview) { this.openPreview(this.state.scenes.length ? this.state.scenes[0].id : null); return; }
                this.openCurrent();
            },

            url(name, id) { return String((this.cfg.urls || {})[name] || '').replace('__SCENE__', encodeURIComponent(id || '')); },

            async call(method, url, body) {
                var meta = document.querySelector('meta[name="csrf-token"]');
                var res = await fetch(url, {
                    method: method, credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': meta ? meta.content : '' },
                    body: body ? JSON.stringify(body) : undefined
                });
                var data = {};
                try { data = await res.json(); } catch (e) { data = {}; }
                if (!res.ok) {
                    var err = new Error(data.message || 'تعذّر إتمام الطلب، حاول مجددًا.');
                    err.reason = data.reason || ''; err.status = res.status;
                    throw err;
                }
                return data;
            },

            fail(message) { this.error = message; this.phase = 'error'; },
            reset() {
                this.result = null; this.error = ''; this.showText = false; this.videoEnded = false; this.videoFailed = false;
                this.pick = null; this.order = []; this.held = null; this.placed = {}; this.points = []; this.light = { x: 50, y: 50 };
                this.answer = ''; this.revealed = ''; this.nextScene = null;
            },

            async openCurrent(sceneId) {
                this.phase = 'loading'; this.reset();
                var id = sceneId || (this.state ? this.state.current : null);
                if (!id) { this.phase = 'complete'; return; }
                try {
                    var data = await this.call('GET', this.url('state') + '?scene=' + encodeURIComponent(id));
                    this.state = data.state;
                    if (this.state.progress && this.state.progress.status === 'completed' && !sceneId) { this.phase = 'complete'; return; }
                    if (!data.scene) { this.phase = this.state.current ? 'error' : 'complete'; if (this.phase === 'error') { this.error = 'هذا المشهد غير متاح بعد.'; } return; }
                    await this.enter(data.scene);
                } catch (e) { this.fail(e.message); }
            },

            async enter(scene) {
                this.reset(); this.scene = scene; this.phase = 'scene';
                var self = this;
                this.$nextTick(function () { if (self.$refs.heading) { self.$refs.heading.focus(); } });
                if (this.preview) { return; }
                try { await this.call('POST', this.url('start', scene.id)); } catch (e) {
                    if (e.reason !== 'replay_denied') { this.error = e.message; }
                }
            },

            openPreview(id) {
                if (!id || !this.cfg.scenes || !this.cfg.scenes[id]) { this.phase = 'complete'; return; }
                this.reset(); this.scene = this.cfg.scenes[id]; this.phase = 'scene';
                var self = this;
                this.$nextTick(function () { if (self.$refs.heading) { self.$refs.heading.focus(); } });
            },
            stepPreview(delta) {
                var ids = this.previewIds; var i = ids.indexOf(this.scene ? this.scene.id : '');
                var next = ids[i + delta];
                if (next) { this.openPreview(next); } else if (delta > 0) { this.phase = 'complete'; }
            },

            async finish(mode) {
                if (this.busy || !this.scene) { return; }
                if (this.preview) { this.stepPreview(1); return; }
                this.busy = true; this.error = '';
                try {
                    var data = await this.call('POST', this.url('finish', this.scene.id), { mode: mode });
                    this.state = data.state; await this.advance(data.scene);
                } catch (e) { this.error = e.message; } finally { this.busy = false; }
            },

            async advance(scene) {
                if (this.state.progress && this.state.progress.status === 'completed') { this.phase = 'complete'; return; }
                if (scene) { await this.enter(scene); return; }
                await this.openCurrent();
            },

            async proceed() {
                if (this.preview) { this.stepPreview(1); return; }
                await this.advance(this.nextScene);
            },

            async submit(submission) {
                if (this.busy || !this.scene) { return; }
                this.busy = true; this.error = ''; this.result = null;
                try {
                    var data = await this.call('POST', this.url('submit', this.scene.id), { submission: submission });
                    this.result = data.result || null;
                    if (data.state) { this.state = data.state; }
                    this.nextScene = data.scene || null;
                    if (this.result && this.result.correct && this.result.reveal) { this.revealed = this.result.reveal; }
                } catch (e) { this.error = e.message; } finally { this.busy = false; }
            },

            async upgrade() {
                if (this.busy) { return; }
                this.busy = true; this.error = '';
                try {
                    var data = await this.call('POST', this.url('upgrade'));
                    this.state = data.state; await this.advance(data.scene);
                } catch (e) { this.error = e.message; } finally { this.busy = false; }
            },

            onVideoError() {
                this.videoFailed = true;
                if (!this.preview && !this.reloaded && this.scene) { this.reloaded = true; this.openCurrent(this.scene.id); }
            },
            transcriptLines() { return this.scene && this.scene.transcript ? this.scene.transcript : []; },
            hasText() { return this.transcriptLines().length > 0; },
            videoState() { return this.scene && this.scene.video ? this.scene.video.state : ''; },

            areaStyle(a) { return 'left:' + a.x + '%;top:' + a.y + '%;width:' + a.w + '%;height:' + a.h + '%;'; },
            itemLabel(id) {
                var list = (this.ix && (this.ix.items || this.ix.options)) || [];
                var hit = list.filter(function (i) { return i.id === id; })[0];
                return hit ? hit.label : id;
            },
            targetLabel(id) {
                var hit = ((this.ix && this.ix.targets) || []).filter(function (i) { return i.id === id; })[0];
                return hit ? hit.label : id;
            },

            addToOrder(id) { if (this.order.indexOf(id) === -1) { this.order = this.order.concat([id]); } },
            undoOrder() { this.order = this.order.slice(0, -1); },
            hold(id) { this.held = this.held === id ? null : id; },
            place(target) { if (!this.held) { return; } var p = Object.assign({}, this.placed); p[this.held] = target; this.placed = p; this.held = null; },
            unplace(id) { var p = Object.assign({}, this.placed); delete p[id]; this.placed = p; },
            dragStart(id, ev) { this.held = id; if (ev.dataTransfer) { ev.dataTransfer.setData('text/plain', id); } },
            drop(target) { this.place(target); },
            placedIds() { return Object.keys(this.placed); },

            pointerLight(ev) {
                var box = this.$refs.stage ? this.$refs.stage.getBoundingClientRect() : null;
                if (!box || !box.width || !box.height) { return; }
                this.light = { x: Math.max(0, Math.min(100, Math.round((ev.clientX - box.left) * 1000 / box.width) / 10)), y: Math.max(0, Math.min(100, Math.round((ev.clientY - box.top) * 1000 / box.height) / 10)) };
            },
            moveLight(dx, dy) { this.light = { x: Math.max(0, Math.min(100, this.light.x + dx)), y: Math.max(0, Math.min(100, this.light.y + dy)) }; },
            markPoint() { if (this.points.length < 6) { this.points = this.points.concat([{ x: this.light.x, y: this.light.y }]); } },
            clickLight(ev) { this.pointerLight(ev); this.markPoint(); }
        };
    });
});
@endverbatim
</script>
@endpush

@section('content')
    <div x-data="cinematicPlayer" data-cinematic-player data-mode="{{ $preview ? 'preview' : 'play' }}" data-config="{{ json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR) }}" class="space-y-4">
        <noscript><div class="glass rounded-2xl p-5 text-sm text-slate-200">المشغّل يحتاج إلى تفعيل JavaScript.</div></noscript>
        @verbatim
        <div class="flex flex-wrap items-center justify-between gap-2">
            <a :href="cfg.urls && cfg.urls.back ? cfg.urls.back : '#'" class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-400 hover:text-white transition motion-reduce:transition-none rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                <span x-text="preview ? 'عودة للوحة الإدارة' : 'صفحة القصة'"></span>
            </a>
            <p class="font-display font-black text-white text-sm break-words" x-text="cfg.title"></p>
        </div>

        <template x-if="preview">
            <div class="rounded-xl border border-gold/30 bg-gold/10 px-4 py-3 text-xs text-gold font-bold space-y-2" role="status" data-cinematic-preview-banner>
                <p>معاينة إشرافية: كل المشاهد مفتوحة ولا يُحفظ أي تقدّم. الحالة: <span x-text="cfg.status"></span></p>
                <template x-if="cfg.readiness && cfg.readiness.blockers && cfg.readiness.blockers.length">
                    <ul class="list-disc pr-5 font-normal text-slate-200 space-y-0.5">
                        <template x-for="b in cfg.readiness.blockers" :key="b.code + (b.scene || '')"><li x-text="b.message"></li></template>
                    </ul>
                </template>
                <label class="flex flex-wrap items-center gap-2 font-bold text-slate-200">انتقل إلى مشهد
                    <select class="rounded-lg bg-slate-900/60 border border-white/10 px-2 py-1 text-xs" x-on:change="openPreview($event.target.value)">
                        <template x-for="s in state.scenes" :key="s.id"><option :value="s.id" :selected="scene && scene.id === s.id" x-text="s.stage.title + ' / ' + s.chapter.title + ' / ' + s.title"></option></template>
                    </select>
                </label>
            </div>
        </template>

        <template x-if="!preview && state && state.upgrade_available">
            <div class="rounded-xl border border-amethyst/30 bg-amethyst/10 px-4 py-3 text-sm text-slate-100 flex flex-wrap items-center justify-between gap-3" role="status" data-cinematic-upgrade>
                <span>صدرت نسخة أحدث من السلسلة. يمكنك إكمال هذه النسخة أو الانتقال إلى الجديدة (تُحمل إتماماتك للمشاهد التي لم يتغير منطقها فقط).</span>
                <button type="button" class="btn-gem !py-2 !px-5 text-sm" x-on:click="upgrade()" :disabled="busy">الانتقال إلى النسخة الأحدث</button>
            </div>
        </template>

        <template x-if="phase === 'loading'">
            <div class="glass rounded-2xl p-10 text-center text-slate-300" role="status" aria-live="polite" data-cinematic-loading>
                <div class="mx-auto mb-3 w-8 h-8 rounded-full border-2 border-amethyst border-t-transparent animate-spin motion-reduce:animate-none" aria-hidden="true"></div>
                <p class="text-sm font-bold">جارٍ التحميل…</p>
            </div>
        </template>

        <template x-if="phase === 'error'">
            <div class="rounded-2xl border border-rose/30 bg-rose/10 p-6 text-center space-y-3" role="alert" data-cinematic-error>
                <p class="font-black text-white" x-text="error || 'حدث خطأ غير متوقع.'"></p>
                <button type="button" class="btn-gem !py-2 !px-6 text-sm" x-on:click="preview ? openPreview(scene && scene.id) : openCurrent()">أعد المحاولة</button>
            </div>
        </template>

        <template x-if="phase === 'complete'">
            <div class="puzzle-card !p-8 text-center space-y-4" data-cinematic-complete>
                <h1 class="font-display font-black text-2xl text-white" tabindex="-1" x-ref="heading" x-text="preview ? 'انتهت المعاينة' : (state && state.progress && state.progress.status === 'completed' ? 'أكملت السلسلة' : 'لا توجد مشاهد متاحة الآن')"></h1>
                <p class="text-sm text-slate-300" x-show="!preview && state && state.progress && state.progress.status === 'completed'">أحسنت! تقدّمك محفوظ. (لا تمنح هذه السلاسل جواهر ولا خبرة.)</p>
                <div class="flex flex-wrap justify-center gap-3">
                    <a :href="cfg.urls && cfg.urls.back ? cfg.urls.back : '#'" class="btn-gem !py-2.5 !px-6 text-sm">العودة</a>
                    <button type="button" class="chip !py-2 !px-4" x-show="!preview && state && state.scenes.length" x-on:click="openCurrent(state.scenes[0].id)">أعد من البداية</button>
                    <button type="button" class="chip !py-2 !px-4" x-show="preview" x-on:click="openPreview(previewIds[0])">أعد المعاينة</button>
                </div>
            </div>
        </template>

        <template x-if="phase === 'scene' && scene">
            <article class="space-y-4" :data-scene-type="scene.type" :data-scene-key="scene.key">
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-300">
                        <p><span class="font-black text-white" x-text="meta ? meta.stage.title : ''"></span><span x-show="meta"> · </span><span x-text="meta ? meta.chapter.title : ''"></span></p>
                        <p x-show="!preview && state && state.progress" x-text="state && state.progress ? (state.progress.completed_scenes + ' / ' + state.progress.total_scenes + ' (' + percent + '%)') : ''"></p>
                    </div>
                    <div class="h-2 rounded-full bg-white/5 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="percent" aria-label="تقدّمك في السلسلة" x-show="!preview">
                        <div class="h-full rounded-full bg-amethyst transition-all motion-reduce:transition-none" :style="'width:' + percent + '%'"></div>
                    </div>
                </div>

                <h1 class="font-display font-black text-xl md:text-2xl text-white break-words focus:outline-none" tabindex="-1" x-ref="heading" x-text="scene.title"></h1>
                <p class="text-sm text-slate-300 break-words" x-show="scene.description" x-text="scene.description"></p>

                <div x-show="scene.intro_lines && scene.intro_lines.length" class="glass rounded-xl p-4 space-y-1.5">
                    <template x-for="(l, i) in scene.intro_lines" :key="i"><p class="text-sm text-slate-100 break-words"><span class="font-black text-amethyst" x-show="l.speaker" x-text="l.speaker + ': '"></span><span x-text="l.text"></span></p></template>
                </div>

                <!-- فيديو -->
                <template x-if="scene.type === 'video'">
                    <div class="space-y-3">
                        <template x-if="videoState() === 'ready' && !videoFailed">
                            <div class="rounded-2xl overflow-hidden border border-white/10 bg-black">
                                <video class="w-full aspect-video bg-black" controls playsinline preload="metadata" controlslist="nodownload noremoteplayback" disablepictureinpicture
                                    :poster="scene.video.poster || null" x-on:ended="videoEnded = true" x-on:error="onVideoError()">
                                    <source :src="scene.video.src" type="video/mp4">
                                    <template x-if="scene.video.subtitle"><track kind="subtitles" srclang="ar" label="العربية" :src="scene.video.subtitle" default></template>
                                </video>
                            </div>
                        </template>
                        <template x-if="videoState() !== 'ready' || videoFailed">
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-6 text-center space-y-1" role="status" data-cinematic-video-state>
                                <p class="font-black text-white" x-text="videoState() === 'awaiting' ? 'هذا المشهد قيد إنتاج الفيديو' : 'الفيديو غير متاح حاليًا'"></p>
                                <p class="text-xs text-slate-400" x-text="hasText() ? 'يمكنك متابعة السلسلة بقراءة النص البديل.' : 'عاود المحاولة لاحقًا.'"></p>
                            </div>
                        </template>
                        <template x-if="scene.video && scene.video.audio">
                            <details class="text-xs text-slate-300"><summary class="cursor-pointer font-bold">صوت إضافي للمشهد</summary><audio class="mt-2 w-full" controls preload="none" :src="scene.video.audio"></audio></details>
                        </template>
                    </div>
                </template>

                <!-- سرد -->
                <template x-if="scene.type === 'narrative'">
                    <div class="puzzle-card !p-5 space-y-3" data-cinematic-narrative>
                        <template x-if="scene.image"><img :src="scene.image" alt="" class="w-full h-auto rounded-xl border border-white/10" loading="lazy"></template>
                        <template x-for="(l, i) in scene.lines" :key="i"><p class="text-base text-slate-100 leading-relaxed break-words"><span class="font-black text-amethyst" x-show="l.speaker" x-text="l.speaker + ': '"></span><span x-text="l.text"></span></p></template>
                    </div>
                </template>

                <!-- انتقال -->
                <template x-if="scene.type === 'transition'">
                    <div class="puzzle-card !p-10 text-center space-y-3" data-cinematic-transition>
                        <p class="font-display font-black text-3xl text-white break-words" x-text="scene.transition.title"></p>
                        <p class="text-sm text-slate-300 break-words" x-show="scene.transition.subtitle" x-text="scene.transition.subtitle"></p>
                        <p class="text-sm font-black text-amethyst break-words" x-show="scene.transition.next_stage_title" x-text="scene.transition.next_stage_title"></p>
                    </div>
                </template>

                <!-- أحجية كلاسيكية -->
                <template x-if="scene.type === 'puzzle'">
                    <form class="puzzle-card !p-5 space-y-3" x-on:submit.prevent="answer.trim() && submit({ answer: answer })" data-cinematic-puzzle>
                        <template x-if="scene.puzzle">
                            <div class="space-y-3">
                                <p class="font-black text-white break-words" x-text="scene.puzzle.title"></p>
                                <p class="text-slate-100 break-words whitespace-pre-line" x-text="scene.puzzle.prompt"></p>
                                <template x-if="scene.puzzle.image_url"><img :src="scene.puzzle.image_url" alt="" class="w-full h-auto rounded-xl" loading="lazy"></template>
                                <template x-if="scene.puzzle.choices && scene.puzzle.choices.length">
                                    <fieldset class="space-y-2"><legend class="sr-only">الخيارات</legend>
                                        <template x-for="c in scene.puzzle.choices" :key="c"><label class="flex items-center gap-2 glass rounded-xl px-4 py-2.5 cursor-pointer"><input type="radio" name="cin-choice" :value="c" x-model="answer"><span x-text="c"></span></label></template>
                                    </fieldset>
                                </template>
                                <template x-if="!scene.puzzle.choices || !scene.puzzle.choices.length">
                                    <label class="block"><span class="sr-only">إجابتك</span><input type="text" x-model="answer" maxlength="500" autocomplete="off" class="w-full rounded-xl bg-slate-900/50 border border-white/10 px-4 py-3 text-white" placeholder="اكتب إجابتك"></label>
                                </template>
                                <button type="submit" class="btn-gem !px-8 text-sm" :disabled="busy || !answer.trim() || doneNow">تحقق</button>
                            </div>
                        </template>
                        <p class="text-sm text-slate-300" x-show="!scene.puzzle">هذه الأحجية غير متاحة حاليًا.</p>
                    </form>
                </template>

                <!-- تفاعل / استكشاف -->
                <template x-if="(scene.type === 'interaction' || scene.type === 'exploration') && ix">
                    <div class="space-y-3" data-cinematic-interaction :data-kind="kind">
                        <p class="text-white font-black break-words" x-text="ix.prompt"></p>
                        <p class="text-xs text-slate-300 break-words" x-show="ix.instructions" x-text="ix.instructions"></p>
                        <p class="text-xs text-slate-400 break-words" x-show="ix.alt_text" x-text="ix.alt_text"></p>

                        <!-- CIN-1-FIX: صورة المشهد للأنواع التي لا تعرض صورتها داخل كتلتها (ترتيب / سحب وإفلات / اختيار / مقارنة). مرجع بصري فقط: لا تتدخل في الإرسال، وتُخفى إن تعذّر تحميلها فلا تظهر صورة مكسورة. hotspot/container/flashlight تعرض صورتها في كتلها كما كانت (لا تكرار). -->
                        <template x-if="scene.image && ['sequence', 'drag_drop', 'choose_item', 'compare'].indexOf(kind) !== -1">
                            <div class="relative aspect-video rounded-2xl overflow-hidden border border-white/10 bg-slate-900 select-none" data-cinematic-scene-image x-data="{ broken: false }" x-show="!broken">
                                <img :src="scene.image" alt="" draggable="false" class="absolute inset-0 w-full h-full object-cover pointer-events-none" loading="lazy" x-on:error="broken = true">
                            </div>
                        </template>

                        <!-- مساحات (نقطة ساخنة / باب أو درج) -->
                        <template x-if="kind === 'hotspot' || kind === 'container'">
                            <div class="space-y-3">
                                <div class="relative aspect-video rounded-2xl overflow-hidden border border-white/10 bg-slate-900" aria-hidden="true">
                                    <template x-if="scene.image"><img :src="scene.image" alt="" class="absolute inset-0 w-full h-full object-cover" loading="lazy"></template>
                                    <template x-for="a in ix.areas" :key="a.id"><button type="button" tabindex="-1" class="absolute rounded-lg border-2 border-transparent hover:border-amethyst/70 focus:border-amethyst transition motion-reduce:transition-none" :style="areaStyle(a)" :title="a.label" x-on:click="submit({ area: a.id })" :disabled="busy || doneNow"></button></template>
                                </div>
                                <ul class="flex flex-wrap gap-2" aria-label="المناطق (بديل لوحة المفاتيح واللمس)">
                                    <template x-for="a in ix.areas" :key="a.id"><li><button type="button" class="chip !py-2 !px-4" x-on:click="submit({ area: a.id })" :disabled="busy || doneNow" x-text="a.label"></button></li></template>
                                </ul>
                            </div>
                        </template>

                        <!-- ترتيب -->
                        <template x-if="kind === 'sequence'">
                            <div class="space-y-3">
                                <ul class="flex flex-wrap gap-2" aria-label="العناصر">
                                    <template x-for="i in ix.items" :key="i.id"><li><button type="button" class="chip !py-2 !px-4" x-on:click="addToOrder(i.id)" :disabled="order.indexOf(i.id) !== -1 || busy || doneNow"><span aria-hidden="true" x-text="i.symbol"></span> <span x-text="i.label"></span></button></li></template>
                                </ul>
                                <ol class="flex flex-wrap gap-2 min-h-[2.5rem]" aria-label="ترتيبك">
                                    <template x-for="(id, n) in order" :key="id"><li class="glass rounded-full px-3 py-1 text-sm font-black text-white" x-text="(n + 1) + '. ' + itemLabel(id)"></li></template>
                                </ol>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="chip !py-2 !px-4" x-on:click="undoOrder()" :disabled="!order.length || busy || doneNow">تراجع</button>
                                    <button type="button" class="chip !py-2 !px-4" x-on:click="order = []" :disabled="!order.length || busy || doneNow">مسح</button>
                                    <button type="button" class="btn-gem !py-2 !px-6 text-sm" x-on:click="submit({ order: order })" :disabled="order.length !== itemCount || busy || doneNow">تحقق</button>
                                </div>
                            </div>
                        </template>

                        <!-- سحب وإفلات (نقر لاختيار عنصر ثم هدف، أو سحب بالفأرة) -->
                        <template x-if="kind === 'drag_drop'">
                            <div class="space-y-3">
                                <p class="text-xs text-slate-400">اختر عنصرًا ثم اختر مكانه (أو اسحبه بالفأرة).</p>
                                <ul class="flex flex-wrap gap-2" aria-label="العناصر">
                                    <template x-for="i in ix.items" :key="i.id"><li><button type="button" class="chip !py-2 !px-4" draggable="true" :aria-pressed="held === i.id ? 'true' : 'false'" :class="held === i.id ? 'ring-2 ring-amethyst' : ''" x-on:dragstart="dragStart(i.id, $event)" x-on:click="hold(i.id)" :disabled="busy || doneNow"><span aria-hidden="true" x-text="i.symbol"></span> <span x-text="i.label"></span></button></li></template>
                                </ul>
                                <ul class="grid gap-2 grid-cols-2 sm:grid-cols-3" aria-label="الأماكن">
                                    <template x-for="t in ix.targets" :key="t.id"><li><button type="button" class="glass rounded-xl w-full px-4 py-4 text-sm font-black text-white" x-on:click="place(t.id)" x-on:dragover.prevent x-on:drop.prevent="drop(t.id)" :disabled="busy || doneNow"><span x-text="t.label"></span></button></li></template>
                                </ul>
                                <ul class="space-y-1 text-sm text-slate-200" aria-label="اختياراتك">
                                    <template x-for="id in placedIds()" :key="id"><li class="flex items-center gap-2"><span x-text="itemLabel(id) + ' ← ' + targetLabel(placed[id])"></span><button type="button" class="text-xs text-rose underline" x-on:click="unplace(id)" :disabled="busy || doneNow">إزالة</button></li></template>
                                </ul>
                                <button type="button" class="btn-gem !py-2 !px-6 text-sm" x-on:click="submit({ placements: placed })" :disabled="placedIds().length !== itemCount || busy || doneNow">تحقق</button>
                            </div>
                        </template>

                        <!-- اختيار عنصر / مقارنة -->
                        <template x-if="kind === 'choose_item' || kind === 'compare'">
                            <div class="space-y-3">
                                <ul class="grid gap-2 grid-cols-2 sm:grid-cols-3" aria-label="الخيارات">
                                    <template x-for="i in (ix.items || ix.options)" :key="i.id"><li><button type="button" class="glass rounded-xl w-full px-4 py-4 text-center font-black text-white" :class="pick === i.id ? 'ring-2 ring-amethyst' : ''" :aria-pressed="pick === i.id ? 'true' : 'false'" x-on:click="pick = i.id" :disabled="busy || doneNow"><span class="block text-2xl" aria-hidden="true" x-text="i.symbol"></span><span x-text="i.label"></span></button></li></template>
                                </ul>
                                <button type="button" class="btn-gem !py-2 !px-6 text-sm" x-on:click="submit(kind === 'compare' ? { option: pick } : { item: pick })" :disabled="!pick || busy || doneNow">تأكيد الاختيار</button>
                            </div>
                        </template>

                        <!-- كشف رمز بشرط -->
                        <template x-if="kind === 'reveal_code'">
                            <div class="space-y-3">
                                <button type="button" class="btn-gem !py-2.5 !px-8 text-sm" x-on:click="submit({ confirm: true })" :disabled="busy || doneNow" x-text="ix.reveal_label || 'اكشف الرمز'"></button>
                                <p x-show="revealed" class="inline-block rounded-xl border border-gold/40 bg-gold/10 px-5 py-3 font-display font-black text-2xl text-gold tracking-widest" dir="ltr" data-cinematic-code x-text="revealed"></p>
                            </div>
                        </template>

                        <!-- مصباح -->
                        <template x-if="kind === 'flashlight'">
                            <div class="space-y-3">
                                <div x-ref="stage" class="relative aspect-video rounded-2xl overflow-hidden border border-white/10 bg-slate-900 touch-none select-none cursor-crosshair focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" tabindex="0" role="application" aria-label="منطقة الاستكشاف: حرّك الضوء بالأسهم واضغط Enter لفحص الموضع"
                                    x-on:pointermove="pointerLight($event)" x-on:click="clickLight($event)"
                                    x-on:keydown.arrow-left.prevent="moveLight(-4, 0)" x-on:keydown.arrow-right.prevent="moveLight(4, 0)" x-on:keydown.arrow-up.prevent="moveLight(0, -4)" x-on:keydown.arrow-down.prevent="moveLight(0, 4)" x-on:keydown.enter.prevent="markPoint()" x-on:keydown.space.prevent="markPoint()">
                                    <template x-if="scene.image"><img :src="scene.image" alt="" class="absolute inset-0 w-full h-full object-cover" loading="lazy"></template>
                                    <div class="absolute inset-0 pointer-events-none" :style="lightStyle"></div>
                                    <template x-for="(p, n) in points" :key="n"><span class="absolute w-5 h-5 -mt-2.5 -ms-2.5 rounded-full border-2 border-gold pointer-events-none" :style="'left:' + p.x + '%;top:' + p.y + '%;'"></span></template>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs text-slate-400" x-text="'المواضع المفحوصة: ' + points.length + ' / 6'"></span>
                                    <button type="button" class="chip !py-2 !px-4" x-on:click="points = []" :disabled="!points.length || busy || doneNow">مسح المواضع</button>
                                    <button type="button" class="btn-gem !py-2 !px-6 text-sm" x-on:click="submit({ points: points })" :disabled="!points.length || busy || doneNow">تحقق</button>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
                <p class="text-sm text-slate-300" x-show="(scene.type === 'interaction' || scene.type === 'exploration') && !ix">هذا المشهد غير مكتمل الإعداد.</p>

                <!-- نص بديل للفيديو -->
                <template x-if="scene.type === 'video' && hasText()">
                    <div class="space-y-2">
                        <button type="button" class="chip !py-2 !px-4" :aria-expanded="showText ? 'true' : 'false'" x-on:click="showText = !showText" x-text="showText ? 'إخفاء النص البديل' : 'عرض النص البديل (الحوار)'"></button>
                        <div x-show="showText" class="glass rounded-xl p-4 space-y-1.5" data-cinematic-transcript>
                            <template x-for="(l, i) in transcriptLines()" :key="i"><p class="text-sm text-slate-100 break-words"><span class="font-black text-amethyst" x-show="l.speaker" x-text="l.speaker + ': '"></span><span x-text="l.text"></span></p></template>
                        </div>
                    </div>
                </template>

                <!-- نتيجة وتنبيهات -->
                <div aria-live="polite" class="space-y-2">
                    <template x-if="error"><p class="rounded-xl border border-rose/30 bg-rose/10 px-4 py-3 text-sm font-bold text-rose" role="alert" x-text="error"></p></template>
                    <template x-if="result">
                        <p class="rounded-xl border px-4 py-3 text-sm font-bold" :class="result.correct ? 'border-emerald/30 bg-emerald/10 text-emerald' : (result.locked ? 'border-gold/30 bg-gold/10 text-gold' : 'border-white/10 bg-white/5 text-slate-200')" data-cinematic-result>
                            <span x-text="result.message || (result.correct ? 'إجابة صحيحة!' : 'حاول مرة أخرى.')"></span>
                            <span class="block text-xs font-normal mt-1" x-show="result.attempts_left !== null && result.attempts_left !== undefined && !result.correct" x-text="'المحاولات المتبقية: ' + result.attempts_left"></span>
                        </p>
                    </template>
                </div>

                <!-- التحكم -->
                <div class="flex flex-wrap items-center gap-3">
                    <template x-if="scene.type === 'video'">
                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" class="btn-gem !px-8 text-sm" x-on:click="finish('watched')" :disabled="busy || (!videoEnded && !preview) || videoState() !== 'ready'" data-cinematic-continue>أكملت المشاهدة، تابع</button>
                            <button type="button" class="chip !py-2 !px-4" x-show="hasText()" x-on:click="finish('read')" :disabled="busy" data-cinematic-read>تابع بقراءة النص البديل</button>
                            <button type="button" class="chip !py-2 !px-4" x-show="scene.skippable" x-on:click="finish('skipped')" :disabled="busy" data-cinematic-skip>تخطّي المشهد</button>
                        </div>
                    </template>
                    <template x-if="scene.type === 'narrative' || scene.type === 'transition'">
                        <button type="button" class="btn-gem !px-8 text-sm" x-on:click="finish('read')" :disabled="busy" data-cinematic-continue>متابعة</button>
                    </template>
                    <template x-if="doneNow">
                        <button type="button" class="btn-gem !px-8 text-sm" x-on:click="proceed()" :disabled="busy" data-cinematic-continue>متابعة</button>
                    </template>
                    <template x-if="preview">
                        <div class="flex gap-2">
                            <button type="button" class="chip !py-2 !px-4" x-on:click="stepPreview(-1)">السابق</button>
                            <button type="button" class="chip !py-2 !px-4" x-on:click="stepPreview(1)">التالي</button>
                        </div>
                    </template>
                    <button type="button" class="chip !py-2 !px-4" x-show="!preview && meta && meta.status === 'completed' && meta.allow_replay" x-on:click="openCurrent(scene.id)">إعادة المشهد</button>
                </div>
            </article>
        </template>
        @endverbatim
    </div>
@endsection
