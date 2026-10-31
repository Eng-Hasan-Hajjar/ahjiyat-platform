/**
 * E45: لوحة «تطوّر AI» (المساعد الذكي لمنصة الأحجيات). تُحمَّل كسولًا عند أول تفاعل مع الزر العائم (لا تكلفة على أي صفحة قبل ذلك).
 *  - كل نص (سؤال المستخدم، ردّ المساعد، المصادر، الأخطاء) يُدرَج بـtextContent/createTextNode حصرًا: لا innerHTML ولا insertAdjacentHTML ولا eval.
 *  - الروابط تُقبل فقط مسارًا نسبيًا للموقع نفسه (safePath من البحث العام) - ويُعاد فحصها هنا حتى لو فحصها الخادم.
 *  - نقاط الاتصال (state/messages/history/feedback) من data-* يطبعها الخادم؛ لا عناوين خارجية ولا مفاتيح في الواجهة.
 *  - SSE اختياري: يُستعمل فقط إن أعلنت الحالة streaming=true؛ وإلا طلب JSON عادي. أحداث delta/reset/replace/done/error.
 *  - لوحة المفاتيح: Enter يرسل، Shift+Enter سطر جديد، Esc يغلق ويعيد التركيز للزر، Tab محصور بالحوار على الجوال، تركيب IME لا يُرسَل.
 *  - لا تخزين محلي لأي نص (لا localStorage ولا sessionStorage): السجل يأتي من الخادم (للأعضاء) أو جلسة مؤقتة (للضيف).
 */
import { safePath } from '../search.js';

const SVG_NS = 'http://www.w3.org/2000/svg';
const MOBILE = '(max-width: 767.98px)';
const REQUEST_TIMEOUT_MS = 70000;

const ICONS = {
    x: 'M6 18L18 6M6 6l12 12',
    send: 'M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5',
    trash: 'M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0',
    stop: 'M7 7h10v10H7z',
};

const REASONS = [['inaccurate', 'غير دقيق'], ['incomplete', 'ناقص'], ['unsafe', 'غير آمن'], ['off_topic', 'خارج الموضوع'], ['other', 'سبب آخر']];

const TEXT = {
    title: 'تطوّر AI',
    subtitle: 'المساعد الذكي لمنصة الأحجيات',
    welcomeTitle: 'مرحبًا، أنا تطوّر',
    welcomeBody: 'أجيب عن أسئلتك حول المنصة من دليلها وبياناتها الحيّة. لا أكشف حلول الأحجيات ولا أنفّذ عمليات نيابة عنك.',
    guideMode: 'وضع الدليل: أبحث لك في دليل المنصة مباشرة.',
    guestNote: 'للأسئلة عن حسابك وتقدّمك ',
    guestLink: 'سجّل الدخول',
    placeholder: 'اسأل عن المنصة…',
    send: 'إرسال',
    stop: 'إيقاف',
    close: 'إغلاق',
    clear: 'حذف المحادثة',
    clearAsk: 'حذف كل محادثاتك مع تطوّر؟',
    clearYes: 'حذف',
    clearNo: 'إلغاء',
    foot: 'قد يخطئ تطوّر AI. تأكّد من المعلومات المهمة في صفحات المنصة.',
    helpLink: 'دليل المنصة',
    copy: 'نسخ',
    copied: 'تم النسخ',
    up: 'مفيد',
    down: 'غير مفيد',
    thanks: 'شكرًا لتقييمك.',
    why: 'ما المشكلة؟',
    sources: 'المصادر',
    errNetwork: 'تعذر الاتصال بتطوّر. تحقّق من الاتصال ثم أعد المحاولة.',
    errSession: 'انتهت صلاحية الجلسة. حدّث الصفحة ثم أعد المحاولة.',
    errBusy: 'ما زال ردّ سؤالك السابق قيد المعالجة. انتظر لحظة ثم أعد المحاولة.',
    errLimit: 'تجاوزت الحد المسموح مؤقتًا. حاول بعد لحظات.',
    errGeneric: 'تعذر إكمال الردّ الآن. أعد المحاولة بعد قليل.',
    errUnavailable: 'المساعد غير متاح حاليًا.',
    errClear: 'تعذر حذف المحادثة الآن. أعد المحاولة.',
    errLoad: 'تعذر تحميل المساعد. أعد المحاولة.',
};

