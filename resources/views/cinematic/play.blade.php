@extends('layouts.app')
@php($playerLike = $playerLike ?? false)

@section('title', ($preview ? 'معاينة: ' : '').$story->title)
@section('meta_description', 'مشغّل السلسلة السينمائية: '.$story->title)
@section('content_width', 'max-w-4xl')
{{-- CIN-1 IMMERSIVE: يفعّل في التخطيط وضع «شاشة اللعب» (بلا رأس ولا تذييل ولا شريط سفلي، ومناطق آمنة viewport-fit=cover). --}}
@section('immersive', '1')

{{--
    CIN-1: المشغّل (مشاهد فيديو/سرد/أحجية/تفاعل/انتقال). قرارات التقدّم كلها على الخادم؛ هذا القالب يعرض فقط ما يعيده الخادم.
     - الإعداد يُمرَّر بسمة data-config مهرَّبة (إخراج Blade المهرَّب فقط، لا إدراج بيانات داخل سكربت). النصوص كلها تُعرض بـx-text (نصًّا خامًّا لا HTML).
     - الفيديو: preload=metadata (تحميل كسول)، لا تشغيل تلقائي قبل أول لمسة، تحكّم نظامي، نص بديل وترجمة اختيارية.
     - التفاعل يعمل باللمس والفأرة ولوحة المفاتيح (قوائم/أزرار بديلة لكل مساحة بصرية).
     - في المعاينة الإشرافية لا يُحفظ شيء، وتُقيَّم الإجابات بالحل الحقيقي على الخادم. المعاينة ليست دليلًا على نجاح اللعب الفعلي.
    كتل JS/HTML الحاوية على أقواس Alpine محاطة بتوجيه التعطيل الحرفي في Blade حتى لا يفسّرها. (تنبيه: داخل ذلك التوجيه لا تُكتب تعليقات Blade لأنها تُطبع حرفيًا؛ تُستعمل تعليقات HTML.)

    CIN-1 UX (مناطق النقر على الصور):
     - إطار الصورة يأخذ نسبة أبعاد الصورة الحقيقية (naturalWidth/naturalHeight) بدل 16:9 ثابت مع object-cover؛ فالنسب المئوية (x,y,w,h) تُقاس من الصورة نفسها
       ولا تنزاح عند اختلاف نسبة الصورة أو تغيّر عرض الشاشة. الإطار اتجاهه LTR صراحة فلا يتأثر بـRTL الصفحة (الإحداثيات فيزيائية).
     - التفاعل الأساسي: النقر/اللمس على العنصر داخل الصورة (مع هامش لمس 44px عند صغر المنطقة)، بلا حدود دائمة؛ يظهر توهّج خفيف عند مرور المؤشر.
       أزرار القائمة (تظهر عند التركيز بلوحة المفاتيح) تبقى بديلًا لإمكانية الوصول (لوحة المفاتيح/قارئ الشاشة).
     - ما تحدده الواجهة (المنطقة المضغوطة) يُرسل للخادم الذي يقرر الصحة؛ لا حل ولا إحداثيات صحيحة في القالب ولا في الإعداد.

    CIN-1 IMMERSIVE (تجربة اللعب الغامرة): طبقة عرض فوق ما سبق، بلا تغيير في منطق السلسلة/التقدّم/المكافآت.
     - التخطيط: خلفية محيطة ← مسرح (الوسيط) ← ورقة (النص/الخيارات) ← شريط علوي ← ستارة انتقال؛ كلها داخل شاشة ثابتة بلا تمرير للصفحة.
       الأنماط في partials/immersive-styles، والسلوك في partials/immersive-scripts (يُدمج في المكوّن)، والتغذية الراجعة في partials/immersive-feedback.
     - عقود الاختبارات القديمة (CinSceneImageFixTest/CinUxTest/CinHttpTest) محفوظة: كتلة صورة المشهد الواحدة، صور hotspot/flashlight/السرد، شريط المعاينة وسماته، وغياب مخارج HTML غير المهرَّبة.
     - ثلاثة أوضاع: لعب حقيقي (لا يُرسَم فيه أي DOM إداري)، معاينة إشرافية (درج أدوات قابل للطي)، معاينة كلاعب (?mode=player: مثل اللعب الحقيقي بلا أدوات، ودون حفظ تقدّم).
--}}
@push('head')
<style>
@verbatim
[data-cinematic-player] select { background-color: #0f172a; color: #f8fafc; border: 1px solid #64748b; border-radius: .6rem; padding: .45rem .7rem; font-size: .85rem; color-scheme: dark; max-width: 100%; min-height: 2.5rem; }
[data-cinematic-player] select option, [data-cinematic-player] select optgroup { background-color: #0f172a; color: #f8fafc; }
[data-cinematic-player] select option:checked { background-color: #5b21b6; color: #ffffff; }
[data-cinematic-player] .cin-fade { animation: cin-fade .25s ease-out both; }
@keyframes cin-fade { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }

/* إطار الصورة: نسبة الصورة الحقيقية (--r)، لا يتجاوز 76% من ارتفاع الشاشة، ويتوسّط. اتجاه LTR لأن الإحداثيات فيزيائية. */
[data-cinematic-player] .cin-stage { position: relative; width: 100%; max-width: min(100%, calc(76vh * var(--r, 1.7778))); margin-inline: auto; overflow: hidden; border-radius: 1rem; border: 1px solid rgba(255,255,255,.12); background: #020617; user-select: none; -webkit-user-select: none; touch-action: manipulation; }
[data-cinematic-player] .cin-stage-img { position: absolute; inset: 0; width: 100%; height: 100%; display: block; object-fit: fill; pointer-events: none; -webkit-user-drag: none; }
[data-cinematic-player] .cin-stage.is-pointer { cursor: pointer; }
/* توهّج خفيف عند المرور/القرب بدل حدود دائمة؛ لا يلتقط النقر (النقر يُحسب على الإطار كله). */
[data-cinematic-player] .cin-glow { position: absolute; pointer-events: none; border-radius: 9999px; opacity: 0; transform: scale(.85); transition: opacity .15s, transform .15s; background: radial-gradient(closest-side, rgba(167,139,250,.55), rgba(139,92,246,.22) 60%, transparent 100%); box-shadow: 0 0 0 2px rgba(196,181,253,.0); }
[data-cinematic-player] .cin-glow.on { opacity: 1; transform: scale(1.15); box-shadow: 0 0 0 2px rgba(196,181,253,.8); }
[data-cinematic-player] .cin-outline { position: absolute; pointer-events: none; border: 2px dashed rgba(251,191,36,.8); border-radius: .4rem; }
[data-cinematic-player] .cin-ping { position: absolute; width: 2.5rem; height: 2.5rem; margin: -1.25rem 0 0 -1.25rem; border-radius: 9999px; border: 3px solid #fde68a; pointer-events: none; animation: cin-ping .6s ease-out both; }
@keyframes cin-ping { from { opacity: 1; transform: scale(.4); } to { opacity: 0; transform: scale(1.4); } }
[data-cinematic-player] .cin-pin { position: absolute; width: 1.25rem; height: 1.25rem; margin: -.625rem 0 0 -.625rem; border-radius: 9999px; border: 2px solid #fcd34d; pointer-events: none; }

/* الفيديو 16:9 */
[data-cinematic-player] .cin-video { position: relative; width: 100%; aspect-ratio: 16 / 9; background: #000; border-radius: 1rem; overflow: hidden; border: 1px solid rgba(255,255,255,.12); }
[data-cinematic-player] .cin-video video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; background: #000; }
[data-cinematic-player] .cin-overlay { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .6rem; text-align: center; padding: 1rem; background: rgba(2,6,23,.55); }
[data-cinematic-player] .cin-bigplay { width: 4.5rem; height: 4.5rem; border-radius: 9999px; border: 3px solid #fff; background: rgba(124,58,237,.9); color: #fff; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
[data-cinematic-player] .cin-bigplay svg { width: 2rem; height: 2rem; margin-inline-start: .25rem; }
[data-cinematic-player] .cin-bigplay:focus-visible, [data-cinematic-player] .cin-tbtn:focus-visible { outline: 3px solid #fde68a; outline-offset: 3px; }

/* شريط المعاينة الإشرافية */
[data-cinematic-player] .cin-pv { border: 1px solid rgba(252,211,77,.35); background: rgba(252,211,77,.08); border-radius: .9rem; padding: .8rem .9rem; display: flex; flex-direction: column; gap: .6rem; }
[data-cinematic-player] .cin-pv-row { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
[data-cinematic-player] .cin-tag { display: inline-flex; align-items: center; padding: .1rem .55rem; border-radius: 9999px; font-size: .75rem; font-weight: 800; background: #1e293b; color: #f1f5f9; border: 1px solid #475569; }
[data-cinematic-player] .cin-tag.ok { background: #052e24; color: #6ee7b7; border-color: transparent; }
[data-cinematic-player] .cin-tag.warn { background: #3a2a05; color: #fde68a; border-color: transparent; }
[data-cinematic-player] .cin-tag.err { background: #3b1219; color: #fda4af; border-color: transparent; }
[data-cinematic-player] .cin-tbtn { display: inline-flex; align-items: center; min-height: 2.5rem; padding: .35rem .9rem; border-radius: .6rem; font-size: .85rem; font-weight: 800; background: #1e293b; color: #f8fafc; border: 1px solid #64748b; cursor: pointer; text-decoration: none; }
[data-cinematic-player] .cin-tbtn:hover { border-color: #c4b5fd; }
[data-cinematic-player] .cin-tbtn[disabled] { opacity: .5; cursor: not-allowed; }
@media (max-width: 640px) { [data-cinematic-player] .cin-pv select { width: 100%; } [data-cinematic-player] .cin-bigplay { width: 3.75rem; height: 3.75rem; } }
@media (prefers-reduced-motion: reduce) { [data-cinematic-player] .cin-fade, [data-cinematic-player] .cin-ping { animation: none; } [data-cinematic-player] .cin-glow { transition: none; } }
@endverbatim
</style>
@include('cinematic.partials.immersive-styles')
@include('cinematic.partials.immersive-scripts')
@include('cinematic.partials.cin2-styles')
@include('cinematic.partials.cin2-scripts')
<script>
@verbatim
document.addEventListener('alpine:init', function () {
    window.Alpine.data('cinematicPlayer', function () {
        var base = {
            cfg: {}, state: null, scene: null, nextScene: null,
            phase: 'loading', busy: false, error: '', result: null, showText: false,
            videoEnded: false, videoFailed: false, reloaded: false, videoLoading: false, videoStarted: false,
            pick: null, order: [], held: null, placed: {}, points: [], light: { x: 50, y: 50 }, answer: '', revealed: '',
            stageRatio: 16 / 9, hoverId: null, ping: null, nudge: '',

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
            get previewIndex() { return this.scene ? this.previewIds.indexOf(this.scene.id) : -1; },
            get stageStyle() { return '--r:' + this.stageRatio + ';aspect-ratio:' + this.stageRatio + ';'; },
            /* بعد النجاح يُكشف المشهد كاملًا (يزول الظلام)؛ قبله الظلام يغطي كل شيء عدا دائرة الضوء. */
            get lightStyle() {
                if (this.doneNow) { return 'background: transparent;'; }
                var r = this.ix && this.ix.light_radius ? this.ix.light_radius : 15;
                return 'background: radial-gradient(circle at ' + this.light.x + '% ' + this.light.y + '%, transparent 0, transparent ' + r + '%, rgba(2,6,23,.96) ' + (r + 9) + '%);';
            },
            /* مشاهد المعاينة مجمّعة بالفصل لقائمة الانتقال، مع علامة جاهزية الوسائط. */
            get previewGroups() {
                var self = this; var groups = []; var index = {};
                if (!this.state) { return groups; }
                this.state.scenes.forEach(function (s, i) {
                    var k = s.stage.title + ' / ' + s.chapter.title;
                    if (index[k] === undefined) { index[k] = groups.length; groups.push({ title: k, items: [] }); }
                    groups[index[k]].items.push({ id: s.id, label: (i + 1) + '. ' + s.title + self.flagFor(s.id) });
                });
                return groups;
            },
            get sceneNotes() {
                var r = this.cfg.readiness; var k = this.scene ? this.scene.key : null;
                if (!r || !k) { return []; }
                return (r.blockers || []).concat(r.warnings || []).filter(function (n) { return n.scene === k; });
            },

            init() {
                try { this.cfg = JSON.parse(this.$el.dataset.config || '{}'); } catch (e) { this.cfg = {}; }
                this.state = this.cfg.state || null;
                this.evidence = Array.isArray(this.cfg.evidence) ? this.cfg.evidence : [];
                this.initImmersive();
                if (!this.state || !this.state.scenes) { this.fail('تعذّر تحميل المشغّل.'); return; }
                if (this.preview) {
                    var start = this.cfg.start_scene && this.cfg.scenes && this.cfg.scenes[this.cfg.start_scene] ? this.cfg.start_scene : (this.state.scenes.length ? this.state.scenes[0].id : null);
                    this.openPreview(start);
                    return;
                }
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
                /* CIN-2: سجل الأدلة يأتي من الخادم مع كل استجابة تحمل الحالة (مشاهد مكتملة فقط). */
                if (res.ok && data && Array.isArray(data.evidence)) { this.evidence = data.evidence; }
                if (!res.ok) {
                    var err = new Error(data.message || 'تعذّر إتمام الطلب، حاول مجددًا.');
                    err.reason = data.reason || ''; err.status = res.status;
                    throw err;
                }
                return data;
            },

            fail(message) { this.error = message; this.phase = 'error'; },
            reset() {
                this.result = null; this.error = ''; this.showText = false; this.videoEnded = false; this.videoFailed = false; this.videoLoading = false; this.videoStarted = false;
                this.pick = null; this.order = []; this.held = null; this.placed = {}; this.points = []; this.light = { x: 50, y: 50 };
                this.answer = ''; this.revealed = ''; this.nextScene = null;
                this.stageRatio = 16 / 9; this.hoverId = null; this.ping = null; this.nudge = '';
                if (typeof this.resetStage2 === 'function') { this.resetStage2(); }
                this.resetImmersive();
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
                this.$nextTick(function () { if (self.$refs.heading) { self.$refs.heading.focus({ preventScroll: true }); } });
                this.afterEnter();
                if (this.preview) { return; }
                try { await this.call('POST', this.url('start', scene.id)); } catch (e) {
                    if (e.reason !== 'replay_denied') { this.error = e.message; }
                }
            },

            openPreview(id) {
                if (!id || !this.cfg.scenes || !this.cfg.scenes[id]) { this.phase = 'complete'; return; }
                this.reset(); this.scene = this.cfg.scenes[id]; this.phase = 'scene';
                var self = this;
                this.$nextTick(function () { if (self.$refs.heading) { self.$refs.heading.focus({ preventScroll: true }); } });
                this.afterEnter();
            },
            stepPreview(delta) {
                var ids = this.previewIds; var i = ids.indexOf(this.scene ? this.scene.id : '');
                var next = ids[i + delta];
                if (next) { this.openPreview(next); } else if (delta > 0) { this.phase = 'complete'; }
            },
            replayScene() { if (this.preview && this.scene) { this.openPreview(this.scene.id); } else if (this.scene) { this.openCurrent(this.scene.id); } },
            flagFor(id) {
                var s = this.cfg.scenes ? this.cfg.scenes[id] : null; if (!s) { return ''; }
                if (s.type === 'video') { return s.video && s.video.state === 'ready' ? ' ✓' : ' (بلا فيديو)'; }
                if (s.type === 'interaction' || s.type === 'exploration') { return s.image ? ' ✓' : ' (بلا صورة)'; }
                return '';
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
                this.busy = true; this.error = ''; this.result = null; this.nudge = '';
                var body = { submission: submission };
                /* المعاينة لا تحفظ محاولات: رقم المحاولة المحلي يكفي لاختيار التلميح المناسب (اللعب الحقيقي يحسبه الخادم). */
                if (this.preview) { this.tries = (this.tries || 0) + 1; body.attempt = Math.min(50, this.tries); }
                try {
                    var data = await this.call('POST', this.url('submit', this.scene.id), body);
                    this.result = data.result || null;
                    if (typeof this.applyLockDetail === 'function') { this.applyLockDetail(this.result); }
                    if (typeof this.applyBoardResult === 'function') { this.applyBoardResult(this.result); }
                    if (data.state) { this.state = data.state; }
                    this.nextScene = data.scene || null;
                    if (this.result && this.result.correct && this.result.reveal) { this.revealed = this.result.reveal; }
                    this.afterResult(submission);
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

            /* ───── الفيديو ───── */
            onVideoError() {
                this.videoFailed = true; this.videoLoading = false;
                if (!this.preview && !this.reloaded && this.scene) { this.reloaded = true; this.openCurrent(this.scene.id); }
            },
            /* مشهدا فيديو متتاليان يبقيان على عنصر video نفسه فيتغيّر <source> ولا يُعاد التحميل (المتصفح يتجاهل تغيير source بعد التحميل الأول)، فيُعرض مقطع المشهد السابق. نستدعي load() عند تغيّر المصدر فقط. */
            syncVideoSrc(el) {
                var src = this.scene && this.scene.video ? this.scene.video.src : null;
                if (!el || !src) { return; }
                var changed = el._cinSrc !== undefined && el._cinSrc !== src;
                el._cinSrc = src;
                if (changed) { this.$nextTick(function () { try { el.load(); } catch (e) { /* لا شيء: زر إعادة المحاولة متاح */ } }); }
            },
            playVideo() { var v = this.$refs.video; if (v) { var p = v.play(); if (p && p.catch) { p.catch(function () { /* المتصفح يطلب نقرة المستخدم: أدوات التحكم الأصلية متاحة */ }); } } },
            replayVideo() { var v = this.$refs.video; if (v) { v.currentTime = 0; this.videoEnded = false; this.playVideo(); } },
            retryVideo() { this.videoFailed = false; this.reloaded = false; this.openCurrentOrPreview(); },
            openCurrentOrPreview() { if (this.preview) { this.openPreview(this.scene.id); } else { this.openCurrent(this.scene.id); } },
            transcriptLines() { return this.scene && this.scene.transcript ? this.scene.transcript : []; },
            hasText() { return this.transcriptLines().length > 0; },
            videoState() { return this.scene && this.scene.video ? this.scene.video.state : ''; },
            canReplayVideo() { return this.preview || (this.scene && this.scene.allow_replay); },

            /* ───── الصور ومناطق النقر ───── */
            areaStyle(a) { return 'left:' + a.x + '%;top:' + a.y + '%;width:' + a.w + '%;height:' + a.h + '%;'; },
            /* إطار الصورة يتبع نسبة الصورة الحقيقية فتبقى النسب المئوية مقيسة من الصورة نفسها. */
            imgLoaded(ev) {
                var img = ev.target;
                if (img && img.naturalWidth && img.naturalHeight) { this.stageRatio = Math.max(0.4, Math.min(4, img.naturalWidth / img.naturalHeight)); }
            },
            /* المنطقة الأقرب لموضع المؤشر، مع هامش لمس: الحد الأدنى للهدف 44px (يتجاوز صغر المقبض على الجوال) دون خلط مناطق متجاورة (الأقرب إلى المركز يفوز). */
            areaAt(ev) {
                var stage = this.$refs.stage; if (!stage || !this.ix || !this.ix.areas) { return null; }
                var box = stage.getBoundingClientRect(); if (!box.width || !box.height) { return null; }
                var px = ev.clientX - box.left; var py = ev.clientY - box.top; var best = null; var bestD = Infinity;
                this.ix.areas.forEach(function (a) {
                    var x = a.x / 100 * box.width, y = a.y / 100 * box.height, w = a.w / 100 * box.width, h = a.h / 100 * box.height;
                    var padX = Math.max(0, (44 - w) / 2), padY = Math.max(0, (44 - h) / 2);
                    if (px >= x - padX && px <= x + w + padX && py >= y - padY && py <= y + h + padY) {
                        var d = Math.hypot(px - (x + w / 2), py - (y + h / 2));
                        if (d < bestD) { best = a; bestD = d; }
                    }
                });
                return best;
            },
            glowStyle(a) {
                /* توهّج دائري يغطي المنطقة (وهامش اللمس) بلا حدود دائمة. الأبعاد بنسب مئوية من الإطار: ratio = العرض/الارتفاع،
                   فنحوّل ارتفاع المنطقة إلى وحدات العرض (÷ratio) ثم نحسب الارتفاع المئوي لدائرة بالقطر نفسه (×ratio). */
                var r = this.stageRatio || 1.7778;
                var cx = a.x + a.w / 2, cy = a.y + a.h / 2; var size = Math.max(a.w, a.h / r, 4);
                var w = size * 1.5; var h = w * r;
                return 'left:' + (cx - w / 2) + '%;top:' + (cy - h / 2) + '%;width:' + w + '%;height:' + h + '%;';
            },
            stageMove(ev) {
                if (ev.pointerType === 'touch') { return; }
                var a = this.areaAt(ev); this.hoverId = a ? a.id : null;
            },
            stageLeave() { this.hoverId = null; },
            stageClick(ev) {
                if (this.busy || this.doneNow) { return; }
                this.armHint();
                var stage = this.$refs.stage; var box = stage ? stage.getBoundingClientRect() : null;
                if (box) { this.ping = { x: (ev.clientX - box.left) / box.width * 100, y: (ev.clientY - box.top) / box.height * 100, k: Date.now() }; var k = this.ping.k; var self = this; setTimeout(function () { if (self.ping && self.ping.k === k) { self.ping = null; } }, 650); }
                var a = this.areaAt(ev);
                if (a) { this.nudge = ''; this.submit({ area: a.id }); return; }
                /* لا شيء هنا: ملاحظة محلية فقط (بلا إرسال ولا استهلاك محاولة ولا كشف أي معلومة). */
                this.nudge = 'لا شيء هنا. جرّب الضغط على العنصر المقصود داخل الصورة.';
            },
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
                /* مرجع خاص بالمصباح (lightStage): لو شاركه مرجع مسرح النقر (stage) لحذفه Alpine عند الانتقال المباشر من مشهد نقر إلى مشهد المصباح فيثبت الضوء على المنتصف. */
                var stage = this.$refs.lightStage; var box = stage ? stage.getBoundingClientRect() : null;
                if (!box || !box.width || !box.height) { return; }
                this.light = { x: Math.max(0, Math.min(100, Math.round((ev.clientX - box.left) * 1000 / box.width) / 10)), y: Math.max(0, Math.min(100, Math.round((ev.clientY - box.top) * 1000 / box.height) / 10)) };
            },
            moveLight(dx, dy) { this.light = { x: Math.max(0, Math.min(100, this.light.x + dx)), y: Math.max(0, Math.min(100, this.light.y + dy)) }; },
            markPoint() { if (this.points.length < 6 && !this.doneNow) { this.points = this.points.concat([{ x: this.light.x, y: this.light.y }]); } },
            clickLight(ev) { this.pointerLight(ev); this.markPoint(); }
        };
        /* سلوك الوضع الغامر (partials/immersive-scripts) يُدمج مع الاحتفاظ بالـgetters. */
        var extra = typeof window.cinImmersiveMixin === 'function' ? window.cinImmersiveMixin() : {};
        Object.defineProperties(base, Object.getOwnPropertyDescriptors(extra));
        /* CIN-2: أنواع التفاعل الجديدة وسجل الأدلة. */
        var extra2 = typeof window.cinStage2Mixin === 'function' ? window.cinStage2Mixin() : {};
        return Object.defineProperties(base, Object.getOwnPropertyDescriptors(extra2));
    });
});
@endverbatim
</script>
@endpush

@section('content')
    <div x-data="cinematicPlayer" data-cinematic-player data-mode="{{ $preview ? 'preview' : 'play' }}" data-view="{{ $preview ? ($playerLike ? 'player' : 'admin') : 'player' }}" data-config="{{ json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR) }}" class="imm-root" :class="rootClass"
        x-on:pointermove.passive="bumpHud()" x-on:pointerdown.passive="unlock(); bumpHud()" x-on:keydown="onKey($event)" x-on:focusin="bumpHud()"
        x-on:fullscreenchange.document="syncFs()" x-on:webkitfullscreenchange.document="syncFs()" x-on:resize.window.debounce.150ms="onResize()">
        <noscript><div class="imm-root-noscript">المشغّل يحتاج إلى تفعيل JavaScript.</div></noscript>
        @verbatim
        <!-- الطبقة 1: خلفية محيطة تملأ الشاشة (نسخة ضبابية من صورة المشهد/ملصق الفيديو) -->
        <div class="imm-backdrop" :class="{ 'has-bg': bgUrl }" :style="backdropStyle" aria-hidden="true"></div>

        <!-- الطبقة 4: الشريط العلوي (عودة / عنوان المشهد / أدوات المشهد / ملء الشاشة). يختفي أثناء تشغيل الفيديو ويعود بأي حركة أو تركيز. -->
        <header class="imm-hud" data-imm-hud>
            <a :href="backHref" class="imm-iconbtn" data-imm-back :aria-label="backLabel">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                <span class="imm-hide-sm" x-text="backLabel"></span>
            </a>
            <div class="imm-title">
                <template x-if="phase === 'scene' && scene"><h1 class="imm-h1" tabindex="-1" x-ref="heading" x-text="scene.title"></h1></template>
                <template x-if="!(phase === 'scene' && scene)"><p class="imm-h1" x-text="cfg.title"></p></template>
                <p class="imm-kicker" x-text="kicker"></p>
            </div>
            <div class="imm-hud-end">
                <template x-if="phase === 'scene' && scene && scene.type === 'video' && (hasText() || scene.description)">
                    <button type="button" class="imm-iconbtn" :class="panelOpen ? 'is-on' : ''" x-on:click="panelOpen = !panelOpen" :aria-expanded="panelOpen ? 'true' : 'false'" aria-label="عرض النص البديل (الحوار)" data-imm-transcript-toggle>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                        <span class="imm-hide-sm">النص</span>
                    </button>
                </template>
                <template x-if="phase === 'scene' && scene && scene.type === 'video' && scene.skippable">
                    <button type="button" class="imm-iconbtn" x-on:click="continueScene('skipped')" :disabled="busy" aria-label="تخطّي المشهد" data-cinematic-skip>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8.689c0-.864.933-1.406 1.683-.977l7.108 4.061a1.125 1.125 0 010 1.954l-7.108 4.061A1.125 1.125 0 013 16.811V8.69zM12.75 8.689c0-.864.933-1.406 1.683-.977l7.108 4.061a1.125 1.125 0 010 1.954l-7.108 4.061a1.125 1.125 0 01-1.683-.977V8.69z"/></svg>
                        <span class="imm-hide-sm">تخطّي</span>
                    </button>
                </template>
                <template x-if="phase === 'scene' && scene && layoutKind === 'stage'">
                    <button type="button" class="imm-iconbtn" x-on:click="toggleHelp()" aria-label="إظهار التعليمات أو إخفاؤها" data-imm-help-toggle>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"/></svg>
                    </button>
                </template>
                @endverbatim
                @if ($preview && ! $playerLike)
                    @verbatim
                    <!-- أدوات الإدارة في الشريط: تنقّل سريع + فتح درج المعاينة. تُرسَم في الخادم للمعاينة الإدارية فقط. -->
                    <button type="button" class="imm-iconbtn" x-on:click="stepPreview(-1)" :disabled="previewIndex <= 0" aria-label="المشهد السابق" data-imm-prev><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg></button>
                    <button type="button" class="imm-iconbtn" x-on:click="stepPreview(1)" aria-label="المشهد التالي" data-imm-next><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg></button>
                    <button type="button" class="imm-iconbtn imm-pill-admin" :class="adminOpen ? 'is-on' : ''" x-on:click="setAdmin(!adminOpen)" :aria-expanded="adminOpen ? 'true' : 'false'" aria-controls="imm-admin-drawer" data-imm-admin-toggle>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75"/></svg>
                        <span class="imm-hide-sm">أدوات المعاينة</span>
                    </button>
                    @endverbatim
                @endif
                @if ($preview && $playerLike)
                    @verbatim
                    <!-- معاينة كما يراها اللاعب: لا أدوات في الواجهة؛ فقط رابط صغير للعودة إلى معاينة الإدارة بالأدوات. -->
                    <a class="imm-iconbtn imm-pill-admin" :href="url('admin_like', scene ? scene.id : '')" data-imm-exit-player-like aria-label="العودة إلى المعاينة الإدارية بالأدوات"><span>معاينة كلاعب</span><span class="imm-hide-sm">· أدوات</span></a>
                    @endverbatim
                @endif
                @verbatim
                <template x-if="evidenceList.length">
                    <button type="button" class="imm-iconbtn" x-ref="evToggle" x-on:click="openEvidence()" aria-label="سجل الأدلة" data-cin2-evidence-toggle>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 110-15 7.5 7.5 0 010 15z"/></svg>
                        <span class="imm-hide-sm">الأدلة</span><span class="cin2-count" x-text="evidenceList.length"></span>
                    </button>
                </template>
                <button type="button" class="imm-iconbtn" x-show="fsSupported" x-cloak x-on:click="toggleFs()" :aria-pressed="fs ? 'true' : 'false'" :aria-label="fs ? 'إنهاء ملء الشاشة' : 'ملء الشاشة'" data-imm-fullscreen>
                    <svg x-show="!fs" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9V5.25A1.5 1.5 0 015.25 3.75H9M15 3.75h3.75a1.5 1.5 0 011.5 1.5V9M20.25 15v3.75a1.5 1.5 0 01-1.5 1.5H15M9 20.25H5.25a1.5 1.5 0 01-1.5-1.5V15"/></svg>
                    <svg x-show="fs" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3.75V7.5A1.5 1.5 0 017.5 9H3.75M20.25 9H16.5A1.5 1.5 0 0115 7.5V3.75M15 20.25V16.5a1.5 1.5 0 011.5-1.5h3.75M3.75 15H7.5A1.5 1.5 0 019 16.5v3.75"/></svg>
                </button>
            </div>
            <div class="imm-progress" x-show="!preview && state && state.progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="percent" aria-label="تقدّمك في السلسلة"><span :style="'width:' + percent + '%'"></span></div>
        </header>
        @endverbatim

        @if ($preview && ! $playerLike)
        @verbatim
        <!-- الطبقة 6 (إدارة فقط): درج أدوات المعاينة الإشرافية. لا يُرسَم للاعب الحقيقي ولا في «معاينة كلاعب». لا يُحفظ تقدّم ولا تُمنح مكافأة، والنجاح هنا ليس دليلًا على نجاح اللعب الفعلي. -->
        <section id="imm-admin-drawer" class="imm-admin" x-show="adminOpen" x-cloak role="region" aria-label="أدوات المعاينة الإشرافية" data-cinematic-preview-banner data-imm-admin>
            <div class="cin-pv-row" style="justify-content: space-between">
                <h2>أدوات المعاينة الإشرافية</h2>
                <button type="button" class="cin-tbtn" x-on:click="setAdmin(false)" aria-label="إغلاق درج الأدوات" data-imm-admin-close>إغلاق</button>
            </div>
            <div class="cin-pv-row">
                <span class="cin-tag warn">معاينة إشرافية</span>
                <span class="cin-tag" x-text="'حالة النسخة: ' + ({ draft: 'مسودة', in_review: 'قيد المراجعة', published: 'منشورة', superseded: 'مستبدلة', unpublished: 'مسحوبة' }[cfg.status] || cfg.status)"></span>
                <span class="cin-tag" :class="cfg.readiness && cfg.readiness.ok ? 'ok' : 'warn'" x-text="cfg.readiness && cfg.readiness.ok ? 'جاهزة للنشر' : 'غير جاهزة للنشر'"></span>
            </div>
            <p>كل المشاهد مفتوحة ولا يُحفظ أي تقدّم ولا تُمنح مكافآت. نجاح تفاعل هنا لا يُعدّ دليلًا على نجاحه في اللعب الفعلي.</p>

            <template x-if="scene">
                <div class="cin-pv-row" data-cinematic-preview-context>
                    <span class="cin-tag" x-text="meta ? meta.stage.title : ''"></span>
                    <span class="cin-tag" x-text="meta ? meta.chapter.title : ''"></span>
                    <span class="cin-tag" x-text="'النوع: ' + ({ video: 'فيديو', narrative: 'سرد', puzzle: 'أحجية', interaction: 'تفاعل', exploration: 'استكشاف', transition: 'انتقال' }[scene.type] || scene.type)"></span>
                    <template x-if="scene.type === 'video'"><span class="cin-tag" :class="videoState() === 'ready' ? 'ok' : 'err'" x-text="videoState() === 'ready' ? 'الفيديو جاهز' : (videoState() === 'awaiting' ? 'الفيديو بانتظار الإنتاج' : 'الفيديو غير متاح')"></span></template>
                    <template x-if="scene.type === 'interaction' || scene.type === 'exploration'"><span class="cin-tag" :class="scene.image ? 'ok' : 'warn'" x-text="scene.image ? 'صورة المشهد مربوطة' : 'بلا صورة مشهد'"></span></template>
                    <span class="cin-tag" x-text="'المشهد ' + (previewIndex + 1) + ' من ' + previewIds.length"></span>
                </div>
            </template>

            <label>انتقل إلى مشهد
                <select x-on:change="openPreview($event.target.value)" data-preview-picker aria-label="انتقل إلى مشهد">
                    <template x-for="g in previewGroups" :key="g.title">
                        <optgroup :label="g.title"><template x-for="o in g.items" :key="o.id"><option :value="o.id" :selected="scene && scene.id === o.id" x-text="o.label"></option></template></optgroup>
                    </template>
                </select>
            </label>
            <div class="cin-pv-row">
                <button type="button" class="cin-tbtn" x-on:click="stepPreview(-1)" :disabled="previewIndex <= 0" data-preview-prev>السابق</button>
                <button type="button" class="cin-tbtn" x-on:click="stepPreview(1)" data-preview-next>التالي</button>
                <button type="button" class="cin-tbtn" x-on:click="replayScene()" data-preview-replay>إعادة المشهد</button>
                <template x-if="scene && cfg.urls && cfg.urls.builder"><a class="cin-tbtn" :href="url('builder', scene.id)" data-preview-edit>العودة إلى محرّر المشهد</a></template>
            </div>

            <div class="cin-pv-row" data-imm-mode-switch>
                <a class="cin-tbtn" :href="url('admin_like', scene ? scene.id : '')" aria-current="page" data-imm-mode-admin>الوضع الحالي: معاينة إشرافية</a>
                <a class="cin-tbtn" :href="url('player_like', scene ? scene.id : '')" data-imm-mode-player>معاينة كما يراها اللاعب</a>
                <button type="button" class="cin-tbtn" :class="showZones ? 'is-on' : ''" x-on:click="setZones(!showZones)" :aria-pressed="showZones ? 'true' : 'false'" data-imm-zones-toggle>إظهار مناطق النقر</button>
            </div>

            <template x-if="sceneNotes.length">
                <details open data-preview-notes>
                    <summary x-text="'ملاحظات هذا المشهد (' + sceneNotes.length + ')'"></summary>
                    <ul><template x-for="(n, ni) in sceneNotes" :key="ni"><li x-text="n.message"></li></template></ul>
                </details>
            </template>
            <template x-if="cfg.readiness && cfg.readiness.blockers && cfg.readiness.blockers.length">
                <details><summary x-text="'ما يمنع النشر في هذه النسخة (' + cfg.readiness.blockers.length + ')'"></summary>
                    <ul><template x-for="(b, bi) in cfg.readiness.blockers" :key="bi"><li x-text="b.message"></li></template></ul>
                </details>
            </template>
            <details x-on:toggle="if ($event.target.open) { refreshDiag(); }" data-imm-diagnostics>
                <summary>تشخيص المشهد والشاشة</summary>
                <dl><template x-for="(row, ri) in diag" :key="ri"><div style="display: contents"><dt x-text="row[0]"></dt><dd x-text="row[1]"></dd></div></template></dl>
                <button type="button" class="cin-tbtn" style="margin-top: .4rem" x-on:click="refreshDiag()">تحديث القياسات</button>
            </details>
        </section>
        @endverbatim
        @endif

        @verbatim
        <template x-if="!preview && state && state.upgrade_available">
            <div class="imm-upgrade" role="status" data-cinematic-upgrade>
                <span>صدرت نسخة أحدث من السلسلة. يمكنك إكمال هذه النسخة أو الانتقال إلى الجديدة (تُحمل إتماماتك للمشاهد التي لم يتغير منطقها فقط).</span>
                <button type="button" class="imm-btn imm-btn-primary imm-btn-sm" x-on:click="upgrade()" :disabled="busy">الانتقال إلى النسخة الأحدث</button>
            </div>
        </template>

        <template x-if="phase === 'loading'">
            <div class="imm-center" role="status" aria-live="polite" data-cinematic-loading>
                <div class="imm-card">
                    <div class="imm-spinner animate-spin motion-reduce:animate-none" aria-hidden="true"></div>
                    <p class="imm-loadtxt">جارٍ التحميل…</p>
                </div>
            </div>
        </template>

        <template x-if="phase === 'error'">
            <div class="imm-center" role="alert" data-cinematic-error>
                <div class="imm-card is-error">
                    <p class="imm-card-title" x-text="error || 'حدث خطأ غير متوقع.'"></p>
                    <button type="button" class="imm-btn imm-btn-primary" x-on:click="preview ? openPreview(scene && scene.id) : openCurrent()">أعد المحاولة</button>
                </div>
            </div>
        </template>

        <template x-if="phase === 'complete'">
            <div class="imm-center" data-cinematic-complete>
                <div class="imm-card">
                    <h1 tabindex="-1" x-ref="heading" x-text="preview ? 'انتهت المعاينة' : (state && state.progress && state.progress.status === 'completed' ? 'أكملت السلسلة' : 'لا توجد مشاهد متاحة الآن')"></h1>
                    <p x-show="!preview && state && state.progress && state.progress.status === 'completed'">أحسنت! تقدّمك محفوظ. (لا تمنح هذه السلاسل جواهر ولا خبرة.)</p>
                    <div class="imm-card-row">
                        <a :href="backHref" class="imm-btn imm-btn-primary">العودة</a>
                        <button type="button" class="imm-btn" x-show="!preview && state && state.scenes.length" x-on:click="openCurrent(state.scenes[0].id)">أعد من البداية</button>
                        <button type="button" class="imm-btn" x-show="preview" x-on:click="openPreview(previewIds[0])">أعد المعاينة</button>
                    </div>
                </div>
            </div>
        </template>

        <template x-if="phase === 'scene' && scene">
            <article class="imm-scene" :data-scene-type="scene.type" :data-scene-key="scene.key" :data-imm-layout="layoutKind" :data-imm-art="hasArt ? 'yes' : 'no'" :data-kind="kind">

                <!-- الطبقة 2: المسرح (الوسيط + طبقة التفاعل). يتسع الوسيط فيه بنسبة أبعاده الحقيقية دون قصّ. -->
                <div class="imm-stage" data-imm-stage :data-cinematic-interaction="isInteractionScene && ix ? kind : null">

                    <!-- فيديو: ملصق، زر تشغيل واضح، حالة تحميل، نهاية مع إعادة/متابعة، ورسالة فشل مع إعادة محاولة -->
                    <template x-if="scene.type === 'video' && videoState() === 'ready' && !videoFailed">
                        <div class="cin-video" data-cinematic-video-frame>
                            <video x-ref="video" x-effect="syncVideoSrc($el)" controls playsinline preload="metadata" controlslist="nodownload noremoteplayback nofullscreen" disablepictureinpicture
                                :poster="scene.video.poster || null" :aria-label="'فيديو: ' + scene.title"
                                x-on:loadedmetadata="fitVideo($event)" x-on:ended="videoEnded = true; videoStarted = true; onVideoStop()" x-on:error="onVideoError()" x-on:play="onVideoPlay()" x-on:pause="onVideoStop()"
                                x-on:waiting="videoLoading = true" x-on:loadstart="videoLoading = true" x-on:canplay="videoLoading = false" x-on:playing="videoLoading = false; videoStarted = true">
                                <source :src="scene.video.src" type="video/mp4">
                                <template x-if="scene.video.subtitle"><track kind="subtitles" srclang="ar" label="العربية" :src="scene.video.subtitle" default></template>
                            </video>
                            <div class="cin-overlay" x-show="!videoStarted && !videoLoading" data-cinematic-playoverlay>
                                <button type="button" class="cin-bigplay" x-on:click="playVideo()" aria-label="تشغيل الفيديو"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></button>
                                <span class="imm-bigplay-label" aria-hidden="true">اضغط للتشغيل</span>
                            </div>
                            <div class="cin-overlay" x-show="videoLoading" role="status" aria-live="polite" style="pointer-events:none" data-cinematic-videoloading>
                                <div class="imm-spinner animate-spin motion-reduce:animate-none" aria-hidden="true"></div>
                                <p class="imm-loadtxt">جارٍ تحميل الفيديو…</p>
                            </div>
                            <div class="cin-overlay is-ended" x-show="videoEnded" data-cinematic-replay>
                                <button type="button" class="imm-btn" x-show="canReplayVideo()" x-on:click="replayVideo()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>إعادة المشاهدة</button>
                                <button type="button" class="imm-btn imm-btn-primary" x-on:click="continueScene('watched')" :disabled="busy" data-cinematic-continue>أكملت المشاهدة، تابع</button>
                            </div>
                        </div>
                    </template>
                    <template x-if="scene.type === 'video' && (videoState() !== 'ready' || videoFailed)">
                        <div class="imm-card" role="status" data-cinematic-video-state>
                            <p class="imm-card-title" x-text="videoFailed ? 'تعذّر تشغيل الفيديو' : (videoState() === 'awaiting' ? 'هذا المشهد قيد إنتاج الفيديو' : 'الفيديو غير متاح حاليًا')"></p>
                            <p x-text="hasText() ? 'يمكنك متابعة السلسلة بقراءة النص البديل.' : 'عاود المحاولة لاحقًا.'"></p>
                            <div class="imm-card-row">
                                <button type="button" class="imm-btn" x-show="videoFailed" x-on:click="retryVideo()">إعادة المحاولة</button>
                                <button type="button" class="imm-btn imm-btn-primary" x-show="hasText()" x-on:click="continueScene('read')" :disabled="busy">تابع بقراءة النص البديل</button>
                            </div>
                        </div>
                    </template>

                    <!-- فنّ المشاهد السردية والأحجية الكلاسيكية: صورة كاملة بلا قصّ داخل المسرح -->
                    <template x-if="scene.type === 'narrative' && scene.image"><div class="imm-art" data-imm-art-layer><img :src="scene.image" alt="" draggable="false" loading="lazy"></div></template>
                    <template x-if="scene.type === 'puzzle' && scene.puzzle && scene.puzzle.image_url"><div class="imm-art" data-imm-art-layer><img :src="scene.puzzle.image_url" alt="" draggable="false" loading="lazy"></div></template>

                    <!-- CIN-1-FIX: صورة المشهد للأنواع التي لا تعرض صورتها داخل كتلتها (ترتيب / سحب وإفلات / اختيار / مقارنة). مرجع بصري فقط: لا تتدخل في الإرسال، وتُخفى إن تعذّر تحميلها فلا تظهر صورة مكسورة. hotspot/container/flashlight تعرض صورتها في كتلها (لا تكرار). في الوضع الغامر تملأ المسرح بلا قصّ (أنماط imm-art تتغلّب على aspect-video/object-cover المثبَّتة بعقد الاختبارات). -->
                    <template x-if="scene.image && ['sequence', 'drag_drop', 'choose_item', 'compare'].indexOf(kind) !== -1">
                        <div class="imm-art relative aspect-video rounded-2xl overflow-hidden border border-white/10 bg-slate-900 select-none" data-cinematic-scene-image x-data="{ broken: false }" x-show="!broken">
                            <img :src="scene.image" alt="" draggable="false" class="absolute inset-0 w-full h-full object-cover pointer-events-none" loading="lazy" x-on:error="broken = true">
                        </div>
                    </template>

                    <!-- مصباح: يكشف الظلام موضعًا موضعًا؛ بعد النجاح فقط يُكشف المشهد كاملًا (لا حل قبل تحقق الشرط على الخادم). أزراره في الورقة. -->
                    <template x-if="kind === 'flashlight'">
                        <div x-ref="lightStage" class="cin-stage touch-none cursor-crosshair focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" dir="ltr" :style="stageStyle" tabindex="0" role="application" aria-label="منطقة الاستكشاف: حرّك الضوء بالأسهم واضغط Enter لفحص الموضع" data-cinematic-flashlight-stage
                            x-on:pointermove="pointerLight($event)" x-on:click="clickLight($event)"
                            x-on:keydown.arrow-left.prevent="moveLight(-4, 0)" x-on:keydown.arrow-right.prevent="moveLight(4, 0)" x-on:keydown.arrow-up.prevent="moveLight(0, -4)" x-on:keydown.arrow-down.prevent="moveLight(0, 4)" x-on:keydown.enter.prevent="markPoint()" x-on:keydown.space.prevent="markPoint()">
                            <template x-if="scene.image"><img :src="scene.image" :alt="ix.alt_text || ''" draggable="false" class="cin-stage-img" x-on:load="imgLoaded($event)" x-init="if ($el.complete && $el.naturalWidth) { imgLoaded({ target: $el }); }"></template>
                            <div class="absolute inset-0 pointer-events-none transition-opacity duration-500 motion-reduce:transition-none" :style="lightStyle"></div>
                            <template x-for="(p, n) in points" :key="n"><span class="cin-pin" :style="'left:' + p.x + '%;top:' + p.y + '%;'"></span></template>
                        </div>
                    </template>

                    <!-- مساحات (نقطة ساخنة / باب أو درج): النقر على العنصر داخل الصورة هو التفاعل الأساسي، بلا حدود دائمة؛ توهّج عند المرور ونبض تلميح بعد الخمول. القائمة (بديل لوحة المفاتيح وقارئ الشاشة) في الورقة. -->
                    <template x-if="kind === 'hotspot' || kind === 'container'">
                        <div class="cin-stage" :class="[hoverId ? 'is-pointer' : '', doneNow ? 'is-done' : '']" dir="ltr" x-ref="stage" :style="stageStyle" role="group" :aria-label="ix.alt_text || 'صورة المشهد: انقر على العنصر المطلوب'" data-cinematic-hotspot-stage
                            x-on:pointermove="stageMove($event)" x-on:pointerleave="stageLeave()" x-on:click="stageClick($event)">
                            <template x-if="scene.image"><img :src="scene.image" :alt="ix.alt_text || ''" draggable="false" class="cin-stage-img" x-on:load="imgLoaded($event)" x-init="if ($el.complete && $el.naturalWidth) { imgLoaded({ target: $el }); }"></template>
                            <template x-for="a in ix.areas" :key="a.id"><span class="cin-glow" :class="hoverId === a.id ? 'on' : ''" :style="glowStyle(a)" aria-hidden="true"></span></template>
                            <template x-for="a in ix.areas" :key="'p' + a.id"><span class="imm-pulse" :class="pulse && !doneNow ? 'on' : ''" :style="glowStyle(a)" aria-hidden="true"></span></template>
                            <template x-if="!scene.image"><div class="absolute inset-0"><template x-for="a in ix.areas" :key="'o' + a.id"><span class="cin-outline" :style="areaStyle(a)" aria-hidden="true"></span></template></div></template>
                            <template x-for="a in zoneAreas" :key="'z' + a.id"><span class="imm-zone" :style="areaStyle(a)" aria-hidden="true" data-imm-zone><span x-text="a.label"></span></span></template>
                            <template x-if="flash"><span class="imm-flash" aria-hidden="true"></span></template>
                            <template x-if="ping"><span class="cin-ping" :style="'left:' + ping.x + '%;top:' + ping.y + '%;'" aria-hidden="true"></span></template>
                        </div>
                    </template>

                    <!-- CIN-2: فحص صورة بتكبير/سحب/قلب -->
                    @endverbatim
                    @include('cinematic.partials.cin2-stage')
                    @verbatim

                    <!-- انتقال: بطاقة عنوان سينمائية -->
                    <template x-if="scene.type === 'transition'">
                        <div class="imm-card imm-title-card" data-cinematic-transition>
                            <p class="imm-big" x-text="scene.transition.title"></p>
                            <hr class="imm-rule">
                            <p x-show="scene.transition.subtitle" x-text="scene.transition.subtitle"></p>
                            <p class="imm-gold" x-show="scene.transition.next_stage_title" x-text="scene.transition.next_stage_title"></p>
                            <button type="button" class="imm-btn imm-btn-primary" x-on:click="continueScene('read')" :disabled="busy" data-cinematic-continue>متابعة</button>
                        </div>
                    </template>
                </div>

                <!-- الطبقة 3: الورقة (النص والخيارات). عمود جانبي على الشاشات العريضة، ورقة سفلية على الجوال العمودي؛ في مشاهد النقر المباشر تعليق شفاف فوق المشهد. -->
                <template x-if="layoutKind === 'paper' || layoutKind === 'stage'">
                    <aside class="imm-paper" role="region" aria-label="نص المشهد وخياراته" data-imm-paper>
                        <div class="imm-paper-body" :class="quiet ? 'is-quiet' : ''">
                            <template x-if="scene.intro_lines && scene.intro_lines.length">
                                <div class="imm-lines">
                                    <template x-for="(l, i) in scene.intro_lines" :key="i"><p class="imm-dialog"><b x-show="l.speaker" x-text="l.speaker + ': '"></b><span x-text="l.text"></span></p></template>
                                </div>
                            </template>

                            <template x-if="scene.type === 'narrative'">
                                <div class="imm-lines" data-cinematic-narrative>
                                    <template x-for="(l, i) in scene.lines" :key="i"><p class="imm-line"><b x-show="l.speaker" x-text="l.speaker + ': '"></b><span x-text="l.text"></span></p></template>
                                </div>
                            </template>

                            <template x-if="isInteractionScene && ix">
                                <div class="imm-lines">
                                    <p class="imm-prompt" x-text="ix.prompt"></p>
                                    <button type="button" class="imm-helpbtn" x-show="ix.instructions" x-on:click="toggleHelp()" :aria-expanded="helpOpen ? 'true' : 'false'" data-imm-help-btn>
                                        <span x-text="helpOpen ? 'إخفاء التعليمات' : 'تعليمات'"></span>
                                    </button>
                                    <p class="imm-help" x-show="showInstructions" x-text="ix.instructions"></p>
                                    <p class="sr-only" x-show="ix.alt_text" x-text="ix.alt_text"></p>
                                </div>
                            </template>
                            <p class="imm-sub" x-show="scene.description" x-text="scene.description"></p>
                            <p class="imm-sub" x-show="isInteractionScene && !ix">هذا المشهد غير مكتمل الإعداد.</p>

                            <!-- أحجية كلاسيكية -->
                            <template x-if="scene.type === 'puzzle'">
                                <form class="imm-lines" x-on:submit.prevent="answer.trim() && submit({ answer: answer })" data-cinematic-puzzle>
                                    <template x-if="scene.puzzle">
                                        <div class="imm-lines">
                                            <p class="imm-prompt" x-text="scene.puzzle.title"></p>
                                            <p class="imm-line" style="white-space: pre-line" x-text="scene.puzzle.prompt"></p>
                                            <template x-if="scene.puzzle.choices && scene.puzzle.choices.length">
                                                <fieldset class="imm-lines" style="border: 0; padding: 0; margin: 0"><legend class="sr-only">الخيارات</legend>
                                                    <template x-for="c in scene.puzzle.choices" :key="c"><label class="imm-radio"><input type="radio" name="cin-choice" :value="c" x-model="answer"><span x-text="c"></span></label></template>
                                                </fieldset>
                                            </template>
                                            <template x-if="!scene.puzzle.choices || !scene.puzzle.choices.length">
                                                <label class="block"><span class="sr-only">إجابتك</span><input type="text" x-model="answer" maxlength="500" autocomplete="off" class="imm-input" placeholder="اكتب إجابتك"></label>
                                            </template>
                                            <div><button type="submit" class="imm-btn imm-btn-primary" :disabled="busy || !answer.trim() || doneNow">تحقق</button></div>
                                        </div>
                                    </template>
                                    <p class="imm-sub" x-show="!scene.puzzle">هذه الأحجية غير متاحة حاليًا.</p>
                                </form>
                            </template>

                            <!-- بديل لوحة المفاتيح وقارئ الشاشة لمناطق الصورة: يظهر عند التركيز فقط (النقر على الصورة هو الأساس) -->
                            <template x-if="isAreaKind">
                                <ul class="imm-kbd" aria-label="المناطق (بديل لوحة المفاتيح وقارئ الشاشة)">
                                    <template x-for="a in ix.areas" :key="a.id"><li><button type="button" class="imm-chip" x-on:click="submit({ area: a.id })" :disabled="busy || doneNow" x-text="a.label"></button></li></template>
                                </ul>
                            </template>

                            <!-- ترتيب -->
                            <template x-if="kind === 'sequence'">
                                <div class="imm-lines">
                                    <ul class="imm-chips" aria-label="العناصر">
                                        <template x-for="i in ix.items" :key="i.id"><li><button type="button" class="imm-chip" x-on:click="addToOrder(i.id)" :disabled="order.indexOf(i.id) !== -1 || busy || doneNow"><span aria-hidden="true" x-text="i.symbol"></span> <span x-text="i.label"></span></button></li></template>
                                    </ul>
                                    <ol class="imm-order" aria-label="ترتيبك">
                                        <template x-for="(id, n) in order" :key="id"><li x-text="(n + 1) + '. ' + itemLabel(id)"></li></template>
                                    </ol>
                                    <div class="imm-ls">
                                        <button type="button" class="imm-btn imm-btn-sm" x-on:click="undoOrder()" :disabled="!order.length || busy || doneNow">تراجع</button>
                                        <button type="button" class="imm-btn imm-btn-sm" x-on:click="order = []" :disabled="!order.length || busy || doneNow">مسح</button>
                                        <button type="button" class="imm-btn imm-btn-primary imm-btn-sm" x-on:click="submit({ order: order })" :disabled="order.length !== itemCount || busy || doneNow">تحقق</button>
                                    </div>
                                </div>
                            </template>

                            <!-- سحب وإفلات (نقر لاختيار عنصر ثم هدف، أو سحب بالفأرة) -->
                            <template x-if="kind === 'drag_drop'">
                                <div class="imm-lines">
                                    <p class="imm-note">اختر عنصرًا ثم اختر مكانه (أو اسحبه بالفأرة).</p>
                                    <ul class="imm-chips" aria-label="العناصر">
                                        <template x-for="i in ix.items" :key="i.id"><li><button type="button" class="imm-chip" draggable="true" :aria-pressed="held === i.id ? 'true' : 'false'" :class="held === i.id ? 'is-on' : ''" x-on:dragstart="dragStart(i.id, $event)" x-on:click="hold(i.id)" :disabled="busy || doneNow"><span aria-hidden="true" x-text="i.symbol"></span> <span x-text="i.label"></span></button></li></template>
                                    </ul>
                                    <ul class="imm-choices" aria-label="الأماكن">
                                        <template x-for="t in ix.targets" :key="t.id"><li><button type="button" class="imm-choice" x-on:click="place(t.id)" x-on:dragover.prevent x-on:drop.prevent="drop(t.id)" :disabled="busy || doneNow"><span x-text="t.label"></span></button></li></template>
                                    </ul>
                                    <ul class="imm-placed" aria-label="اختياراتك">
                                        <template x-for="id in placedIds()" :key="id"><li><span x-text="itemLabel(id) + ' ← ' + targetLabel(placed[id])"></span><button type="button" class="imm-linkbtn" x-on:click="unplace(id)" :disabled="busy || doneNow">إزالة</button></li></template>
                                    </ul>
                                    <div class="imm-ls"><button type="button" class="imm-btn imm-btn-primary imm-btn-sm" x-on:click="submit({ placements: placed })" :disabled="placedIds().length !== itemCount || busy || doneNow">تحقق</button></div>
                                </div>
                            </template>

                            <!-- اختيار عنصر / مقارنة -->
                            <template x-if="kind === 'choose_item' || kind === 'compare'">
                                <div class="imm-lines">
                                    <ul class="imm-choices" aria-label="الخيارات">
                                        <template x-for="i in (ix.items || ix.options)" :key="i.id"><li><button type="button" class="imm-choice" :class="pick === i.id ? 'is-on' : ''" :aria-pressed="pick === i.id ? 'true' : 'false'" x-on:click="pick = i.id" :disabled="busy || doneNow"><span class="imm-sym" aria-hidden="true" x-text="i.symbol"></span><span x-text="i.label"></span></button></li></template>
                                    </ul>
                                    <div class="imm-ls"><button type="button" class="imm-btn imm-btn-primary imm-btn-sm" x-on:click="submit(kind === 'compare' ? { option: pick } : { item: pick })" :disabled="!pick || busy || doneNow">تأكيد الاختيار</button></div>
                                </div>
                            </template>

                            <!-- كشف رمز بشرط -->
                            <template x-if="kind === 'reveal_code'">
                                <div class="imm-lines">
                                    <div class="imm-ls"><button type="button" class="imm-btn imm-btn-primary" x-on:click="submit({ confirm: true })" :disabled="busy || doneNow" x-text="ix.reveal_label || 'اكشف الرمز'"></button></div>
                                    <p x-show="revealed" class="imm-code" dir="ltr" data-cinematic-code x-text="revealed"></p>
                                </div>
                            </template>

                            <!-- CIN-2: إجابة / فحص / خزانة متعددة الأقفال -->
                            @endverbatim
                            @include('cinematic.partials.cin2-paper')
                            @verbatim

                            <!-- أزرار المصباح: عدد المواضع المفحوصة، مسح، تحقق -->
                            <template x-if="kind === 'flashlight'">
                                <div class="imm-ls">
                                    <span class="imm-note" x-text="'المواضع المفحوصة: ' + points.length + ' / 6'"></span>
                                    <button type="button" class="imm-btn imm-btn-sm" x-on:click="points = []" :disabled="!points.length || busy || doneNow">مسح المواضع</button>
                                    <button type="button" class="imm-btn imm-btn-primary imm-btn-sm" x-on:click="submit({ points: points })" :disabled="!points.length || busy || doneNow">تحقق</button>
                                </div>
                            </template>
                        </div>

                        <div class="imm-paper-foot" x-on:mouseenter="autoPaused = true" x-on:mouseleave="autoPaused = false" x-on:focusin="autoPaused = true" x-on:focusout="autoPaused = false">
                            @endverbatim
                            @include('cinematic.partials.immersive-feedback')
                            @verbatim
                            <div class="imm-paper-actions">
                                <template x-if="scene.type === 'narrative'">
                                    <button type="button" class="imm-btn imm-btn-primary" x-on:click="continueScene('read')" :disabled="busy" data-cinematic-continue>متابعة</button>
                                </template>
                                <template x-if="doneNow">
                                    <button type="button" class="imm-btn imm-btn-primary" x-on:click="goNext()" :disabled="busy" data-cinematic-continue>متابعة</button>
                                </template>
                                <button type="button" class="imm-btn imm-btn-sm" x-show="!preview && meta && meta.status === 'completed' && meta.allow_replay" x-on:click="openCurrent(scene.id)">إعادة المشهد</button>
                            </div>
                        </div>
                    </aside>
                </template>

                <!-- نص الفيديو البديل: ورقة سفلية تُفتح من زر «النص» في الشريط العلوي -->
                <template x-if="scene.type === 'video' && panelOpen">
                    <div class="imm-sheet" role="region" aria-label="نص المشهد البديل" data-imm-transcript-sheet>
                        <div class="imm-sheet-head"><b>النص البديل (الحوار)</b><button type="button" class="imm-btn imm-btn-sm" x-on:click="panelOpen = false">إغلاق</button></div>
                        <p class="imm-sub" x-show="scene.description" x-text="scene.description"></p>
                        <div class="imm-lines" x-show="hasText()" data-cinematic-transcript>
                            <template x-for="(l, i) in transcriptLines()" :key="i"><p class="imm-dialog"><b x-show="l.speaker" x-text="l.speaker + ': '"></b><span x-text="l.text"></span></p></template>
                        </div>
                        <div class="imm-card-row" style="margin-top: .7rem"><button type="button" class="imm-btn imm-btn-primary imm-btn-sm" x-show="hasText()" x-on:click="continueScene('read')" :disabled="busy" data-cinematic-read>تابع بقراءة النص البديل</button></div>
                    </div>
                </template>

                <!-- طبقة عائمة علوية للتغذية الراجعة: مشاهد الفيديو/الانتقال (لا ورقة لها)، ومشاهد «الضغط على الصورة» في الشاشات العمودية/الضيقة (لئلا تكبر الورقة السفلية فتتحرك الصورة تحت إصبع اللاعب) -->
                <div class="imm-float" :data-imm-for="layoutKind" x-show="layoutKind === 'media' || layoutKind === 'title' || layoutKind === 'stage'">
                    @endverbatim
                    @include('cinematic.partials.immersive-feedback')
                    @verbatim
                </div>

                <p class="imm-hint-rotate" aria-hidden="true">أدر الجهاز أفقيًا لتجربة مشاهدة أوسع</p>
            </article>
        </template>

        <!-- CIN-2: سجل الأدلة -->
        @endverbatim
        @include('cinematic.partials.cin2-evidence')
        @verbatim

        <!-- الطبقة 5: ستارة الانتقال بين المشاهد -->
        <div class="imm-veil" :class="veil ? 'is-on' : ''" aria-hidden="true" data-imm-veil></div>
        @endverbatim
    </div>
@endsection
