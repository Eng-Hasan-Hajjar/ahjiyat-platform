{{--
    أنماط وسلوك المكوّن المشترك <x-searchable-select>. يُضمَّن مرة واحدة في أعلى الصفحة (خارج أي كتلة شرطية)، لا داخل المكوّن:
    Livewire لا ينفّذ <script> يُحقن بالمقارنة (morph) بعد التحميل الأول، فلو وُضع السكربت داخل المكوّن لما عمل في محرّر يُفتح لاحقًا.

    لماذا هنا وليس ملف CSS/JS في Vite؟ لوحة Filament في هذا المشروع بلا ثيم Vite مخصّص، فأي أصناف Tailwind جديدة لا تُبنى فيها.
    لذلك كل القواعد محصورة بـ.ss-root وبرموز --ss-* (فاتح افتراضي و.dark غامق: لوحة الإدارة غامقة دائمًا لكن الفاتح محفوظ احتياطًا)،
    ونسب التباين مفحوصة آليًا في SearchableSelectContrastTest (النص ≥ 4.5:1، الحدود والتركيز ≥ 3:1) فلا تُغيَّر القيم دون إعادة الفحص.
--}}
@once
<style data-ss-styles>
.ss-root {
    --ss-surface: #ffffff;
    --ss-surface-2: #f3f4f6;
    --ss-field: #ffffff;
    --ss-fg: #111827;
    --ss-muted: #4b5563;
    --ss-border: #6b7280;
    --ss-border-soft: #d1d5db;
    --ss-primary: #5b21b6;
    --ss-primary-fg: #ffffff;
    --ss-active-bg: #ede9fe;
    --ss-focus: #5b21b6;
    --ss-ok: #065f46; --ss-ok-bg: #d1fae5;
    --ss-warn: #78350f; --ss-warn-bg: #fef3c7;
    --ss-danger: #9f1239; --ss-danger-bg: #ffe4e6;
    --ss-disabled-fg: #4b5563; --ss-disabled-bg: #e5e7eb;
    color-scheme: light;
}
.dark .ss-root {
    --ss-surface: #111827;
    --ss-surface-2: #1f2937;
    --ss-field: #0b1220;
    --ss-fg: #f3f4f6;
    --ss-muted: #a8b1c0;
    --ss-border: #6b7280;
    --ss-border-soft: #374151;
    --ss-primary: #7c3aed;
    --ss-primary-fg: #ffffff;
    --ss-active-bg: #2a1f4d;
    --ss-focus: #c4b5fd;
    --ss-ok: #6ee7b7; --ss-ok-bg: #052e24;
    --ss-warn: #fde68a; --ss-warn-bg: #3a2a05;
    --ss-danger: #fda4af; --ss-danger-bg: #3b1219;
    --ss-disabled-fg: #9ca3af; --ss-disabled-bg: #1b2231;
    color-scheme: dark;
}
.ss-root { position: relative; display: block; min-width: 0; max-width: 100%; color: var(--ss-fg); font-size: 0.9rem; line-height: 1.45; font-weight: 400; scroll-margin-block: 5rem; /* يترك هامشًا لرأس المحرّر الثابت وشريط الحفظ الثابت عند إظهار اللوحة */ }
.ss-root *, .ss-root *::before, .ss-root *::after { box-sizing: border-box; }
.ss-root [x-cloak] { display: none !important; }
.ss-root p, .ss-root ul { margin: 0; padding: 0; list-style: none; }

.ss-root .ss-field { display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: stretch; }
.ss-root .ss-trigger {
    flex: 1 1 14rem; min-width: 0; display: flex; align-items: center; justify-content: space-between; gap: 0.6rem;
    min-height: 2.75rem; padding: 0.45rem 0.75rem; text-align: start; font: inherit; color: var(--ss-fg);
    background-color: var(--ss-field); border: 1px solid var(--ss-border); border-radius: 0.5rem; cursor: pointer;
}
.ss-root .ss-trigger[aria-expanded=true] { border-color: var(--ss-focus); }
.ss-root .ss-trigger-text { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; flex: 1 1 auto; }
.ss-root .ss-placeholder { color: var(--ss-muted); }
.ss-root .ss-chevron { width: 1.1rem; height: 1.1rem; flex: none; color: var(--ss-muted); }
.ss-root .ss-trigger[aria-expanded=true] .ss-chevron { transform: rotate(180deg); }

