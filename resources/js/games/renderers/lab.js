import { burstConfetti } from '../utils/confetti.js';

/**
 * E47 - مكوّن Alpine لجولة «مختبر الأحجيات» (تدريب فقط).
 *
 * كل ما يخصّ الجولة يأتي من الخادم ويُتحقَّق منه فيه:
 *  - بدء الجولة: ذاكرة => مواضع البطاقات بلا أوجه؛ تسلسل => عناصر مخلوطة برموز معتمة خاصة بالجولة (لا ترتيب الحل).
 *  - قلب بطاقة: الخادم يكشف وجه البطاقة المقلوبة فقط.
 *  - النتيجة: الخادم يتحقق بالمدقّق الرسمي ويعيد ملاحظة (خطأ) أو شرحًا (نجاح). لا يُخزَّن شيء بالمتصفح ولا بالخادم.
 * «أعد المحاولة» = startRound() من جديد (جولة جديدة كليًا، رمز جديد، توزيع جديد).
 */
export default function labRound(config) {
    return {
        type: config.type,
        phase: 'idle', // idle | playing | result
        loading: false,
        busy: false,
        round: null,
        error: '',
        announce: '',
        note: '',
        result: null,

        // الذاكرة
        board: [],
        pairs: 0,
        faces: {},
        flippedIds: [],
        matchedIds: [],
        matchedPairs: [],
        shakingIds: [],
        moves: 0,
        mismatches: 0,

        // التسلسل
        order: [],

        csrfToken: document.querySelector('meta[name="csrf-token"]')?.content,

        async post(url, body) {
            let response;

            try {
                response = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': this.csrfToken,
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(body || {}),
                });
            } catch (e) {
                return { ok: false, status: 0, data: {} };
            }

            let data = {};

            try {
                data = await response.json();
            } catch (e) {
                data = {};
            }

            return { ok: response.ok, status: response.status, data };
        },

        failureMessage(response) {
            if (response.status === 419 || response.status === 401) {
                return 'انتهت جلستك. أعد تحميل الصفحة ثم حاول مجددًا.';
            }

            if (response.status === 429) {
                return 'محاولات كثيرة خلال وقت قصير. انتظر لحظة ثم أعد المحاولة.';
            }

            if (response.status === 422 && response.data?.message) {
                return response.data.message;
            }

            return 'تعذّر الاتصال بالخادم. تحقق من اتصالك ثم أعد المحاولة.';
        },

        resetRound() {
            this.error = '';
            this.announce = '';
            this.note = '';
            this.result = null;
            this.board = [];
            this.pairs = 0;
            this.faces = {};
            this.flippedIds = [];
            this.matchedIds = [];
            this.matchedPairs = [];
            this.shakingIds = [];
            this.moves = 0;
            this.mismatches = 0;
            this.order = [];
            this.busy = false;
        },

        /** بدء جولة جديدة (أول مرة أو «أعد المحاولة»). */
        async startRound() {
            if (this.loading) return;

            this.loading = true;
            this.round = null; // تُهمَل أي استجابة متأخرة من الجولة السابقة
            this.resetRound();

            const response = await this.post(config.startUrl);

            this.loading = false;

            if (!response.ok || !response.data?.round) {
                this.error = this.failureMessage(response);
                this.phase = 'idle'; // يعود زر «ابدأ التدريب» مع رسالة الخطأ
                return;
            }

            const data = response.data;
            this.round = data.round;

            if (this.type === 'memory') {
                this.board = data.cards || [];
                this.pairs = data.pairs || 0;
            } else {
                this.order = data.items || [];
            }

            this.phase = 'playing';
            this.announce = this.type === 'memory'
                ? `بدأت جولة جديدة: ${this.pairs} أزواج.`
                : `بدأت جولة جديدة: رتّب ${this.order.length} عناصر.`;

            this.$nextTick(() => this.$refs.play?.focus({ preventScroll: false }));
        },

        // ================= الذاكرة =================

        isFlipped(card) {
            return this.flippedIds.includes(card.id) || this.matchedIds.includes(card.id);
        },

        isMatched(card) {
            return this.matchedIds.includes(card.id);
        },

        cardLabel(card, position) {
            const number = position + 1;

            if (this.isMatched(card)) return `بطاقة ${number}: ${this.faces[card.id]} (تمت المطابقة)`;
            if (this.flippedIds.includes(card.id)) return `بطاقة ${number}: ${this.faces[card.id]}`;

            return `بطاقة ${number} مقلوبة. اضغط لكشفها`;
        },

        async flip(card) {
            if (this.phase !== 'playing' || this.busy || this.isMatched(card) || this.flippedIds.includes(card.id) || this.flippedIds.length >= 2) return;

            const round = this.round;
            this.busy = true;

            const response = await this.post(config.flipUrl, { round, card: card.id });

            if (round !== this.round) return; // جولة أُعيدت أثناء الطلب

            if (!response.ok || typeof response.data?.face !== 'string') {
                this.busy = false;
                this.error = this.failureMessage(response);
                return;
            }

            this.error = '';
            this.faces[card.id] = response.data.face;
            this.flippedIds.push(card.id);

            if (this.flippedIds.length < 2) {
                this.busy = false;
                this.announce = `${response.data.face}. اختر بطاقة ثانية.`;
                return;
            }

            this.moves += 1;
            this.evaluatePair(round);
        },

        evaluatePair(round) {
            const [firstId, secondId] = this.flippedIds;

            if (this.faces[firstId] === this.faces[secondId]) {
                this.matchedIds.push(firstId, secondId);
                this.matchedPairs.push([firstId, secondId]);
                this.flippedIds = [];
                this.announce = `تطابقت بطاقتان: ${this.faces[firstId]}. ${this.matchedPairs.length} من ${this.pairs}.`;

                if (this.matchedPairs.length === this.pairs) {
                    this.submitMatches(round);
                } else {
                    this.busy = false;
                }

                return;
            }

            this.mismatches += 1;
            this.shakingIds = [firstId, secondId];
            this.announce = `${this.faces[firstId]} و${this.faces[secondId]} غير متطابقتين.`;

            // أول عدم تطابق = «محاولة خاطئة»: الملاحظة التعليمية تأتي من الخادم بعد تحقّقه (لا تُرسل قبل ذلك).
            if (this.mismatches === 1 && !this.note) {
                this.fetchNote(round);
            }

            setTimeout(() => {
                if (round !== this.round) return;

                this.shakingIds = [];
                this.flippedIds = [];
                this.busy = false;
            }, 750);
        },

        async fetchNote(round) {
            const response = await this.post(config.checkUrl, { round, matches: this.matchedPairs });

            if (round !== this.round) return;

            if (response.ok && response.data?.correct === false && response.data.note) {
                this.note = response.data.note;
            }
        },

        async submitMatches(round) {
            const response = await this.post(config.checkUrl, { round, matches: this.matchedPairs });

            if (round !== this.round) return;

            this.busy = false;
            this.finish(response);
        },

        // ================= التسلسل =================

        moveUp(position) {
            this.move(position, position - 1, 'أعلى');
        },

        moveDown(position) {
            this.move(position, position + 1, 'أسفل');
        },

        move(from, to, direction) {
            if (this.phase !== 'playing' || to < 0 || to >= this.order.length) return;

            // $root (لا $el): داخل معالج النقر يشير $el إلى الزر نفسه، والبحث داخله لا يجد شيئًا فيضيع التركيز.
            const root = this.$root;
            const item = this.order[from];
            [this.order[from], this.order[to]] = [this.order[to], this.order[from]];
            this.announce = `نُقل «${item.label}» إلى الموضع ${to + 1} من ${this.order.length}.`;

            // نقل عنصر مفتاحي في x-for يُعيد إدراج عقدته فيفقد الزر التركيز؛ نعيده إلى زر الاتجاه نفسه (لوحة المفاتيح/قارئ الشاشة).
            this.$nextTick(() => {
                const selector = `.lab-seq-item[data-pos="${to}"] [data-move="${direction === 'أعلى' ? 'up' : 'down'}"]`;
                const button = root.querySelector(selector);
                const fallback = root.querySelector(`.lab-seq-item[data-pos="${to}"] .lab-move:not(:disabled)`);

                (button && !button.disabled ? button : fallback)?.focus();
            });
        },

        async submitOrder() {
            if (this.phase !== 'playing' || this.busy) return;

            const round = this.round;
            this.busy = true;

            const response = await this.post(config.checkUrl, { round, order: this.order.map((item) => item.idx) });

            if (round !== this.round) return;

            this.busy = false;
            this.finish(response);
        },

        // ================= النتيجة =================

        finish(response) {
            if (!response.ok || typeof response.data?.correct !== 'boolean') {
                this.error = this.failureMessage(response);
                return;
            }

            this.error = '';
            this.result = {
                correct: response.data.correct,
                note: response.data.note || '',
                explanation: response.data.explanation || '',
            };

            if (response.data.correct) {
                this.phase = 'result';
                this.announce = 'أحسنت! أكملت التمرين.';

                if (!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
                    burstConfetti(this.$root);
                }
            } else {
                this.announce = 'ليست هذه الإجابة الصحيحة بعد. ' + this.result.note;
            }

            this.$nextTick(() => this.$refs.result?.focus({ preventScroll: false }));
        },
    };
}
