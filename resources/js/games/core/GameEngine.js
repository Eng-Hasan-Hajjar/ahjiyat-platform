export default class GameEngine extends EventTarget {
    constructor(options = {}) {
        super();
        this.timeLimitSeconds = options.timeLimitSeconds ?? null;
        this.lives = options.lives ?? null;

        this.state = 'idle'; // idle | running | paused | finished
        this.score = 0;
        this.moves = 0;
        this.elapsedSeconds = 0;
        this.muted = false;

        this._startedAt = 0;
        this._timerHandle = null;
    }

    initialize() {
        this.emit('initialize');
    }

    start() {
        if (this.state === 'running') return;
        this.state = 'running';
        this._startedAt = performance.now() - this.elapsedSeconds * 1000;
        this._tick();
        this.emit('start');
    }

    pause() {
        if (this.state !== 'running') return;
        this.state = 'paused';
        cancelAnimationFrame(this._timerHandle);
        this.emit('pause');
    }

    resume() {
        if (this.state === 'paused') this.start();
    }

    restart() {
        cancelAnimationFrame(this._timerHandle);
        this.state = 'idle';
        this.score = 0;
        this.moves = 0;
        this.elapsedSeconds = 0;
        this.emit('restart');
        this.start();
    }

    finish(result = {}) {
        if (this.state === 'finished') return;
        this.state = 'finished';
        cancelAnimationFrame(this._timerHandle);
        this.emit('finish', result);
    }

    addScore(points) {
        this.score += points;
        this.emit('score', this.score);
    }

    recordMove() {
        this.moves += 1;
        this.emit('move', this.moves);
    }

    loseLife() {
        if (this.lives === null) return;
        this.lives -= 1;
        this.emit('life', this.lives);
        if (this.lives <= 0) this.finish({ reason: 'no-lives' });
    }

    toggleMute(force) {
        this.muted = force ?? !this.muted;
        this.emit('mute', this.muted);
    }

    emit(name, detail) {
        this.dispatchEvent(new CustomEvent(name, { detail }));
    }

    _tick = () => {
        if (this.state !== 'running') return;

        this.elapsedSeconds = (performance.now() - this._startedAt) / 1000;
        this.emit('tick', this.elapsedSeconds);

        if (this.timeLimitSeconds && this.elapsedSeconds >= this.timeLimitSeconds) {
            this.finish({ reason: 'time-up' });
            return;
        }

        this._timerHandle = requestAnimationFrame(this._tick);
    };
}