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

        init() {
            this.$nextTick(() => this.scrollBottom());
            this.subscribe();
            this.markRead();
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    this.markRead();
                }
            });
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

            this.upsert(this.decorate(event));

            if (isNew) {
                this.$nextTick(() => this.scrollBottomIfNear());
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
                this.$nextTick(() => this.scrollBottom());
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

        // ---------- عرض
        timeLabel(iso) {
            return new Date(iso).toLocaleTimeString('ar', { hour: '2-digit', minute: '2-digit' });
        },

        scrollBottom() {
            const el = this.$refs.scroller;
            el && (el.scrollTop = el.scrollHeight);
        },

        scrollBottomIfNear() {
            const el = this.$refs.scroller;

            if (el && el.scrollHeight - el.scrollTop - el.clientHeight < 160) {
                this.scrollBottom();
            }
        },
    };
}