function h(tag, attrs, ...kids) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs || {})) {
        if (value === null || value === undefined || value === false) continue;
        if (key === 'class') node.className = value;
        else if (key === 'text') node.textContent = value;
        else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
        else node.setAttribute(key, value === true ? '' : String(value));
    }
    for (const kid of kids.flat()) {
        if (kid !== null && kid !== undefined && kid !== false) node.append(kid);
    }
    return node;
}

function icon(name) {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', name === 'stop' ? 'currentColor' : 'none');
    svg.setAttribute('stroke', name === 'stop' ? 'none' : 'currentColor');
    svg.setAttribute('stroke-width', '1.7');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const path = document.createElementNS(SVG_NS, 'path');
    path.setAttribute('d', ICONS[name]);
    svg.append(path);
    return svg;
}

/** نص عادي ← **غامق** فقط (بلا أي علامات أخرى). يُدرَج عبر عقد نصية. */
function inline(parent, text) {
    String(text).split('**').forEach((part, i) => {
        if (part === '') return;
        parent.append(i % 2 === 1 ? h('strong', { text: part }) : document.createTextNode(part));
    });
}

/** يبني فقرات وقوائم من نص الردّ (أسطر "- " أو "1. ") دون أي HTML. */
function renderRich(container, text) {
    container.replaceChildren();
    let list = null;
    let listType = null;

    const flush = () => {
        list = null;
        listType = null;
    };

    for (const raw of String(text).replace(/\r/g, '').split('\n')) {
        const line = raw.trim();
        if (line === '') {
            flush();
            continue;
        }
        const bullet = line.match(/^[-•*]\s+(.*)$/u);
        const numbered = line.match(/^\d+[.)]\s+(.*)$/u);
        if (bullet || numbered) {
            const type = bullet ? 'ul' : 'ol';
            if (list === null || listType !== type) {
                list = h(type);
                listType = type;
                container.append(list);
            }
            const li = h('li');
            inline(li, (bullet || numbered)[1]);
            list.append(li);
        } else {
            flush();
            const p = h('p');
            inline(p, line);
            container.append(p);
        }
    }
}

