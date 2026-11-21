{{--
    CIN-1 IMMERSIVE - سلوك الوضع الغامر للمشغّل (يُدمج في مكوّن Alpine cinematicPlayer عبر window.cinImmersiveMixin).
    ─ عرض فقط: لا قرار تقدّم ولا مكافأة ولا إرسال هنا. كل ما يتعلق بالحل/التقدّم يبقى في المكوّن الأصلي وعلى الخادم.
    ─ ما يضيفه: ملء الشاشة (Fullscreen API مع بديل webkit؛ وإخفاؤه حيث لا يُدعم مثل iPhone)، إخفاء الشريط أثناء تشغيل الفيديو،
      ستارة انتقال بين المشاهد، تلميح ذكي بعد خمول اللاعب، مؤثر نجاح (تقريب + وميض) على موضع النقر، تقدّم تلقائي قصير بعد نجاح النقر على عنصر
      (بلا رسالة طويلة ولا رمز مكشوف؛ يتوقف عند التمرير/التركيز)، تشغيل تلقائي لفيديو المشهد التالي بعد أول لمسة، وحالة درج أدوات المعاينة الإشرافية.
    ─ احترام «تقليل الحركة»: لا ستارة ولا تقدّم تلقائي ولا نبض.
    ─ التخزين المحلي (حالة درج الأدوات فقط) داخل try/catch ويعمل المشغّل بدونه.
--}}
<script data-cinematic-immersive-script>
@verbatim
(function () {
    var reduced = function () { try { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) { return false; } };
    var sleep = function (ms) { return new Promise(function (resolve) { setTimeout(resolve, ms); }); };
    var store = {
        get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* التخزين محجوب: لا بأس */ } }
    };
    var AREA_KINDS = ['hotspot', 'container'];
    var STAGE_KINDS = ['hotspot', 'container', 'flashlight'];
    var OK_FALLBACK = { hotspot: 'أصبتَ الموضع… ينفتح الطريق أمامك.', container: 'أصبتَ الموضع… ينفتح الطريق أمامك.', flashlight: 'ظهر شيء تحت الضوء…', sequence: 'ترتيبك في محلّه.', drag_drop: 'استقرّت القطع في أماكنها.', choose_item: 'اختيار موفّق.', compare: 'اختيار موفّق.', reveal_code: 'انكشف السر.', answer: 'إجابة صحيحة.', inspect: 'أنهيت الفحص.', multi_lock: 'انفتح القفلان.', board_move: 'استقرّ الحصان في أثره.', inference: 'استنتاج يسنده دليله.' };

    window.cinImmersiveMixin = function () {
        return {
            adminOpen: false, helpOpen: true, hudIdle: false, fs: false, fsSupported: false, veil: false, videoPlaying: false,
            showZones: false, pulse: false, zoom: null, flash: 0, auto: null, autoPaused: false, unlocked: false, panelOpen: false, quiet: false,
            diag: [], lastLabel: '', hudTimer: null, hintTimer: null, autoTimer: null, quietTimer: null, lastBump: 0,

            /* ───── أوضاع العرض ───── */
            get view() { return this.cfg.view || (this.preview ? 'admin' : 'player'); },
            get adminTools() { return this.preview && this.view === 'admin'; },
            get playerLike() { return this.preview && this.view === 'player'; },
            get isInteractionScene() { return !!this.scene && (this.scene.type === 'interaction' || this.scene.type === 'exploration'); },
            /* media: فيديو | title: انتقال | stage: نقر مباشر على الصورة (باب/درج/مصباح) | paper: ورقة الخيارات والنص */
            get layoutKind() {
                var s = this.scene;
                if (!s) { return 'none'; }
                if (s.type === 'video') { return 'media'; }
                if (s.type === 'transition') { return 'title'; }
                if (this.isInteractionScene && STAGE_KINDS.indexOf(this.kind) !== -1) { return 'stage'; }
                return 'paper';
            },
            get hasArt() {
                var s = this.scene;
                if (!s) { return false; }
                if (s.type === 'video') { return true; }
                if (s.type === 'transition') { return false; }
                if (s.type === 'puzzle') { return !!(s.puzzle && s.puzzle.image_url); }
                /* PLM-3: الرقعة هي المسرح نفسه (لا صورة مطلوبة) فيبقى التخطيط جانبيًا/علويًا للرقعة والورقة معًا */
                if (this.isInteractionScene && this.kind === 'board_move') { return true; }
                return !!s.image;
            },
            get bgUrl() {
                var s = this.scene;
                if (!s) { return ''; }
                if (s.type === 'video') { return s.video && s.video.poster ? s.video.poster : ''; }
                if (s.type === 'puzzle') { return s.puzzle && s.puzzle.image_url ? s.puzzle.image_url : ''; }
                return s.image || '';
            },
            get backdropStyle() { var u = this.bgUrl; return u ? '--imm-bg:url("' + String(u).replace(/["\\\r\n]/g, '') + '");' : ''; },
            get rootClass() { return { 'is-idle': this.hudIdle, 'is-fs': this.fs, 'is-admin': this.adminTools, 'is-player-like': this.playerLike }; },
            get isAreaKind() { return AREA_KINDS.indexOf(this.kind) !== -1; },
            get backHref() { return this.cfg.urls && this.cfg.urls.back ? this.cfg.urls.back : '#'; },
            get backLabel() { return this.preview ? 'عودة للوحة الإدارة' : 'صفحة القصة'; },
            get kicker() {
                var base = this.meta ? (this.meta.stage.title + ' · ' + this.meta.chapter.title) : (this.cfg.title || '');
                if (this.preview) { return this.scene ? base + ' · ' + (this.previewIndex + 1) + ' / ' + this.previewIds.length : base; }
                var p = this.state && this.state.progress;
                return p ? base + ' · ' + p.completed_scenes + ' / ' + p.total_scenes : base;
            },
            get headingText() { return this.scene ? this.scene.title : (this.cfg.title || ''); },
            get showInstructions() { return !!(this.ix && this.ix.instructions) && (this.helpOpen || this.pulse); },
            get zoneAreas() { return this.adminTools && this.showZones && this.ix && this.ix.areas ? this.ix.areas : []; },
            get autoPct() { return this.auto ? Math.max(0, Math.min(100, (this.auto.left / this.auto.total) * 100)) : 0; },
            /* رسالة النتيجة: نص المؤلّف إن وُجد، وإلا عبارة قصصية محايدة حسب نوع التفاعل (لا ادّعاء حدث بعينه). */
            get resultText() {
                var r = this.result;
                if (!r) { return ''; }
                if (r.message) { return r.message; }
                if (r.correct) {
                    /* نص المؤلّف غائب: عبارة قصصية باسم العنصر الذي لمسه اللاعب (تسمية المنطقة من المحرّر) أو العبارة المحايدة للنوع */
                    if (this.isAreaKind && this.lastLabel) { return 'لمستَ «' + this.lastLabel + '»… وينفتح الطريق أمامك.'; }
                    return OK_FALLBACK[this.kind] || 'إجابة صحيحة!';
                }
                return r.locked ? 'لا يمكنك فعل ذلك بعد.' : 'ليست هذه… حاول مرة أخرى.';
            },
            get resultStory() { return !!(this.result && this.result.correct && this.isAreaKind); },

            /* ───── التهيئة ───── */
            initImmersive() {
                var el = this.$el;
                this.fsSupported = !!(el && (el.requestFullscreen || el.webkitRequestFullscreen)) && document.fullscreenEnabled !== false;
                this.syncFs();
                this.helpOpen = window.innerWidth >= 768 && window.innerHeight >= 560;   /* هاتف أفقي قصير: التعليمات مطويّة افتراضيًا لئلا تغطي المشهد */
                this.adminOpen = this.adminTools && store.get('cin-imm-admin') === '1';
                this.showZones = this.adminTools && store.get('cin-imm-zones') === '1';
            },
            unlock() { this.unlocked = true; },
            onKey(ev) {
                this.unlock(); this.bumpHud();
                if (ev.defaultPrevented || ev.ctrlKey || ev.metaKey || ev.altKey) { return; }
                var t = ev.target; var tag = t && t.tagName ? t.tagName.toLowerCase() : '';
                if (ev.key === 'Escape' && this.adminOpen) { this.setAdmin(false); return; }
                if (tag === 'input' || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable)) { return; }
                /* ev.code لا ev.key: يعمل مع لوحة المفاتيح العربية أيضًا */
                if (ev.code === 'KeyF' && this.fsSupported) { ev.preventDefault(); this.toggleFs(); }
            },
            onResize() { if (this.adminOpen) { this.refreshDiag(); } },

            /* ───── ملء الشاشة ───── */
            fsElement() { return document.fullscreenElement || document.webkitFullscreenElement || null; },
            syncFs() { this.fs = !!this.fsElement(); },
            async toggleFs() {
                try {
                    if (this.fsElement()) {
                        var exit = document.exitFullscreen || document.webkitExitFullscreen;
                        if (exit) { await exit.call(document); }
                    } else {
                        var el = this.$el; var req = el.requestFullscreen || el.webkitRequestFullscreen;
                        if (req) { await req.call(el, { navigationUI: 'hide' }); }
                    }
                } catch (e) { /* رفض المتصفح ملء الشاشة: التجربة الغامرة تعمل بدونه */ }
                this.syncFs();
            },

            /* ───── الشريط العلوي: يختفي أثناء تشغيل الفيديو ويعود عند أي حركة أو تركيز ───── */
            bumpHud() {
                var now = Date.now();
                if (!this.hudIdle && now - this.lastBump < 350) { return; }
                this.lastBump = now; this.hudIdle = false;
                clearTimeout(this.hudTimer);
                if (this.videoPlaying && !this.adminOpen) { var self = this; this.hudTimer = setTimeout(function () { self.hudIdle = true; }, 3200); }
            },
            onVideoPlay() { this.videoPlaying = true; this.bumpHud(); },
            onVideoStop() { this.videoPlaying = false; this.hudIdle = false; clearTimeout(this.hudTimer); },
            /* إطار الفيديو يتبع النسبة الحقيقية للمقطع (16:9 أو عمودي 9:16 أو مربّع) فيملأ المسرح بأقصى ما يتسع دون قصّ */
            fitVideo(ev) {
                var v = ev && ev.target; if (!v || !v.videoWidth || !v.videoHeight || !v.parentElement) { return; }
                var r = v.videoWidth / v.videoHeight; r = Math.min(3, Math.max(0.4, r));
                v.parentElement.style.setProperty('--vr', String(Math.round(r * 10000) / 10000));
            },

            /* ───── درج أدوات المعاينة الإشرافية ───── */
            setAdmin(open) { this.adminOpen = !!open; store.set('cin-imm-admin', this.adminOpen ? '1' : '0'); if (this.adminOpen) { this.hudIdle = false; this.refreshDiag(); } },
            setZones(on) { this.showZones = !!on; store.set('cin-imm-zones', this.showZones ? '1' : '0'); },
            refreshDiag() {
                var st = this.$refs.stage || this.$refs.lightStage || this.$refs.inspectStage; var box = st ? st.getBoundingClientRect() : null; var s = this.scene;
                var rows = [
                    ['مفتاح المشهد', s ? s.key : '—'], ['نوع المشهد', s ? s.type : '—'], ['نوع التفاعل', this.kind || '—'], ['التخطيط', this.layoutKind],
                    ['نسبة صورة المشهد', this.ix && (this.kind === 'hotspot' || this.kind === 'container' || this.kind === 'flashlight' || this.kind === 'inspect') ? this.stageRatio.toFixed(3) : '—'],
                    ['حجم إطار الصورة', box ? Math.round(box.width) + '×' + Math.round(box.height) + 'px' : '—'],
                    ['حجم الشاشة', window.innerWidth + '×' + window.innerHeight + 'px'],
                    ['الاتجاه', window.innerWidth >= window.innerHeight ? 'أفقي' : 'عمودي'],
                    ['ملء الشاشة', this.fsSupported ? 'مدعوم' : 'غير مدعوم هنا']
                ];
                if (this.ix && this.ix.areas) { this.ix.areas.forEach(function (a) { rows.push(['منطقة ' + a.id, a.x + ',' + a.y + ' · ' + a.w + '×' + a.h + ' %']); }); }
                this.diag = rows;
            },

            /* ───── التلميح الذكي: بعد خمول اللاعب على مشهد نقر مباشر ───── */
            armHint() {
                this.pulse = false; clearTimeout(this.hintTimer);
                if (this.layoutKind !== 'stage' || this.kind === 'flashlight' || this.doneNow) { return; }
                var self = this;
                this.hintTimer = setTimeout(function () { if (!self.doneNow) { self.pulse = true; self.quiet = false; } }, 6500);
            },
            armQuiet() {
                /* تعليق المسرح الشفاف يخفت بعد قليل ليبقى المشهد نظيفًا؛ يعود عند المرور/التركيز أو بزر المساعدة. */
                this.quiet = false; clearTimeout(this.quietTimer);
                if (!this.isAreaKind) { return; }
                var self = this;
                this.quietTimer = setTimeout(function () { if (!self.result && !self.pulse) { self.quiet = true; } }, 9000);
            },
            toggleHelp() { this.helpOpen = !this.helpOpen; this.quiet = false; this.armQuiet(); },

            /* ───── دورة المشهد (تُستدعى من المكوّن الأصلي) ───── */
            resetImmersive() {
                this.cancelAuto(); clearTimeout(this.hintTimer); clearTimeout(this.quietTimer);
                this.pulse = false; this.zoom = null; this.flash = 0; this.lastLabel = ''; this.panelOpen = false; this.quiet = false; this.videoPlaying = false;
                this.helpOpen = window.innerWidth >= 768 && window.innerHeight >= 560;
            },
            afterEnter() {
                var self = this;
                this.armHint(); this.armQuiet(); this.bumpHud();
                /* بعد أول لمسة يسمح المتصفح بتشغيل فيديو المشهد التالي مباشرة (وإن رفض يبقى زر التشغيل). */
                if (this.scene && this.scene.type === 'video' && this.unlocked && this.videoState() === 'ready') {
                    setTimeout(function () { if (self.scene && self.$refs.video) { self.playVideo(); } }, 150);
                }
            },
            afterResult(submission) {
                this.cancelAuto();
                var r = this.result;
                if (!r || !r.correct) { return; }
                this.pulse = false; clearTimeout(this.hintTimer);
                var area = null; var id = submission && submission.area ? submission.area : null;
                if (id && this.ix && this.ix.areas) { area = this.ix.areas.filter(function (a) { return a.id === id; })[0] || null; }
                this.lastLabel = area ? String(area.label || '').slice(0, 40) : '';
                if (area) { this.zoom = { x: Math.round((area.x + area.w / 2) * 10) / 10, y: Math.round((area.y + area.h / 2) * 10) / 10 }; this.flash = Date.now(); }
                this.scheduleAuto();
            },

            /* ───── التقدّم: ستارة قصيرة ثم الانتقال (بلا ستارة مع تقليل الحركة) ───── */
            async goNext() {
                if (this.busy) { return; }
                this.cancelAuto();
                if (!reduced()) { this.veil = true; await sleep(220); }
                try { await this.proceed(); } finally { this.veil = false; }
            },
            async continueScene(mode) {
                if (this.busy) { return; }
                if (!reduced()) { this.veil = true; await sleep(180); }
                try { await this.finish(mode); } finally { this.veil = false; }
            },
            /* تقدّم تلقائي: فقط بعد نجاح نقر على عنصر (باب/درج) برسالة قصيرة وبلا رمز مكشوف، وليس في معاينة الإدارة (تبقى يدوية). */
            scheduleAuto() {
                if (this.adminTools || reduced() || !this.isAreaKind || this.revealed) { return; }
                var msg = this.result && this.result.message ? String(this.result.message) : '';
                if (msg.length > 90) { return; }
                var total = msg ? 2800 : 1500;
                this.auto = { total: total, left: total }; this.autoPaused = false;
                this.tickAuto();
            },
            tickAuto() {
                var self = this; clearTimeout(this.autoTimer);
                this.autoTimer = setTimeout(function () {
                    if (!self.auto) { return; }
                    if (!self.autoPaused) { self.auto.left -= 100; }
                    if (self.auto.left <= 0) { self.auto = null; self.goNext(); return; }
                    self.tickAuto();
                }, 100);
            },
            cancelAuto() { clearTimeout(this.autoTimer); this.auto = null; this.autoPaused = false; }
        };
    };
})();
@endverbatim
</script>