/* اسم الملف في سطر واضح، معزول: الامتداد وأسماء PLM_S02_… تبقى بترتيبها الصحيح داخل صفحة RTL. */
.ss-root .ss-name { display: block; unicode-bidi: isolate; overflow-wrap: anywhere; font-weight: 700; color: var(--ss-fg); }
.ss-root .ss-name[dir=ltr] { text-align: left; }
[dir=rtl] .ss-root .ss-name[dir=ltr] { text-align: right; }
.ss-root .ss-none { font-weight: 600; color: var(--ss-fg); }
/* الخصائص في سطر ثانٍ: كل خاصية معزولة (bdi) كي لا ينقلب ترتيب 1920×1080 داخل جملة عربية. */
.ss-root .ss-meta, .ss-root .ss-dist { display: flex; flex-wrap: wrap; gap: 0.1rem 0.6rem; font-size: 0.78rem; color: var(--ss-muted); }
.ss-root .ss-part { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0 0.3em; white-space: normal; }
/* المسافة بين مقاطع الجزء الواحد (رُفع | تاريخ | وقت) بـgap على الحاوية (باتجاه الصفحة): هامش الـbdi المنطقي يتبع اتجاه الـbdi نفسه (عربي/لاتيني) فيقع في جهات مختلفة، والمسافة الطرفية داخل bdi تُبتلع. */
/* الفاصل على الحاوية (باتجاه الصفحة) لا على الـbdi: اتجاه كل bdi يختلف (عربي/لاتيني) فكان الفاصل يقع في جهات مختلفة. */
.ss-root .ss-part + .ss-part::before { content: '·'; margin-inline-end: 0.3rem; color: var(--ss-muted); }
.ss-root .ss-dist { color: var(--ss-warn); background: var(--ss-warn-bg); border-radius: 0.3rem; padding: 0.1rem 0.4rem; width: fit-content; max-width: 100%; }
.ss-root .ss-dist .ss-part + .ss-part::before { color: var(--ss-warn); }
.ss-root .ss-flag { font-size: 0.78rem; color: var(--ss-danger); background: var(--ss-danger-bg); border-radius: 0.3rem; padding: 0.1rem 0.4rem; width: fit-content; max-width: 100%; }

.ss-root .ss-btn {
    display: inline-flex; align-items: center; justify-content: center; min-height: 2.75rem; padding: 0.3rem 0.85rem;
    font: inherit; font-size: 0.85rem; font-weight: 700; color: var(--ss-fg); background: var(--ss-surface-2);
    border: 1px solid var(--ss-border); border-radius: 0.5rem; cursor: pointer; white-space: nowrap;
}
.ss-root .ss-btn:hover { border-color: var(--ss-focus); }
.ss-root .ss-btn[disabled] { color: var(--ss-disabled-fg); background: var(--ss-disabled-bg); cursor: not-allowed; }
.ss-root .ss-trigger[disabled] { color: var(--ss-disabled-fg); background-color: var(--ss-disabled-bg); cursor: not-allowed; }
fieldset:disabled .ss-root .ss-trigger { color: var(--ss-disabled-fg); background-color: var(--ss-disabled-bg); cursor: not-allowed; }

.ss-root :where(button, input, [tabindex]):focus-visible { outline: 2px solid var(--ss-focus); outline-offset: 2px; }

/* اللوحة ضمن التدفق (لا تعويم مطلق): لا تُقصّ داخل حاوية المحرّر المتمرّرة ولا على الجوال، وقائمتها تتمرّر داخليًا بارتفاع محدود. */
.ss-root .ss-panel { margin-top: 0.4rem; display: flex; flex-direction: column; gap: 0.4rem; padding: 0.6rem; background: var(--ss-surface); border: 1px solid var(--ss-border); border-radius: 0.6rem; }
.ss-root .ss-search { position: relative; display: flex; align-items: center; }
.ss-root .ss-search input {
    display: block; width: 100%; min-height: 2.75rem; padding: 0.5rem 0.75rem; padding-inline-end: 2.75rem; font: inherit; font-size: 0.95rem;
    color: var(--ss-fg); background-color: var(--ss-field); border: 1px solid var(--ss-border); border-radius: 0.5rem; box-shadow: none;
}
.ss-root .ss-search input::placeholder { color: var(--ss-muted); opacity: 1; }
.ss-root .ss-search input:focus { border-color: var(--ss-focus); }
.ss-root .ss-clear { position: absolute; inset-inline-end: 0.25rem; display: inline-flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; padding: 0; color: var(--ss-fg); background: transparent; border: 0; border-radius: 0.4rem; cursor: pointer; }
.ss-root .ss-clear svg { width: 1rem; height: 1rem; }
.ss-root .ss-status { min-height: 1.2rem; font-size: 0.8rem; color: var(--ss-muted); }
.ss-root .ss-error { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; padding: 0.5rem 0.7rem; border-radius: 0.5rem; color: var(--ss-danger); background: var(--ss-danger-bg); font-size: 0.85rem; font-weight: 600; }
.ss-root .ss-error .ss-btn { min-height: 2.25rem; }

