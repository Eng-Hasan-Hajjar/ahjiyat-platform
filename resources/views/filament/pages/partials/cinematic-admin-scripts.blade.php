{{--
    CIN-1 UX - سلوك واجهة الإدارة (Alpine). دوال على window تُستدعى بـx-data="cinWorkspace(...)" فلا تعتمد على توقيت alpine:init.
    لا شبكة هنا إلا في رافع الوسائط (XHR إلى مسار الرفع المحروس نفسه)، ولا أي سرّ ولا حل يمرّ عبر هذا الملف سوى نص حقل الحل الذي يحرّره المسؤول.

    1) cinWorkspace : تتبّع التغييرات غير المحفوظة + تحذير قبل التنقّل/المغادرة، تركيز المحرّر وتمريره عند فتح مشهد، العودة لموضع المشهد، طيّ الفصول.
    2) cinCalibrator: معايرة مناطق النقر بصريًا فوق صورة المشهد (سحب/تحجيم/تحديد بالنقر/لوحة مفاتيح) وكتابتها بنفس صيغة النص الحالية (نسب مئوية من الصورة).
    3) cinUploader  : رفع الوسائط عبر XHR بتقدّم حقيقي (رفع البايتات) ثم حالة «فحص على الخادم».
--}}
<script>
(function () {
    'use strict';

    function clamp(v, lo, hi) { return Math.min(hi, Math.max(lo, v)); }
    function round1(v) { return Math.round(v * 10) / 10; }
    function reduced() { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; }

    /* ───────── 1) مساحة عمل المنشئ ───────── */
    window.cinWorkspace = function (cfg) {
        cfg = cfg || {};
        return {
            dirtyScene: false,
            dirtyMedia: false,
            editingId: cfg.editing || null,
            lastRow: cfg.editing || null,
            collapsed: {},
            get dirty() { return this.dirtyScene || this.dirtyMedia; },
            set dirty(v) { this.dirtyScene = !!v; this.dirtyMedia = !!v; },
            leaveMsg: cfg.leaveMsg || 'لديك تغييرات غير محفوظة في هذا المشهد. هل تريد تجاهلها والمتابعة؟',

            init: function () {
                var self = this;
                this._beforeUnload = function (e) { if (self.dirty) { e.preventDefault(); e.returnValue = ''; } };
                window.addEventListener('beforeunload', this._beforeUnload);
                if (this.editingId) { this.focusEditor(); }
            },
            destroy: function () { window.removeEventListener('beforeunload', this._beforeUnload); },

            /* أي تعديل داخل المحرّر يرفع علم التغييرات (حقول الشريط العلوي والتصفح ليست منه). */
            markDirty: function (e) {
                var t = e.target;
                if (!t || !t.closest || !t.closest('[data-scene-editor]') || t.closest('[data-no-dirty]')) { return; }
                /* نفصل تغييرات الوسائط عن تغييرات الحقول: حفظ الوسائط لا يُسقط تحذير نص لم يُحفظ بعد (والعكس). */
                if (t.closest('[data-scene-media]')) { this.dirtyMedia = true; } else { this.dirtyScene = true; }
            },
            /* اعتراض في طور الالتقاط: النقر على عنصر [data-cin-guard] مع تغييرات غير محفوظة يطلب التأكيد، وإلا يُلغى قبل أن يصل إلى Livewire. */
            guard: function (e) {
                if (!this.dirty) { return; }
                var t = e.target && e.target.closest ? e.target.closest('[data-cin-guard]') : null;
                if (!t) { return; }
                if (window.confirm(this.leaveMsg)) { this.dirty = false; return; }
                e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
            },
            quickNav: function (e) {
                var v = e.target.value;
                if (!v) { return; }
                if (this.dirty && !window.confirm(this.leaveMsg)) { e.target.value = this.editingId || ''; return; }
                this.dirty = false;
                this.$wire.editScene(v);
            },

            opened: function (detail) {
                var id = detail && (detail.id || (detail[0] && detail[0].id)) || this.editingId;
                this.dirty = false; this.editingId = id; this.lastRow = id;
                this.reveal(id);
                this.focusEditor();
            },
            closed: function () { this.dirty = false; this.editingId = null; },
            saved: function (detail) {
                var kind = detail && (detail.kind || (detail[0] && detail[0].kind)) || 'scene';
                if (kind === 'media') { this.dirtyMedia = false; } else { this.dirtyScene = false; }
            },

            /* ينقل التركيز إلى عنوان المحرّر ويجعله مرئيًا: لوحة جانبية ثابتة على الشاشات العريضة، وتمرير سلس إلى المحرّر على الضيقة. لا يعتمد على Ctrl+End. */
            focusEditor: function () {
                var run = function () {
                    var panel = document.querySelector('[data-scene-editor]');
                    if (!panel) { return; }
                    var heading = panel.querySelector('[data-scene-heading]');
                    var col = document.querySelector('[data-editor-col]');
                    var wide = window.matchMedia('(min-width: 1100px)').matches;
                    if (wide) {
                        if (col) { col.scrollTop = 0; }
                        var r = panel.getBoundingClientRect();
                        if (r.top > window.innerHeight - 120 || r.bottom < 80) { panel.scrollIntoView({ block: 'start', behavior: reduced() ? 'auto' : 'smooth' }); }
                    } else {
                        panel.scrollIntoView({ block: 'start', behavior: reduced() ? 'auto' : 'smooth' });
                    }
                    if (heading) { heading.focus({ preventScroll: true }); }
                };
                window.requestAnimationFrame(function () { window.setTimeout(run, 30); });
            },
            /* العودة إلى موضع المشهد في القائمة وإبرازه. */
            backToList: function () {
                var row = this.lastRow ? document.getElementById('scene-row-' + this.lastRow) : null;
                if (!row) { return; }
                this.reveal(this.lastRow);
                row.scrollIntoView({ block: 'center', behavior: reduced() ? 'auto' : 'smooth' });
                var btn = row.querySelector('[data-scene-open]');
                if (btn) { btn.focus({ preventScroll: true }); }
            },

            isClosed: function (k) { return !!this.collapsed[k]; },
            toggle: function (k) { var c = Object.assign({}, this.collapsed); if (c[k]) { delete c[k]; } else { c[k] = true; } this.collapsed = c; },
            collapseChapters: function () {
                var c = {};
                this.$root.querySelectorAll('[data-fold-key^="chapter:"]').forEach(function (el) { c[el.getAttribute('data-fold-key')] = true; });
                this.collapsed = c;
            },
            expandAll: function () { this.collapsed = {}; },
            reveal: function (id) {
                var row = id ? document.getElementById('scene-row-' + id) : null;
                if (!row) { return; }
                var c = Object.assign({}, this.collapsed); var el = row.parentElement;
                while (el && el !== this.$root) {
                    var k = el.getAttribute && el.getAttribute('data-fold-key');
                    if (k) { delete c[k]; }
                    el = el.parentElement;
                }
                this.collapsed = c;
            }
        };
    };

    /* ───────── 2) المعايرة البصرية لمناطق التفاعل ─────────
       النسب (x,y,w,h) تُقاس دائمًا من الصورة نفسها: الإطار يأخذ نسبة أبعاد الصورة الحقيقية، فلا انزياح مهما تغيّر عرض الشاشة.
       الإطار اتجاهه LTR صراحة (الإحداثيات فيزيائية: x من اليسار) فلا يتأثر بـRTL الصفحة. */
    window.cinCalibrator = function (cfg) {
        cfg = cfg || {};
        var HAS_LABEL = cfg.format !== 'hidden';
        return {
            canEdit: cfg.canEdit !== false, areas: [], active: 0, stageW: 0, stageH: 0, ratio: cfg.ratio || (16 / 9), imageW: cfg.width || 0, imageH: cfg.height || 0, lastText: null, drag: null, hint: '',
            hasLabel: HAS_LABEL, prefix: cfg.prefix || 'area',

            init: function () {
                this.src = document.getElementById(cfg.source);
                this.parse();
                var self = this;
                if (this.src) { this.src.addEventListener('input', function () { self.syncFromText(); }); }
                this._saved = function () { self.syncFromText(true); };
                window.addEventListener('cin-scene-saved', this._saved);
                /* Livewire يملأ قيمة الحقل (wire:model) بعد تهيئة Alpine وبلا حدث input؛ نعيد القراءة لفترة قصيرة بعد التهيئة، ثم عند أي تفاعل قبل البدء. */
                var tries = 0;
                this._poll = window.setInterval(function () { self.syncFromText(); tries += 1; if (tries > 24) { window.clearInterval(self._poll); } }, 120);
                this.$nextTick(function () { self.syncFromText(); self.measure(); });
                if (window.ResizeObserver && this.$refs.stage) { this._ro = new ResizeObserver(function () { self.measure(); }); this._ro.observe(this.$refs.stage); }
            },
            measure: function () { var st = this.$refs.stage; if (st) { var r = st.getBoundingClientRect(); this.stageW = r.width; this.stageH = r.height; } },
            small: function (a) { return !this.stageW || (a.w / 100 * this.stageW < 56) || (a.h / 100 * this.stageH < 56); },
            destroy: function () { window.removeEventListener('cin-scene-saved', this._saved); if (this._poll) { window.clearInterval(this._poll); } if (this._ro) { this._ro.disconnect(); } },

            get box() { return this.areas[this.active] || null; },
            get isWide() { return Math.abs(this.ratio - 16 / 9) < 0.02; },
            get ratioText() { return this.imageW && this.imageH ? this.imageW + '×' + this.imageH : ''; },

            imageLoaded: function (ev) {
                var img = ev.target;
                if (img.naturalWidth && img.naturalHeight) { this.ratio = img.naturalWidth / img.naturalHeight; this.imageW = img.naturalWidth; this.imageH = img.naturalHeight; }
            },

            parse: function () {
                var text = this.src ? this.src.value : '';
                var rows = text.split(/\r?\n/).map(function (r) { return r.trim(); }).filter(function (r) { return r !== ''; });
                var self = this;
                var num = function (v, d) { var n = parseFloat(v); return isNaN(n) ? d : n; };
                this.areas = rows.map(function (row) {
                    var p = row.split('|').map(function (s) { return s.trim(); });
                    if (self.hasLabel) { return { id: p[0] || '', label: p[1] || '', x: num(p[2], 0), y: num(p[3], 0), w: num(p[4], 8), h: num(p[5], 8), fresh: false }; }
                    return { id: p[0] || '', label: '', x: num(p[1], 0), y: num(p[2], 0), w: num(p[3], 8), h: num(p[4], 8), fresh: false };
                });
                if (this.active >= this.areas.length) { this.active = Math.max(0, this.areas.length - 1); }
                this.lastText = text;
            },
            syncFromText: function (force) {
                if (!this.src) { return; }
                if (force || this.src.value !== this.lastText) { this.parse(); }
            },
            text: function () {
                var hasLabel = this.hasLabel;
                return this.areas.map(function (a) {
                    return (hasLabel ? [a.id, a.label, a.x, a.y, a.w, a.h] : [a.id, a.x, a.y, a.w, a.h]).join('|');
                }).join('\n');
            },
            /* يكتب النص في الحقل الأصلي ويُبلغ Livewire (حدث input) فيُحفظ مع المشهد، ويرفع علم التغييرات غير المحفوظة. */
            commit: function () {
                var t = this.text();
                this.lastText = t;
                if (this.src) { this.src.value = t; this.src.dispatchEvent(new Event('input', { bubbles: true })); }
            },

            style: function (a) { return 'left:' + a.x + '%;top:' + a.y + '%;width:' + a.w + '%;height:' + a.h + '%;'; },
            select: function (i) { this.active = i; },
            add: function () {
                var n = this.areas.length + 1; var ids = this.areas.map(function (a) { return a.id; });
                while (ids.indexOf(this.prefix + '_' + n) !== -1) { n++; }
                this.areas.push({ id: this.prefix + '_' + n, label: this.hasLabel ? 'منطقة جديدة' : '', x: 46, y: 46, w: 8, h: 8, fresh: true });
                this.active = this.areas.length - 1; this.commit();
                this.hint = 'أُضيفت منطقة في وسط الصورة: اسحبها فوق العنصر المطلوب أو انقر على الصورة لوضع مركزها هناك.';
            },
            remove: function () {
                if (!this.box) { return; }
                this.areas.splice(this.active, 1);
                this.active = Math.max(0, this.active - 1); this.commit();
            },
            normalize: function (a) {
                a.w = round1(clamp(a.w, 1, 100)); a.h = round1(clamp(a.h, 1, 100));
                a.x = round1(clamp(a.x, 0, 100 - a.w)); a.y = round1(clamp(a.y, 0, 100 - a.h));
            },
            numberChanged: function () { if (this.box) { this.normalize(this.box); this.commit(); } },

            /* ───── مؤشر الفأرة/اللمس ───── */
            point: function (ev) {
                var r = this.$refs.stage.getBoundingClientRect();
                return { x: (ev.clientX - r.left) / r.width * 100, y: (ev.clientY - r.top) / r.height * 100, w: r.width, h: r.height };
            },
            down: function (ev, i, mode) {
                ev.preventDefault(); ev.stopPropagation();
                this.syncFromText();
                this.active = i; var a = this.areas[i]; var p = this.point(ev);
                this.drag = { mode: mode, i: i, sx: p.x, sy: p.y, o: { x: a.x, y: a.y, w: a.w, h: a.h } };
                try { this.$refs.stage.setPointerCapture(ev.pointerId); } catch (e) { /* بعض المتصفحات */ }
            },
            stageDown: function (ev) {
                /* نقرة على الصورة: تضع مركز المنطقة المحددة عند النقطة (أسهل طريقة لتحديد مقبض صغير بدقة). */
                this.syncFromText();
                if (!this.box) { return; }
                ev.preventDefault();
                var p = this.point(ev); var a = this.box;
                a.x = round1(clamp(p.x - a.w / 2, 0, 100 - a.w)); a.y = round1(clamp(p.y - a.h / 2, 0, 100 - a.h));
                this.drag = { mode: 'move', i: this.active, sx: p.x, sy: p.y, o: { x: a.x, y: a.y, w: a.w, h: a.h } };
                try { this.$refs.stage.setPointerCapture(ev.pointerId); } catch (e) { /* */ }
                this.commit();
            },
            move: function (ev) {
                if (!this.drag) { return; }
                var d = this.drag; var a = this.areas[d.i]; var p = this.point(ev); var dx = p.x - d.sx; var dy = p.y - d.sy; var o = d.o; var MIN = 1;
                if (d.mode === 'move') {
                    a.x = round1(clamp(o.x + dx, 0, 100 - o.w)); a.y = round1(clamp(o.y + dy, 0, 100 - o.h));
                } else {
                    var m = d.mode; var x = o.x, y = o.y, w = o.w, h = o.h;
                    if (m.indexOf('w') !== -1) { x = clamp(o.x + dx, 0, o.x + o.w - MIN); w = o.x + o.w - x; }
                    if (m.indexOf('e') !== -1) { w = clamp(o.w + dx, MIN, 100 - o.x); }
                    if (m.indexOf('n') !== -1) { y = clamp(o.y + dy, 0, o.y + o.h - MIN); h = o.y + o.h - y; }
                    if (m.indexOf('s') !== -1) { h = clamp(o.h + dy, MIN, 100 - o.y); }
                    a.x = round1(x); a.y = round1(y); a.w = round1(w); a.h = round1(h);
                }
            },
            up: function () { if (this.drag) { this.drag = null; this.commit(); } },
            /* لوحة المفاتيح: الأسهم تحرّك (Shift ×4)، Alt+الأسهم تغيّر الحجم. */
            key: function (ev, i) {
                var step = ev.shiftKey ? 2 : 0.5; var a = this.areas[i]; var k = ev.key; var dx = 0, dy = 0;
                if (k === 'ArrowLeft') { dx = -step; } else if (k === 'ArrowRight') { dx = step; } else if (k === 'ArrowUp') { dy = -step; } else if (k === 'ArrowDown') { dy = step; } else { return; }
                ev.preventDefault(); this.active = i;
                if (ev.altKey) { a.w += dx; a.h += dy; } else { a.x += dx; a.y += dy; }
                this.normalize(a); this.commit();
            }
        };
    };

    /* ───────── 3) رافع الوسائط ───────── */
    window.cinUploader = function (cfg) {
        cfg = cfg || {};
        return {
            role: cfg.role || 'video', busy: false, percent: 0, phase: '', files: [], results: [], dragOver: false,
            accept: cfg.accept || '',
            labels: { video: 'فيديو', poster: 'صورة', subtitle: 'ترجمة', audio: 'صوت' },

            pick: function (ev) { this.files = Array.prototype.slice.call(ev.target.files || []); this.results = []; },
            drop: function (ev) { this.dragOver = false; this.files = Array.prototype.slice.call(ev.dataTransfer && ev.dataTransfer.files || []); this.results = []; },
            get totalText() { var b = this.files.reduce(function (s, f) { return s + f.size; }, 0); return b >= 1048576 ? (b / 1048576).toFixed(1) + ' م.ب' : Math.max(1, Math.round(b / 1024)) + ' ك.ب'; },

            start: async function () {
                if (this.busy || !this.files.length) { return; }
                this.busy = true; this.results = [];
                for (var i = 0; i < this.files.length; i++) { await this.sendOne(this.files[i], i + 1, this.files.length); }
                this.busy = false; this.phase = ''; this.files = [];
                if (this.$refs.file) { this.$refs.file.value = ''; }
                /* بعد رفع ناجح نحدّث قائمة المكتبة (Livewire) لتظهر الملفات الجديدة. */
                if (this.results.some(function (r) { return r.ok; }) && this.$wire) { this.$wire.$refresh(); }
            },
            sendOne: function (file, n, total) {
                var self = this;
                return new Promise(function (resolve) {
                    var meta = document.querySelector('meta[name="csrf-token"]');
                    var fd = new FormData(); fd.append('role', self.role); fd.append('file', file);
                    var xhr = new XMLHttpRequest();
                    self.percent = 0; self.phase = 'رفع الملف ' + n + ' من ' + total + ': ' + file.name;
                    xhr.open('POST', cfg.url, true);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    if (meta) { xhr.setRequestHeader('X-CSRF-TOKEN', meta.content); }
                    xhr.upload.onprogress = function (e) {
                        if (e.lengthComputable) {
                            self.percent = Math.round(e.loaded / e.total * 100);
                            if (e.loaded >= e.total) { self.phase = 'اكتمل الرفع، يفحص الخادم الملف الآن (النوع الحقيقي، الحجم، خصائص الفيديو)…'; }
                        }
                    };
                    var finish = function (row) { self.results.push(Object.assign({ name: file.name }, row)); resolve(); };
                    xhr.onload = function () {
                        var data = {};
                        try { data = JSON.parse(xhr.responseText); } catch (e) { data = {}; }
                        if (xhr.status >= 200 && xhr.status < 300) {
                            finish({ ok: true, duplicate: data.created === false, warnings: data.warnings || [], id: data.id });
                        } else if (xhr.status === 413) {
                            finish({ ok: false, message: 'الملف أكبر مما يسمح به الخادم (post_max_size / upload_max_filesize) قبل أن يصل إلى فحص المنصة.' });
                        } else if (xhr.status === 419) {
                            finish({ ok: false, message: 'انتهت جلسة الصفحة. حدّث الصفحة ثم أعد المحاولة.' });
                        } else if (xhr.status === 429) {
                            finish({ ok: false, message: 'محاولات رفع كثيرة خلال وقت قصير. انتظر دقيقة ثم أعد المحاولة.' });
                        } else if (xhr.status === 403) {
                            finish({ ok: false, message: 'لا تملك صلاحية رفع الوسائط.' });
                        } else {
                            finish({ ok: false, message: data.message || ('تعذّر رفع الملف (رمز ' + xhr.status + ').') });
                        }
                    };
                    xhr.onerror = function () { finish({ ok: false, message: 'انقطع الاتصال أثناء الرفع. تحقق من الشبكة وأعد المحاولة.' }); };
                    xhr.send(fd);
                });
            }
        };
    };
})();
</script>
