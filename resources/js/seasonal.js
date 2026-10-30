/**
 * E44 - تحسينات تقدّمية لصفحتي «كتّاب الموسم» و«داعمو الموسم». الصفحة تعمل كاملة بلا JS (نماذج POST عادية وإعادة تحميل)؛ هذا الملف:
 *  - seasonalBoard: يحدّث الترتيب الحيّ (جزء HTML علني) عند إشارة بثّ عامة (تجميعية بلا هوية) مع debounce، واستطلاع احتياطي دائم يتوقف والتبويب مخفي.
 *  - seasonalVoter: يرسل توزيع النجوم بـfetch (الخادم سلطة الأهلية والميزانية والنافذة) ويعرض المتبقي ورسائل 200/422/429.
 * لا يخزّن شيئًا في localStorage، ولا يكشف هوية ناخب، ولا يثق بأي قيمة محلية: كل رقم معروض يعود من الخادم.
 */

export function seasonalBoard({ url, channel, pollSeconds = 20, debounceMs = 800, realtime = true }) {
    return {
        busy: false,
        timer: null,
        poller: null,
        inflight: null,
        subscribed: null,

        init() {
            if (realtime && typeof window.getEcho === 'function') {
                try {
                    const echo = window.getEcho();

                    if (echo) {
                        this.subscribed = echo.channel(channel).listen('.leaderboard.updated', () => this.schedule());
                    }
                } catch (e) {
                    this.subscribed = null; // الاستطلاع الاحتياطي يكفي
                }
            }

            this.poller = setInterval(() => {
                if (!document.hidden) {
                    this.refresh();
                }
            }, Math.max(5, pollSeconds) * 1000);

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    this.refresh();
                }
            });
        },

        destroy() {
            clearInterval(this.poller);
            clearTimeout(this.timer);

            try {
                if (this.subscribed && typeof window.getEcho === 'function') {
                    window.getEcho()?.leave(channel);
                }
            } catch (e) {
                // لا شيء
            }
        },

        schedule() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.refresh(), Math.max(0, debounceMs));
        },

        async refresh() {
            if (this.inflight) {
                return;
            }

            this.busy = true;
            this.inflight = new AbortController();

            try {
                const res = await fetch(url, { headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal: this.inflight.signal });

                if (res.ok && this.$refs.target) {
                    this.$refs.target.innerHTML = await res.text();
                }
            } catch (e) {
                // فشل شبكي: يبقى العرض السابق ويعيد الاستطلاع القادم المحاولة
            } finally {
                this.busy = false;
                this.inflight = null;
            }
        },
    };
}

export function seasonalVoter({ total = 10, spent = 0, open = false }) {
    return {
        total,
        spent,
        open,
        sending: false,
        messages: {},
        tones: {},

        get remaining() {
            return Math.max(0, this.total - this.spent);
        },

        messageFor(id) {
            return this.messages[id] ?? '';
        },

        toneFor(id) {
            return this.tones[id] ?? 'text-slate-400';
        },

        async save(event) {
            const form = event.target;
            const id = form.closest('[data-writer]')?.dataset.writer;
            const token = document.querySelector('meta[name="csrf-token"]')?.content;

            if (!id || this.sending) {
                return;
            }

            this.sending = true;
            this.messages[id] = 'جارٍ الحفظ…';
            this.tones[id] = 'text-slate-400';

            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token ?? '', 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form),
                    credentials: 'same-origin',
                });

                if (res.status === 429) {
                    this.messages[id] = 'محاولات كثيرة، انتظر قليلًا ثم أعد المحاولة.';
                    this.tones[id] = 'text-gold';

                    return;
                }

                if (res.status === 419) {
                    this.messages[id] = 'انتهت صلاحية الصفحة، أعد تحميلها.';
                    this.tones[id] = 'text-rose';

                    return;
                }

                const data = await res.json().catch(() => null);

                if (res.ok && data?.ok) {
                    this.spent = Number(data.spent);
                    this.total = Number(data.total);
                    this.messages[id] = Number(data.stars) === 0 ? 'سُحبت نجومك.' : 'تم الحفظ.';
                    this.tones[id] = 'text-emerald';
                } else {
                    this.messages[id] = data?.message ?? 'تعذّر الحفظ.';
                    this.tones[id] = 'text-rose';
                }
            } catch (e) {
                this.messages[id] = 'تعذّر الاتصال، أعد المحاولة.';
                this.tones[id] = 'text-rose';
            } finally {
                this.sending = false;
            }
        },
    };
}