.ss-root .ss-list { max-height: min(22rem, 55vh); overflow-y: auto; overscroll-behavior: contain; border: 1px solid var(--ss-border-soft); border-radius: 0.5rem; background: var(--ss-field); }
.ss-root .ss-opt { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; min-height: 2.75rem; padding: 0.5rem 0.75rem; cursor: pointer; border-inline-start: 4px solid transparent; color: var(--ss-fg); }
.ss-root .ss-opt + .ss-opt { border-top: 1px solid var(--ss-border-soft); }
.ss-root .ss-opt.is-active { background: var(--ss-active-bg); }
.ss-root .ss-opt.is-selected { border-inline-start-color: var(--ss-primary); }
.ss-root .ss-opt-body { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; flex: 1 1 auto; }
.ss-root .ss-tags { display: inline-flex; align-items: center; gap: 0.35rem; flex: none; }
.ss-root .ss-tag { font-size: 0.72rem; font-weight: 700; padding: 0.05rem 0.45rem; border-radius: 999px; color: var(--ss-ok); background: var(--ss-ok-bg); white-space: nowrap; }
.ss-root .ss-tag.is-warn { color: var(--ss-warn); background: var(--ss-warn-bg); }
.ss-root .ss-check { width: 1.1rem; height: 1.1rem; color: var(--ss-primary); }
.dark .ss-root .ss-check { color: var(--ss-focus); }
.ss-root .ss-more { align-self: center; min-width: 40%; }
@media (pointer: coarse) { .ss-root .ss-opt { min-height: 3rem; } .ss-root .ss-btn, .ss-root .ss-trigger, .ss-root .ss-search input { min-height: 3rem; } }
@media (prefers-reduced-motion: reduce) { .ss-root * { transition: none !important; animation: none !important; } }
</style>

