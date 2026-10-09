/**
 * E21: مكوّن غرفة الدردشة (Alpine). **كل نص يُعرض بـx-text** (تهريب تلقائي): لا innerHTML ولا x-html أبدًا (نص عادي فقط).
 * الخادم صاحب السلطة: العميل يرسل النص وحده؛ الأعلام (تعديل/حذف/إبلاغ/إخفاء) تأتي من الخادم، والبثّ اللحظي يُكمّل HTTP ولا يخلق رسالة.
 * تفادي التكرار: الرسالة تصل مرتين للمرسل (استجابة HTTP + بثّ) فيُدمجان بمعرّفها (upsert). المحظورون بالعامة يُرشَّحون بقائمة تأتي من الخادم.
 */
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function api(method, url, data) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: data === undefined ? undefined : JSON.stringify(data),
    });

    let json = null;

    try {
        json = await response.json();
    } catch (e) {
        // استجابة بلا JSON
    }

    if (!response.ok) {
        const error = new Error(json?.message ?? 'تعذّر تنفيذ الطلب.');
        error.status = response.status;
        throw error;
    }

    return json;
}

export default function chatRoom(cfg) {
    return {
        cfg,
        messages: cfg.messages.slice(),
        hasMore: cfg.has_more,
        body: '',
        sending: false,
        loadingOlder: false,
        error: '',
        notice: '',
        editingId: null,
        editBody: '',
        reporting: null,
        reportCategory: cfg.categories?.[0] ?? 'spam',
        reportDetails: '',
        subscribedTo: null,
        lastReadSent: 0,
        // E23 (عرض فقط): قائمة الإجراءات، مؤشر الرسائل الجديدة، موضع التمرير.
        menuId: null,
        menuUp: false,
        unseen: 0,
        atBottom: true,

        init() {
            this.$nextTick(() => this.scrollBottom());
            this.subscribe();
            this.markRead();
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    this.markRead();
                }
            });
            // E23: المؤلِّف يتمدّد مع النص ويعود لسطر واحد بعد الإرسال (تفريغ body).
            this.$watch('body', () => this.$nextTick(() => this.grow()));
        },

        // ---------- بثّ لحظي (اختياري)
        subscribe() {
            if (!window.Echo || !this.cfg.channel || this.subscribedTo === this.cfg.channel) {
                return;
            }

            this.subscribedTo = this.cfg.channel;
            window.Echo.private(this.cfg.channel)
                .listen('.message.sent', (event) => this.receive(event, true))
                .listen('.message.updated', (event) => this.receive(event, false));
        },

        receive(event, isNew) {
            if (event?.sender && this.cfg.hidden_sender_ids.includes(event.sender.public_id)) {
                return;
            }

            // E23: القرار «قريب من الأسفل؟» يُؤخذ قبل إضافة الرسالة (إضافتها تزيد الارتفاع)؛ لا قفز لمن يقرأ القديم، ويظهر زر «رسائل جديدة».
            const wasNear = this.isNearBottom();
            const fromMe = !!event?.sender && event.sender.public_id === this.cfg.viewer.public_id;

            this.upsert(this.decorate(event));

            if (isNew) {
                this.$nextTick(() => this.afterIncoming(wasNear || fromMe, fromMe));
                this.markRead();
            }
        },

        /** أعلام المشاهد لرسالة وصلت بالبثّ (الخادم يحدّد الصلاحية الفعلية عند التنفيذ دائمًا). */
        decorate(m) {
            const mine = !!m.sender && m.sender.public_id === this.cfg.viewer.public_id;
            const withinWindow = Date.now() - new Date(m.created_at).getTime() <= this.cfg.limits.edit_window_minutes * 60000;

            return {
                ...m,
                mine,
                can_edit: mine && m.state === 'normal' && withinWindow && this.cfg.caps.send,
                can_delete: mine && m.state !== 'deleted',
                can_report: !mine && m.state === 'normal' && !!m.sender,
                can_hide: this.cfg.caps.moderate && m.state === 'normal' && !mine,
                can_restore: this.cfg.caps.moderate && m.state === 'hidden',
                moderation_body: this.messages.find((x) => x.id === m.id)?.moderation_body ?? null,
            };
        },

        upsert(m) {
            const index = this.messages.findIndex((x) => x.id === m.id);

            if (index >= 0) {
                this.messages.splice(index, 1, m);
            } else {
                this.messages.push(m);
                this.messages.sort((a, b) => a.id - b.id);
            }
        },

        // ---------- إرسال
        async send() {
            const text = this.body.trim();

            if (!text || this.sending || !this.cfg.caps.send) {
                return;
            }

            this.sending = true;
            this.error = '';

            try {
                const result = await api('POST', this.cfg.endpoints.send, { body: text });
                this.adopt(result.thread);
                this.body = '';
                this.upsert(result.message);
                this.$nextTick(() => {
                    this.scrollBottom();
                    this.refocusComposer();
                });
                this.markRead();
            } catch (e) {
                this.error = e.message;
            } finally {
                this.sending = false;
            }
        },

        /** محادثة مباشرة جديدة: الغرفة أُنشئت عند أول إرسال، فنتبنّى معرّفها وقناتها. */
        adopt(thread) {
            if (!thread || this.cfg.thread) {
                return;
            }

            this.cfg.thread = thread.public_id;
            this.cfg.channel = thread.channel;
            this.cfg.endpoints.older = thread.older;
            this.cfg.endpoints.read = thread.read;
            this.subscribe();
        },

        // ---------- أقدم / قراءة
        async loadOlder() {
            if (this.loadingOlder || !this.hasMore || !this.cfg.endpoints.older || this.messages.length === 0) {
                return;
            }

            this.loadingOlder = true;
            const scroller = this.$refs.scroller;
            const before = scroller.scrollHeight;

            try {
                const result = await api('GET', `${this.cfg.endpoints.older}?before=${this.messages[0].id}`);
                result.messages.forEach((m) => this.upsert(m));
                this.hasMore = result.has_more;
                this.$nextTick(() => (scroller.scrollTop = scroller.scrollHeight - before));
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loadingOlder = false;
            }
        },

        async markRead() {
            const last = this.messages.length ? this.messages[this.messages.length - 1].id : 0;

            if (!this.cfg.endpoints.read || !last || last <= this.lastReadSent || document.hidden) {
                return;
            }

            this.lastReadSent = last;

            try {
                await api('POST', this.cfg.endpoints.read, { up_to: last });
            } catch (e) {
                // علامة القراءة ثانوية: فشلها لا يقطع الدردشة
            }
        },

        // ---------- عمليات الرسالة
        url(name, m) {
            return this.cfg.endpoints.message[name].replace(':id', m.id);
        },

        startEdit(m) {
            this.editingId = m.id;
            this.editBody = m.body;
            this.error = '';
            this.$nextTick(() => document.getElementById('edit-box')?.focus());
        },

        async saveEdit(m) {
            try {
                const result = await api('PATCH', this.url('update', m), { body: this.editBody });
                this.upsert(result.message);
                this.editingId = null;
            } catch (e) {
                this.error = e.message;
            }
        },

        async remove(m) {
            if (!window.confirm('حذف هذه الرسالة؟')) {
                return;
            }

            try {
                this.upsert((await api('DELETE', this.url('destroy', m))).message);
            } catch (e) {
                this.error = e.message;
            }
        },

        startReport(m) {
            this.reporting = m.id;
            this.$nextTick(() => document.getElementById('report-category')?.focus());
        },

        async submitReport(m) {
            try {
                await api('POST', this.url('report', m), { category: this.reportCategory, details: this.reportDetails || null });
                this.reporting = null;
                this.reportDetails = '';
                this.notice = 'شكرًا: وصل بلاغك إلى المشرفين.';
            } catch (e) {
                this.error = e.message;
                this.reporting = null;
            }
        },

        async moderate(action, m) {
            const reason = window.prompt(action === 'hide' ? 'سبب إخفاء الرسالة؟' : 'سبب استعادة الرسالة؟');

            if (!reason || !reason.trim()) {
                return;
            }

            try {
                this.upsert((await api('POST', this.url(action, m), { reason: reason.trim() })).message);
            } catch (e) {
                this.error = e.message;
            }
        },


        // ---------- E23: عرض فقط (لا يمسّ النقل ولا الصلاحيات ولا البثّ)
        initial(sender) {
            return sender?.name ? Array.from(sender.name.trim())[0] ?? '؟' : '؟';
        },

        /** درجة لون ثابتة لكل مرسل (4 درجات من رموز الثيم) - تمييز بصري فقط. */
        tone(sender) {
            const key = sender?.public_id ?? '';
            let hash = 0;

            for (let i = 0; i < key.length; i++) {
                hash = (hash * 31 + key.charCodeAt(i)) >>> 0;
            }

            return 'chat-avatar--t' + (hash % 4);
        },

        sameGroup(a, b) {
            const sa = a?.sender?.public_id ?? null;

            if (!a || !b || !sa || sa !== (b.sender?.public_id ?? null)) {
                return false;
            }

            return Math.abs(new Date(b.created_at) - new Date(a.created_at)) <= 5 * 60000 && this.dayKey(a.created_at) === this.dayKey(b.created_at);
        },

        isGroupStart(i) {
            return !this.sameGroup(this.messages[i - 1], this.messages[i]);
        },

        dayKey(iso) {
            return new Date(iso).toDateString();
        },

        dayStart(i) {
            return i === 0 || this.dayKey(this.messages[i - 1].created_at) !== this.dayKey(this.messages[i].created_at);
        },

        dayLabel(iso) {
            const d = new Date(iso);
            const today = new Date();
            const yesterday = new Date(today.getTime() - 86400000);

            if (d.toDateString() === today.toDateString()) {
                return 'اليوم';
            }

            return d.toDateString() === yesterday.toDateString() ? 'أمس' : d.toLocaleDateString('ar', { day: 'numeric', month: 'long' });
        },

        fullTime(iso) {
            return new Date(iso).toLocaleString('ar', { dateStyle: 'medium', timeStyle: 'short' });
        },

        hasActions(m) {
            return !!(m.can_edit || m.can_delete || m.can_report || m.can_hide || m.can_restore);
        },

        // ---------- قائمة الإجراءات (لوحة المفاتيح: أسهم/Home/End/Escape، وTab يغلقها)
        toggleMenu(m, event) {
            if (this.menuId === m.id) {
                this.closeMenu(true);

                return;
            }

            const trigger = event.currentTarget;
            const scroller = this.$refs.scroller.getBoundingClientRect();
            const rect = trigger.getBoundingClientRect();
            const below = scroller.bottom - rect.bottom;
            const above = rect.top - scroller.top;

            this.menuUp = below < 190 && above > below;
            this.menuId = m.id;
            this.focusFirstItem(m.id);
        },

        /** القائمة تُعرض بعد دورة Alpine التالية؛ نعيد المحاولة بإطار رسم (حتى 5) إلى أن يصير أول عنصر قابلًا للتركيز. */
        focusFirstItem(id, tries = 5) {
            requestAnimationFrame(() => {
                const first = this.menuId === id ? this.menuItems(id)[0] : null;

                if (first) {
                    first.focus();
                } else if (this.menuId === id && tries > 0) {
                    this.focusFirstItem(id, tries - 1);
                }
            });
        },

        closeMenu(restoreFocus = false) {
            const id = this.menuId;
            this.menuId = null;

            if (restoreFocus && id !== null) {
                document.querySelector(`[data-chat-act="${id}"]`)?.focus();
            }
        },

        menuItems(id) {
            return [...document.querySelectorAll(`[data-chat-menu="${id}"] [role="menuitem"]`)].filter((el) => el.offsetParent !== null);
        },

        menuKey(event, id) {
            const items = this.menuItems(id);
            const at = items.indexOf(document.activeElement);
            const go = { ArrowDown: (at + 1) % items.length, ArrowUp: (at - 1 + items.length) % items.length, Home: 0, End: items.length - 1 }[event.key];

            if (go === undefined || items.length === 0) {
                return;
            }

            event.preventDefault();
            items[go].focus();
        },

        // ---------- مؤلِّف الرسائل
        grow() {
            const el = this.$refs.box;

            if (!el) {
                return;
            }

            const style = getComputedStyle(el);
            const line = parseFloat(style.lineHeight) || 24;
            const max = line * 5 + parseFloat(style.paddingTop) + parseFloat(style.paddingBottom) + parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth);

            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight + parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth), max) + 'px';
            el.style.overflowY = el.scrollHeight > max ? 'auto' : 'hidden';
        },

        /** Enter يرسل، Shift+Enter سطر جديد، ولا إرسال أثناء تركيب نص IME. */
        onEnter(event) {
            if (event.shiftKey || event.isComposing) {
                return;
            }

            event.preventDefault();
            this.send();
        },

        refocusComposer() {
            if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
                this.$refs.box?.focus({ preventScroll: true });
            }
        },

        // ---------- التمرير
        isNearBottom() {
            const el = this.$refs.scroller;

            return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 160;
        },

        onScroll() {
            this.atBottom = this.isNearBottom();

            if (this.atBottom) {
                this.unseen = 0;
            }
        },

        afterIncoming(stick, fromMe) {
            if (stick) {
                this.scrollBottom();
            } else if (!fromMe) {
                this.unseen++;
            }
        },

        jumpLatest() {
            this.unseen = 0;
            this.scrollBottom();
            this.$refs.box?.focus({ preventScroll: true });
        },

        // ---------- نصوص الحالات (تفسير عرضي لرمز المنع القادم من الخادم؛ السلطة للخادم دائمًا)
        blockerView() {
            const views = {
                blocked: ['المراسلة غير متاحة', 'no-symbol'],
                not_friends: ['لم تعودا صديقين', 'friends'],
                muted: ['أنت مكتوم مؤقتًا', 'mute'],
                team_inactive: ['الدردشة للقراءة فقط', 'lock'],
                closed: ['المحادثة مغلقة', 'lock'],
                unverified: ['وثّق بريدك الإلكتروني', 'mail'],
                frozen: ['حسابك مجمَّد', 'lock'],
            };

            return views[this.cfg.caps.blocker] ?? ['الإرسال غير متاح', 'lock'];
        },

        emptyCopy() {
            return {
                global: 'كن أول من يكتب في الدردشة العامة. نص عادي فقط.',
                team: 'اكتب لزملاء فريقك. الرسائل تظهر لأعضاء الفريق فقط.',
                direct: `قل مرحبًا لـ${this.cfg.title}. المحادثة بينكما فقط.`,
            }[this.cfg.type] ?? 'لا رسائل بعد.';
        },

        // ---------- عرض
        timeLabel(iso) {
            return new Date(iso).toLocaleTimeString('ar', { hour: '2-digit', minute: '2-digit' });
        },

        scrollBottom() {
            const el = this.$refs.scroller;
            el && (el.scrollTop = el.scrollHeight);
        },

    };
}
