export default function spotDifferenceGame(payload, puzzleId) {
    return {
        images: { before: payload.image_before, after: payload.image_after },
        required: payload.required_differences,
        found: 0,
        markers: [],
        finished: false,
        won: false,
        lastMiss: false,
        expiresAt: null,
        expiresInSeconds: null,
        sessionId: null,
        busy: false,
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.content,
        _tickHandle: null,

        async init() {
            try {
                const res = await fetch(`/puzzles/${puzzleId}/session`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, Accept: 'application/json' },
                });

                if (!res.ok) return;

                const data = await res.json();
                this.sessionId = data.session_id;
                this.found = data.found;
                this.required = data.required;

                if (data.expires_at) {
                    this.expiresAt = new Date(data.expires_at);
                    this._startCountdown();
                }
            } catch {
                // فشل الشبكة - تُترك الواجهة بحالتها الابتدائية بدل كسر الصفحة بالكامل
            }
        },

        _startCountdown() {
            const update = () => {
                this.expiresInSeconds = Math.max(0, Math.round((this.expiresAt - new Date()) / 1000));
            };

            update();
            this._tickHandle = setInterval(update, 1000);
        },

        get formattedTime() {
            if (this.expiresInSeconds === null) return '';
            const m = Math.floor(this.expiresInSeconds / 60).toString().padStart(2, '0');
            const s = Math.floor(this.expiresInSeconds % 60).toString().padStart(2, '0');
            return `${m}:${s}`;
        },

        async handlePointerDown(event) {
            if (this.finished || this.busy || !this.sessionId) return;

            const target = event.target.closest('.spot-difference-image');
            if (!target) return;

            const rect = target.getBoundingClientRect();
            const x = (event.clientX - rect.left) / rect.width;
            const y = (event.clientY - rect.top) / rect.height;

            if (x < 0 || x > 1 || y < 0 || y > 1) return;

            this.busy = true;

            try {
                const res = await fetch(`/game-sessions/${this.sessionId}/reveal`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ x, y }),
                });

                if (!res.ok) return;

                const data = await res.json();

                if (data.hit) {
                    this.markers.push({
                        id: this.markers.length,
                        style: `left:${x * 100}%; top:${y * 100}%;`,
                    });
                    this.found = data.found;
                } else {
                    this.lastMiss = true;
                    setTimeout(() => {
                        this.lastMiss = false;
                    }, 300);
                }

                if (data.completed) {
                    this.finished = true;
                    this.won = !!data.correct;
                    clearInterval(this._tickHandle);

                    setTimeout(() => {
                        window.location.reload();
                    }, 1200);
                }
            } finally {
                this.busy = false;
            }
        },
    };
}