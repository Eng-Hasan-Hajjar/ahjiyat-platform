/**
 * E27: البحث العام - تراكب اقتراحات فورية (combobox/listbox) فوق صفحة /search (نموذج GET يعمل بدون JavaScript).
 *  - النصوص تُعرض بـx-text (textContent) أبدًا لا innerHTML؛ والروابط تُقبل فقط إن كانت مسارًا نسبيًا للموقع نفسه.
 *  - تأخير 220ms + إلغاء الطلب السابق (AbortController) + تجاهل الردود القديمة. 429 = رسالة لطيفة وإيقاف مؤقت. لا تخزين لنص البحث (لا localStorage ولا سجل).
 *  - لوحة المفاتيح: ↑/↓ تنقّل، Enter يفتح المحدَّد أو صفحة البحث الكاملة، Esc يغلق، Tab محصور بالحوار. اختصار «/» أو Ctrl/⌘+K للفتح.
 */
const DEBOUNCE_MS = 220;
const BACKOFF_MS = 5000;

// مسار نسبي للموقع نفسه فقط: يبدأ بـ«/» واحدة، بلا «//» ولا «\» ولا أي مسافة/محرف تحكّم (المتصفح يحذف التبويب والسطر الجديد من الروابط فيحوّل "/<tab>/x" إلى "//x").
const SAFE_PATH = /^\/(?![\/\\])[^\s\\\u0000-\u001f\u007f]*$/u;

export function safePath(url) {
    return typeof url === 'string' && SAFE_PATH.test(url) ? url : null;
}

function isEditable(el) {
    if (!el) return false;
    const tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable === true;
}

export default function globalSearch() {
    return {
        open: false,
        q: '',
        state: 'idle', // idle | short | loading | ok | empty | error | throttled
        groups: [],
        note: null,
        allUrl: null,
        active: -1,
        status: '',
        composing: false,
        timer: null,
        controller: null,
        blockedUntil: 0,
        opener: null,

        init() {
            this.cfg = {
                suggest: this.$el.dataset.suggestUrl,
                page: this.$el.dataset.searchUrl,
                min: parseInt(this.$el.dataset.min || '2', 10),
            };
        },

        get flat() {
            const items = [];
            this.groups.forEach((g) => g.items.forEach((item) => items.push(item)));
            return items;
        },

        shortcut(event) {
            const slash = event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey && !isEditable(event.target);
            const palette = (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k';

            if (!this.open && (slash || palette)) {
                event.preventDefault();
                this.show();
            }
        },

        show() {
            if (this.open) return;
            this.opener = document.activeElement;
            this.open = true;
            document.documentElement.classList.add('overflow-hidden');
            this.$nextTick(() => this.$refs.input && this.$refs.input.focus());
        },

        close() {
            if (!this.open) return;
            this.open = false;
            this.cancel();
            document.documentElement.classList.remove('overflow-hidden');
            const opener = this.opener;
            this.opener = null;
            this.$nextTick(() => opener && typeof opener.focus === 'function' && opener.focus());
        },

        cancel() {
            clearTimeout(this.timer);
            if (this.controller) this.controller.abort();
            this.controller = null;
        },

        reset(state = 'idle') {
            this.groups = [];
            this.note = null;
            this.allUrl = null;
            this.active = -1;
            this.state = state;
            this.status = '';
        },

        onInput() {
            this.cancel();
            if (this.composing) return;

            const term = this.q.trim();
            if (term === '') return this.reset('idle');
            if (term.length < this.cfg.min) return this.reset('short');

            this.state = 'loading';
            this.timer = setTimeout(() => this.fetchSuggestions(term), DEBOUNCE_MS);
        },

        async fetchSuggestions(term) {
            if (Date.now() < this.blockedUntil) return this.reset('throttled');

            this.controller = new AbortController();
            const { signal } = this.controller;

            try {
                const response = await fetch(this.cfg.suggest + '?q=' + encodeURIComponent(term), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal,
                });

                if (response.status === 429) {
                    this.blockedUntil = Date.now() + BACKOFF_MS;
                    return this.reset('throttled');
                }
                if (!response.ok) return this.reset('error');

                const data = await response.json();
                if (term !== this.q.trim()) return; // ردّ قديم

                this.groups = (data.groups || [])
                    .map((g) => ({
                        type: g.type,
                        label: g.label,
                        more: safePath(g.more_url),
                        items: (g.items || []).map((i) => ({ ...i, url: safePath(i.url) })).filter((i) => i.url),
                    }))
                    .filter((g) => g.items.length);
                this.note = data.note || null;
                this.allUrl = safePath(data.all_url) || this.cfg.page;
                this.active = -1;
                this.state = this.groups.length ? 'ok' : data.state === 'too_short' ? 'short' : 'empty';
                this.status = this.groups.length ? this.flat.length + ' اقتراحات متاحة' : 'لا نتائج';
            } catch (error) {
                if (error && error.name === 'AbortError') return;
                this.reset('error');
            }
        },

        move(step) {
            const count = this.flat.length;
            if (!count) return;
            this.active = (this.active + step + count + (this.active === -1 && step < 0 ? 1 : 0)) % count;
            this.$nextTick(() => {
                const el = document.getElementById('gs-opt-' + this.active);
                if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
            });
        },

        enter(event) {
            event.preventDefault();
            const item = this.flat[this.active];

            if (item) {
                window.location.assign(item.url);
            } else {
                this.go();
            }
        },

        // بلا عنصر محدَّد: صفحة البحث الكاملة (الصفحة نفسها نموذج GET يعمل بلا JavaScript).
        go() {
            const term = this.q.trim();
            window.location.assign(term === '' ? this.cfg.page : this.cfg.page + '?q=' + encodeURIComponent(term));
        },

        trap(event) {
            const nodes = Array.from(this.$refs.panel.querySelectorAll('input, button, a[href]')).filter((n) => n.offsetParent !== null && n.tabIndex !== -1);
            if (!nodes.length) return;
            const first = nodes[0];
            const last = nodes[nodes.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },

        get activeId() {
            return this.active >= 0 ? 'gs-opt-' + this.active : null;
        },

        indexOf(groupIndex, itemIndex) {
            let n = 0;
            for (let g = 0; g < groupIndex; g++) n += this.groups[g].items.length;
            return n + itemIndex;
        },
    };
}