export function mount(root) {
    const launcher = root.querySelector('[data-tatawwur-launcher]');
    const cfg = {
        state: root.dataset.stateUrl,
        messages: root.dataset.messagesUrl,
        history: root.dataset.historyUrl,
        feedback: root.dataset.feedbackUrl,
        csrf: root.dataset.csrf,
    };
    const mq = window.matchMedia(MOBILE);

    const s = {
        loaded: false,
        loading: null,
        open: false,
        sending: false,
        abort: null,
        meta: null,
        conversation: null,
        stick: true,
        messages: [],
    };

    // ---------- هيكل اللوحة ----------
    const mark = launcher.querySelector('svg').cloneNode(true);
    const titleId = 'tw-title';
    const closeBtn = h('button', { type: 'button', class: 'tw-icon-btn', 'aria-label': TEXT.close, onclick: () => api.close() }, icon('x'));
    const clearBtn = h('button', { type: 'button', class: 'tw-icon-btn', 'aria-label': TEXT.clear, title: TEXT.clear, onclick: () => askClear() }, icon('trash'));
    const head = h('header', { class: 'tw-head' }, mark, h('div', { class: 'tw-head-text' }, h('h2', { id: titleId, class: 'tw-title', text: TEXT.title }), h('p', { class: 'tw-sub', text: TEXT.subtitle })), clearBtn, closeBtn);
    const note = h('p', { class: 'tw-note', hidden: true });
    const log = h('div', { class: 'tw-log', role: 'log', 'aria-live': 'polite', 'aria-relevant': 'additions', tabindex: '0', 'aria-label': 'محادثة تطوّر AI' });
    const confirmBar = h('div', { class: 'tw-confirm', role: 'alertdialog', 'aria-label': TEXT.clearAsk, hidden: true });
    const input = h('textarea', { class: 'tw-input', rows: '1', placeholder: TEXT.placeholder, 'aria-label': 'اكتب سؤالك', enterkeyhint: 'send', autocomplete: 'off', dir: 'auto' });
    const sendBtn = h('button', { type: 'button', class: 'tw-send', 'aria-label': TEXT.send, title: TEXT.send }, icon('send'));
    const counter = h('span', { class: 'tw-count', 'aria-hidden': 'true' });
    const helpLink = h('a', { href: '/help', class: 'tw-src', text: TEXT.helpLink });
    const compose = h('form', { class: 'tw-compose', novalidate: true }, input, sendBtn);
    const foot = h('div', { class: 'tw-foot' }, h('span', { text: TEXT.foot }), counter);
    const panel = h('section', { class: 'tw-panel', role: 'dialog', 'aria-modal': 'false', 'aria-labelledby': titleId, hidden: true }, head, note, log, confirmBar, compose, foot);
    root.append(panel);

    // ---------- أدوات ----------
    const near = () => log.scrollHeight - log.scrollTop - log.clientHeight < 90;
    const toBottom = (force = false) => {
        if (force || s.stick) log.scrollTop = log.scrollHeight;
    };
    log.addEventListener('scroll', () => {
        s.stick = near();
    });

    const fit = () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 136) + 'px';
        const max = s.meta ? s.meta.max_chars : 600;
        const n = input.value.length;
        counter.textContent = n > max * 0.7 ? n + '/' + max : '';
        counter.dataset.warn = n > max ? 'true' : 'false';
        sendBtn.disabled = !s.sending && (input.value.trim() === '' || n > max);
    };

    const focusables = () => [...panel.querySelectorAll('a[href], button:not([disabled]), textarea, [tabindex="0"]')].filter((el) => !el.hidden && el.offsetParent !== null);

    function setSending(on) {
        s.sending = on;
        sendBtn.replaceChildren(icon(on ? 'stop' : 'send'));
        sendBtn.setAttribute('aria-label', on ? TEXT.stop : TEXT.send);
        sendBtn.title = on ? TEXT.stop : TEXT.send;
        panel.setAttribute('aria-busy', on ? 'true' : 'false');
        fit();
    }

    // ---------- الرسائل ----------
    function sourceChips(sources) {
        const links = (Array.isArray(sources) ? sources : [])
            .map((src) => ({ label: String(src && src.label ? src.label : ''), url: safePath(src && src.url) }))
            .filter((src) => src.url && src.label !== '')
            .slice(0, 6);
        if (links.length === 0) return null;
        return h('div', { class: 'tw-srcs', role: 'group', 'aria-label': TEXT.sources }, links.map((src) => h('a', { class: 'tw-src', href: src.url, text: src.label })));
    }

    function bubble(role, text, extra) {
        const body = h('div', { class: 'tw-bubble tw-bubble--' + role + (extra && extra.error ? ' tw-bubble--error' : '') });
        if (role === 'assistant') renderRich(body, text);
        else body.textContent = text;
        return body;
    }

    function addMessage(m) {
        const wrap = h('article', { class: 'tw-msg tw-msg--' + m.role });
        const body = bubble(m.role, m.text, m);
        wrap.append(body);
        const entry = { wrap, body, data: m };

        if (m.role === 'assistant') finishAssistant(entry);

        log.append(wrap);
        s.messages.push(entry);
        toBottom(true);

        return entry;
    }

    function finishAssistant(entry) {
        entry.wrap.querySelectorAll('.tw-srcs, .tw-acts, .tw-reasons').forEach((n) => n.remove());
        const m = entry.data;
        const chips = sourceChips(m.sources);
        const acts = h('div', { class: 'tw-acts' });
        const copy = h('button', { type: 'button', class: 'tw-act', text: TEXT.copy });
        copy.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(entry.body.innerText || entry.body.textContent || '');
                copy.textContent = TEXT.copied;
                setTimeout(() => (copy.textContent = TEXT.copy), 1600);
            } catch (e) {
                // الحافظة غير متاحة (سياق غير آمن/رفض الإذن): لا شيء يُكسَر.
            }
        });
        acts.append(copy);

        const reasons = h('div', { class: 'tw-reasons', hidden: true, role: 'group', 'aria-label': TEXT.why });

        if (m.id && s.meta && s.meta.can_rate) {
            const up = h('button', { type: 'button', class: 'tw-act', 'aria-pressed': m.rating === 'up' ? 'true' : 'false', text: TEXT.up });
            const down = h('button', { type: 'button', class: 'tw-act', 'aria-pressed': m.rating === 'down' ? 'true' : 'false', text: TEXT.down });
            const press = (rating) => {
                up.setAttribute('aria-pressed', rating === 'up' ? 'true' : 'false');
                down.setAttribute('aria-pressed', rating === 'down' ? 'true' : 'false');
                reasons.hidden = rating !== 'down';
            };
            up.addEventListener('click', () => rate(m, 'up', null).then((ok) => ok && press('up')));
            down.addEventListener('click', () => rate(m, 'down', null).then((ok) => ok && press('down')));
            REASONS.forEach(([code, label]) => {
                reasons.append(h('button', {
                    type: 'button',
                    class: 'tw-chip-btn',
                    text: label,
                    onclick: () => rate(m, 'down', code).then((ok) => {
                        if (ok) reasons.replaceChildren(h('span', { class: 'tw-sub', role: 'status', text: TEXT.thanks }));
                    }),
                }));
            });
            acts.append(up, down);
            reasons.hidden = m.rating !== 'down';
        }

        entry.wrap.append(...[chips, acts, reasons].filter(Boolean));
    }

    function typing() {
        return h('div', { class: 'tw-bubble tw-bubble--assistant tw-typing', role: 'status', 'aria-label': 'تطوّر يكتب' }, h('i'), h('i'), h('i'));
    }

    // ---------- الحالة الفارغة ----------
    function renderEmpty() {
        log.replaceChildren();
        s.messages = [];
        const list = h('ul', { class: 'tw-suggest', 'aria-label': 'أسئلة مقترحة' });
        (s.meta ? s.meta.suggestions : []).slice(0, 8).forEach((q) => {
            list.append(h('li', {}, h('button', { type: 'button', text: q, onclick: () => send(q) })));
        });
        log.append(h('div', { class: 'tw-empty' }, mark.cloneNode(true), h('h3', { text: TEXT.welcomeTitle }), h('p', { text: TEXT.welcomeBody }), list, helpLink));
    }

    function applyMeta() {
        const guide = s.meta.mode === 'guide';
        const guest = s.meta.audience !== 'member';
        note.replaceChildren();
        if (guide) note.append(document.createTextNode(TEXT.guideMode + (guest ? ' ' : '')));
        if (guest) {
            note.append(document.createTextNode(TEXT.guestNote));
            const login = safePath(s.meta.urls && s.meta.urls.login);
            note.append(login ? h('a', { href: login, text: TEXT.guestLink }) : document.createTextNode(TEXT.guestLink));
            note.append(document.createTextNode('.'));
        }
        note.hidden = !(guide || guest);
        helpLink.setAttribute('href', safePath(s.meta.urls && s.meta.urls.help) || '/help');
        input.maxLength = Math.max(50, s.meta.max_chars + 200);
        s.conversation = s.meta.conversation_id || null;
    }

    // ---------- الشبكة ----------
    function headers(extra) {
        return Object.assign({ 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, extra || {});
    }

    async function loadState() {
        if (s.loaded) return true;
        s.loading ??= (async () => {
            try {
                const res = await fetch(cfg.state, { headers: headers(), credentials: 'same-origin' });
                if (res.status === 404) {
                    root.remove();
                    return false;
                }
                if (!res.ok) throw new Error('state');
                s.meta = await res.json();
                applyMeta();
                const items = Array.isArray(s.meta.messages) ? s.meta.messages : [];
                if (items.length === 0) renderEmpty();
                else {
                    log.replaceChildren();
                    items.forEach((m) => addMessage({ role: m.role === 'assistant' ? 'assistant' : 'user', text: String(m.text || ''), sources: m.sources, id: m.id, rating: m.rating }));
                }
                s.loaded = true;
                return true;
            } catch (e) {
                log.replaceChildren(h('div', { class: 'tw-empty' }, h('p', { role: 'alert', text: TEXT.errLoad })));
                return false;
            } finally {
                s.loading = null;
            }
        })();
        return s.loading;
    }

    async function failureText(res) {
        if (res.status === 419) return TEXT.errSession;
        if (res.status === 409) return TEXT.errBusy;
        if (res.status === 404) return TEXT.errUnavailable;
        try {
            const data = await res.json();
            if (data && typeof data.message === 'string' && data.message.trim() !== '') return data.message.slice(0, 400);
        } catch (e) {
            // جسم غير JSON.
        }
        return res.status === 429 ? TEXT.errLimit : TEXT.errGeneric;
    }

    async function readStream(res, entry) {
        const reader = res.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer = '';
        let text = '';
        let done = null;
        let failed = false;

        const apply = (event, data) => {
            if (event === 'delta') {
                text += String(data.text || '');
                renderRich(entry.body, text);
                toBottom();
            } else if (event === 'reset') {
                text = '';
                entry.body.replaceChildren(typing());
            } else if (event === 'replace') {
                text = String(data.text || '');
                renderRich(entry.body, text);
                toBottom();
            } else if (event === 'done') {
                done = data;
            } else if (event === 'error') {
                failed = true;
                entry.body.classList.add('tw-bubble--error');
                renderRich(entry.body, String(data.message || TEXT.errGeneric));
            }
        };

        for (;;) {
            const { value, done: end } = await reader.read();
            if (value) buffer += decoder.decode(value, { stream: !end });
            let cut;
            while ((cut = buffer.indexOf('\n\n')) !== -1) {
                const frame = buffer.slice(0, cut);
                buffer = buffer.slice(cut + 2);
                let event = 'message';
                let payload = '';
                frame.split('\n').forEach((line) => {
                    if (line.startsWith('event:')) event = line.slice(6).trim();
                    else if (line.startsWith('data:')) payload += line.slice(5).trim();
                });
                if (payload === '') continue;
                try {
                    apply(event, JSON.parse(payload));
                } catch (e) {
                    // إطار تالف: يُتجاهل ويستمر البث.
                }
            }
            if (end) break;
        }

        return { done, failed, text };
    }

    async function send(raw) {
        const text = String(raw ?? input.value).trim();
        if (s.sending || text === '') return;
        const max = s.meta ? s.meta.max_chars : 600;
        if (text.length > max) return;
        if (!(await loadState())) return;

        if (s.messages.length === 0) log.replaceChildren();

        addMessage({ role: 'user', text });
        input.value = '';
        fit();

        const entry = addMessage({ role: 'assistant', text: '' });
        entry.body.replaceChildren(typing());
        entry.wrap.querySelectorAll('.tw-srcs, .tw-acts, .tw-reasons').forEach((n) => n.remove());
        s.stick = true;
        setSending(true);

        const controller = new AbortController();
        s.abort = controller;
        const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
        const stream = Boolean(s.meta && s.meta.streaming) && typeof TextDecoder !== 'undefined';

        try {
            const res = await fetch(cfg.messages, {
                method: 'POST',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: headers({ 'Content-Type': 'application/json', Accept: stream ? 'text/event-stream' : 'application/json' }),
                body: JSON.stringify({ message: text, conversation: s.conversation, stream }),
            });

            if (!res.ok) {
                entry.body.classList.add('tw-bubble--error');
                renderRich(entry.body, await failureText(res));
                if (res.status === 404) root.querySelector('[data-tatawwur-launcher]')?.remove();
                return;
            }

            let payload = null;

            if ((res.headers.get('content-type') || '').includes('text/event-stream') && res.body) {
                const out = await readStream(res, entry);
                if (out.failed) return;
                payload = out.done || { text: out.text, sources: [], outcome: 'answered' };
            } else {
                payload = await res.json();
            }

            entry.data = { role: 'assistant', text: String(payload.text || ''), sources: payload.sources, id: payload.id || null, rating: null };
            renderRich(entry.body, entry.data.text);
            if (payload.outcome === 'error' || payload.outcome === 'limited') entry.body.classList.add('tw-bubble--error');
            finishAssistant(entry);
            if (payload.conversation_id) s.conversation = payload.conversation_id;
        } catch (e) {
            entry.body.classList.add('tw-bubble--error');
            renderRich(entry.body, e && e.name === 'AbortError' ? TEXT.errGeneric : TEXT.errNetwork);
        } finally {
            clearTimeout(timer);
            s.abort = null;
            setSending(false);
            toBottom();
            if (s.open && !mq.matches) input.focus();
        }
    }

    async function rate(message, rating, reason) {
        try {
            const res = await fetch(cfg.feedback, {
                method: 'POST',
                credentials: 'same-origin',
                headers: headers({ 'Content-Type': 'application/json' }),
                body: JSON.stringify({ message: message.id, rating, reason }),
            });
            if (res.ok) message.rating = rating;
            return res.ok;
        } catch (e) {
            return false;
        }
    }

    // ---------- حذف السجل ----------
    function askClear() {
        confirmBar.replaceChildren(
            h('span', { text: TEXT.clearAsk }),
            h('span', { class: 'tw-reasons' },
                h('button', { type: 'button', class: 'tw-chip-btn', text: TEXT.clearYes, onclick: () => doClear() }),
                h('button', { type: 'button', class: 'tw-chip-btn', text: TEXT.clearNo, onclick: () => closeConfirm() })),
        );
        confirmBar.hidden = false;
        confirmBar.querySelector('button')?.focus();
    }

    function closeConfirm() {
        confirmBar.hidden = true;
        clearBtn.focus();
    }

    async function doClear() {
        try {
            const res = await fetch(cfg.history, { method: 'DELETE', credentials: 'same-origin', headers: headers() });
            if (!res.ok) throw new Error('clear');
            s.conversation = null;
            renderEmpty();
            confirmBar.hidden = true;
            clearBtn.focus();
        } catch (e) {
            confirmBar.replaceChildren(h('span', { role: 'alert', text: TEXT.errClear }), h('button', { type: 'button', class: 'tw-chip-btn', text: TEXT.clearNo, onclick: () => closeConfirm() }));
        }
    }

    // ---------- الفتح والإغلاق ----------
    const api = {
        async open() {
            if (s.open) {
                input.focus();
                return;
            }
            s.open = true;
            panel.hidden = false;
            launcher.setAttribute('aria-expanded', 'true');
            panel.setAttribute('aria-modal', mq.matches ? 'true' : 'false');
            if (mq.matches) document.documentElement.classList.add('tw-lock');
            const ok = await loadState();
            if (!s.open) return;
            if (ok) {
                fit();
                toBottom(true);
                input.focus({ preventScroll: true });
            }
        },
        close() {
            if (!s.open) return;
            s.open = false;
            panel.hidden = true;
            confirmBar.hidden = true;
            launcher.setAttribute('aria-expanded', 'false');
            document.documentElement.classList.remove('tw-lock');
            launcher.focus();
        },
        toggle() {
            return s.open ? api.close() : api.open();
        },
    };

    // ---------- الأحداث ----------
    sendBtn.addEventListener('click', () => {
        if (s.sending) s.abort?.abort();
        else send();
    });
    compose.addEventListener('submit', (e) => {
        e.preventDefault();
        send();
    });
    input.addEventListener('input', fit);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
            e.preventDefault();
            send();
        }
    });
    panel.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            e.stopPropagation();
            if (!confirmBar.hidden) closeConfirm();
            else api.close();
            return;
        }
        if (e.key === 'Tab' && mq.matches) {
            const items = focusables();
            if (items.length === 0) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });
    mq.addEventListener?.('change', () => {
        panel.setAttribute('aria-modal', s.open && mq.matches ? 'true' : 'false');
        if (!mq.matches) document.documentElement.classList.remove('tw-lock');
        else if (s.open) document.documentElement.classList.add('tw-lock');
    });

    fit();

    return api;
}