<script data-ss-script>
(function () {
    'use strict';
    if (window.ssSelect) { return; }

    var seq = 0;
    var DEFAULTS = {
        placeholder: 'ابحث…', choose: 'اختر…', none: '— بلا —', remove: 'مسح الاختيار', clear: 'مسح نص البحث',
        loading: 'جارٍ البحث…', empty: 'لا توجد نتائج تطابق «:term».', emptyAll: 'لا توجد عناصر متاحة.',
        error: 'تعذّر البحث. تحقّق من الاتصال ثم حاول مجددًا.', retry: 'حاول مجددًا', more: 'تحميل المزيد',
        count: 'عرض :shown من :total', current: 'الحالي', retired: 'مسحوب', dup: 'اسم مكرر'
    };
    function reduced() { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; }

    window.ssSelect = function (cfg) {
        cfg = cfg || {};
        var T = Object.assign({}, DEFAULTS, cfg.text || {});
        return {
            uid: 'ss' + (++seq),
            T: T,
            method: cfg.method,
            ctx: cfg.context || {},
            model: cfg.model || null,
            nameDir: cfg.nameDir === 'auto' ? 'auto' : 'ltr',
            allowNone: !!cfg.allowNone,
            value: '',
            picked: null,
            serverSel: null,
            open: false, term: '', items: [], total: 0, next: null,
            loading: false, error: false, active: -1, req: 0, timer: null,

            init: function () {
                var self = this;
                this.value = this.$root.getAttribute('data-ss-value') || '';
                this.readServer();
                /* تغيّر القيمة من الخادم (مثلًا بعد الحفظ أو فتح مشهد آخر): نزامن العرض مع الوصف المرسَل من الخادم. */
                if (this.model && this.$wire && this.$wire.$watch) {
                    this.$wire.$watch(this.model, function (v) {
                        v = v == null ? '' : String(v);
                        if (v !== self.value) { self.value = v; self.picked = null; }
                        self.$nextTick(function () { self.readServer(); });
                    });
                }
            },
            destroy: function () { if (this.timer) { clearTimeout(this.timer); } },
            readServer: function () {
                try { this.serverSel = JSON.parse(this.$root.getAttribute('data-ss-selected') || 'null'); } catch (e) { this.serverSel = null; }
            },

            /* يفصل النص إلى مقاطع: كل رقم/تاريخ/وقت/بصمة/نوع/اسم لاتيني يُعزل باتجاه LTR (bdi)، فلا ينقلب 1920×1080 ولا 2026-10-05 داخل جملة عربية. */
            segs: function (text) {
                var s = String(text == null ? '' : text), out = [], re = /[A-Za-z0-9][A-Za-z0-9_.:×\/+\-]*/g, last = 0, m;
                while ((m = re.exec(s)) !== null) {
                    if (m.index > last && s.slice(last, m.index).trim() !== '') { out.push({ t: s.slice(last, m.index).trim(), ltr: false }); }
                    out.push({ t: m[0], ltr: true }); last = m.index + m[0].length;
                }
                if (last < s.length && s.slice(last).trim() !== '') { out.push({ t: s.slice(last).trim(), ltr: false }); }
                return out;
            },
            /* الاختيار الحالي للعرض: ما اختاره المستخدم للتو، وإلا الوصف المرسَل من الخادم (حتى لو كان الملف قديمًا خارج أي صفحة نتائج). */
            cur: function () {
                if (!this.value) { return null; }
                if (this.picked && this.picked.id === this.value) { return this.picked; }
                if (this.serverSel && this.serverSel.id === this.value) { return this.serverSel; }
                return { id: this.value, name: this.value, parts: [], dist: [], retired: false };
            },
            get rows() {
                var r = [];
                if (this.allowNone && this.term.trim() === '') { r.push({ id: '', none: true }); }
                return r.concat(this.items);
            },
            activeId: function () { return this.open && this.active >= 0 && this.rows[this.active] ? this.uid + '-o' + this.active : null; },
            label: function (row) {
                if (row.none) { return this.T.none; }
                return [row.name].concat(row.parts || [], row.dist && row.dist.length ? [this.T.dup].concat(row.dist) : [], row.current ? [this.T.current] : [], row.retired ? [this.T.retired] : []).join('، ');
            },
            statusText: function () {
                if (this.loading) { return this.T.loading; }
                if (this.error) { return ''; }
                if (!this.items.length) { return this.term.trim() === '' ? this.T.emptyAll : this.T.empty.replace(':term', this.term.trim()); }
                return this.T.count.replace(':shown', this.items.length).replace(':total', this.total);
            },

            toggle: function () { this.open ? this.close(true) : this.openList(); },
            openList: function () {
                if (this.open || this.$refs.trigger.disabled) { return; }
                this.open = true; this.term = ''; this.items = []; this.next = null; this.total = 0; this.error = false;
                this.fetchPage(0, true);
                var self = this;
                /* التركيز ينتقل إلى حقل «ابحث…» عند الفتح، ونُظهر اللوحة كاملة دون قصّ. */
                this.$nextTick(function () {
                    self.$refs.search.focus({ preventScroll: true });
                    self.ensureVisible();
                });
            },
            /* اللوحة ضمن التدفق: تُظهر كاملة داخل المحرّر المتمرّر وعلى الجوال. نعيد الاستدعاء بعد وصول النتائج لأن ارتفاعها يكبر بعد الفتح. */
            ensureVisible: function (behavior) {
                this.$root.scrollIntoView({ block: 'nearest', behavior: behavior || (reduced() ? 'auto' : 'smooth') });
            },
            close: function (refocus) {
                if (!this.open) { return; }
                this.open = false; this.req++; this.loading = false;
                if (this.timer) { clearTimeout(this.timer); this.timer = null; }
                if (refocus) { this.$refs.trigger.focus({ preventScroll: true }); }
            },

            /* تأخير قصير أثناء الكتابة؛ كل طلب جديد يُبطل ما قبله (رقم طلب) فلا تُعرض نتيجة قديمة فوق أحدث. */
            onInput: function () {
                var self = this;
                if (this.timer) { clearTimeout(this.timer); }
                this.loading = true;
                this.timer = setTimeout(function () { self.timer = null; self.fetchPage(0, true); }, 280);
            },
            /* مسح نص البحث فقط: لا يمسّ الاختيار الحالي ولا الربط. */
            clearTerm: function () {
                this.term = '';
                if (this.timer) { clearTimeout(this.timer); this.timer = null; }
                this.fetchPage(0, true);
                this.$refs.search.focus({ preventScroll: true });
            },
            /* فشل «تحميل المزيد» يبقي النتائج الظاهرة و next كما هما فنعيد المحاولة من حيث توقفنا؛ فشل الصفحة الأولى يعيد البحث من البداية. */
            retry: function () { var more = this.items.length > 0 && this.next !== null; this.fetchPage(more ? this.next : 0, !more); },
            loadMore: function () { if (this.next !== null && !this.loading) { this.fetchPage(this.next, false); } },
            /* عنصر مركّز يختفي (زر «المزيد»/«حاول مجددًا» بعد انتهاء عمله) يُفقد التركيز فيتعطّل Escape ولوحة المفاتيح؛ نعيده إلى حقل البحث. */
            keepFocus: function () {
                var self = this;
                window.requestAnimationFrame(function () {
                    var a = document.activeElement;
                    if (self.open && (!a || a === document.body || (self.$root.contains(a) && a.offsetParent === null))) { self.$refs.search.focus({ preventScroll: true }); }
                });
            },

            fetchPage: function (offset, replace) {
                var self = this, id = ++this.req, term = this.term.trim();
                this.loading = true; this.error = false;
                var call = this.$wire.$call(this.method, term, offset, this.value || '', this.ctx);
                var timeout = new Promise(function (_, rej) { setTimeout(function () { rej(new Error('timeout')); }, 15000); });
                Promise.race([call, timeout]).then(function (res) {
                    if (id !== self.req || !self.open) { return; }
                    res = res || {};
                    var incoming = Array.isArray(res.items) ? res.items : [];
                    if (replace) { self.items = incoming; } else {
                        var seen = {}; self.items.forEach(function (i) { seen[i.id] = true; });
                        self.items = self.items.concat(incoming.filter(function (i) { return !seen[i.id]; }));
                    }
                    self.total = res.total || self.items.length;
                    self.next = res.next === undefined ? null : res.next;
                    self.loading = false;
                    if (replace) {
                        var idx = -1;
                        self.rows.forEach(function (r, i) { if (idx < 0 && !r.none && r.id === self.value) { idx = i; } });
                        self.active = idx >= 0 ? idx : (self.rows.length ? (self.term.trim() === '' && self.allowNone && self.rows.length > 1 ? 1 : 0) : -1);
                    }
                    self.keepFocus();
                    self.$nextTick(function () { if (self.open) { self.ensureVisible('auto'); } });
                }).catch(function () {
                    if (id !== self.req) { return; }
                    /* فشل بحث جديد: لا نترك نتائج نص سابق تبدو كأنها نتيجة هذا البحث. */
                    if (replace) { self.items = []; self.next = null; self.total = 0; self.active = -1; }
                    self.loading = false; self.error = true;
                    self.keepFocus();
                });
            },

            pick: function (row) {
                if (!row) { return; }
                if (row.none) { this.setValue('', null); } else { this.setValue(row.id, row); }
                this.close(true);
            },
            /* «مسح الاختيار» (من المشهد/القصة) فعل مستقل عن مسح نص البحث: يضبط القيمة «بلا» ولا يحفظ شيئًا حتى زر الحفظ. */
            remove: function () { this.setValue('', null); if (this.open) { this.close(false); } this.$refs.trigger.focus({ preventScroll: true }); },
            setValue: function (v, item) {
                if (v === this.value) { return; }
                this.value = v; this.picked = item;
                this.$refs.input.value = v;
                /* حدث input حقيقي: يلتقطه Livewire (wire:model) ويلتقطه تتبّع «تغييرات غير محفوظة» في الصفحة. */
                this.$refs.input.dispatchEvent(new Event('input', { bubbles: true }));
            },

            onKey: function (e) {
                var n = this.rows.length;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    /* آخر نتيجة + المزيد متاح: نحمّل الصفحة التالية بدل الدوران إلى الأول (مسار لوحة المفاتيح لـ«تحميل المزيد»). */
                    if (n && this.active === n - 1 && this.next !== null && !this.loading) { this.loadMore(); return; }
                    if (n) { this.active = (this.active + 1) % n; this.reveal(); }
                }
                else if (e.key === 'ArrowUp') { e.preventDefault(); if (n) { this.active = this.active <= 0 ? n - 1 : this.active - 1; this.reveal(); } }
                else if (e.key === 'Enter') { e.preventDefault(); if (this.active >= 0 && this.rows[this.active]) { this.pick(this.rows[this.active]); } }
            },
            reveal: function () {
                var self = this;
                this.$nextTick(function () {
                    var el = self.activeId() ? document.getElementById(self.activeId()) : null;
                    if (el) { el.scrollIntoView({ block: 'nearest' }); }
                });
            }
        };
    };
})();
</script>
@endonce
