import GameEngine from '../core/GameEngine.js';
import { playSound } from '../utils/sound.js';
import { burstConfetti } from '../utils/confetti.js';

export default function memoryGame(cards, timeLimitSeconds) {
    return {
        board: cards,
        flippedIds: [],
        matchedIds: [],
        shakingIds: [],
        moves: 0,
        elapsed: 0,
        finished: false,
        won: false,
        muted: false,
        engine: null,
        timeLimitSeconds,

        init() {
            this.engine = new GameEngine({ timeLimitSeconds: this.timeLimitSeconds || null });

            this.engine.addEventListener('tick', (e) => { this.elapsed = e.detail; });
            this.engine.addEventListener('finish', (e) => this.handleFinish(e.detail));
            this.engine.addEventListener('mute', (e) => { this.muted = e.detail; });

            this.engine.initialize();
            this.engine.start();
        },

        get formattedTime() {
            const remaining = this.timeLimitSeconds
                ? Math.max(0, Math.ceil(this.timeLimitSeconds - this.elapsed))
                : Math.floor(this.elapsed);
            const m = Math.floor(remaining / 60).toString().padStart(2, '0');
            const s = Math.floor(remaining % 60).toString().padStart(2, '0');
            return `${m}:${s}`;
        },

        get gridSizeClass() {
            return this.board.length > 12 ? 'memory-grid-lg' : 'memory-grid';
        },

        isFlipped(card) {
            return this.flippedIds.includes(card.id) || this.matchedIds.includes(card.id);
        },

        isMatched(card) {
            return this.matchedIds.includes(card.id);
        },

        flip(card) {
            if (this.finished || this.isMatched(card) || this.flippedIds.includes(card.id)) return;
            if (this.flippedIds.length >= 2) return;

            this.flippedIds.push(card.id);
            playSound('flip', { muted: this.muted });

            if (this.flippedIds.length === 2) {
                this.moves += 1;
                this.engine.recordMove();
                this.$nextTick(() => this.evaluatePair());
            }
        },

        evaluatePair() {
            const [firstId, secondId] = this.flippedIds;
            const first = this.board.find((c) => c.id === firstId);
            const second = this.board.find((c) => c.id === secondId);

            if (first.face === second.face) {
                this.matchedIds.push(firstId, secondId);
                playSound('match', { muted: this.muted });

                if (this.matchedIds.length === this.board.length) {
                    this.engine.finish({ reason: 'solved' });
                }

                this.flippedIds = [];
            } else {
                this.shakingIds = [firstId, secondId];
                playSound('wrong', { muted: this.muted });

                setTimeout(() => {
                    this.shakingIds = [];
                    this.flippedIds = [];
                }, 550);
            }
        },

        handleFinish({ reason }) {
            this.finished = true;
            this.won = reason === 'solved';

            if (this.won) {
                burstConfetti(this.$el);
            }

            this.$nextTick(() => this.$el.closest('form')?.requestSubmit());
        },

        toggleMute() {
            this.engine.toggleMute();
        },

        get submissionJson() {
            return JSON.stringify({
                matches: this.chunkPairs(this.matchedIds),
                moves: this.moves,
                elapsed_seconds: Math.round(this.elapsed),
            });
        },

        chunkPairs(ids) {
            const pairs = [];
            for (let i = 0; i < ids.length; i += 2) pairs.push([ids[i], ids[i + 1]]);
            return pairs;
        },
    };
}