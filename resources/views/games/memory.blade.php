@php
    $shuffledCards = collect($puzzle->game_config['cards'] ?? [])->shuffle()->values()->all();
    $startToken = \App\GameEngine\Support\TimedAttemptToken::issue($puzzle, auth()->id());
@endphp

<div
    x-data="memoryGame({{ Illuminate\Support\Js::from($shuffledCards) }}, {{ (int) ($puzzle->time_limit_seconds ?? 0) }})"
    x-init="init()"
    class="game-surface mb-5"
>
    <div class="game-hud">
        <div class="game-hud-item" x-show="timeLimitSeconds > 0">
            <x-game-icon name="timer" class="w-5 h-5 text-gold" />
            <span x-text="formattedTime"></span>
        </div>
        <div class="game-hud-item">
            <x-game-icon name="target" class="w-5 h-5 text-amethyst" />
            <span x-text="moves"></span> حركة
        </div>
        <button type="button" @click="toggleMute()" class="game-hud-item" :aria-label="muted ? 'تفعيل الصوت' : 'كتم الصوت'">
            <x-game-icon name="sound" class="w-5 h-5" x-show="!muted" />
            <x-game-icon name="mute" class="w-5 h-5" x-show="muted" x-cloak />
        </button>
    </div>

    <div class="memory-grid" :class="gridSizeClass">
        <template x-for="card in board" :key="card.id">
            <button
                type="button"
                class="memory-card game-press"
                :class="{ 'is-flipped': isFlipped(card), 'is-matched': isMatched(card), 'is-shaking': shakingIds.includes(card.id) }"
                @click="flip(card)"
                :disabled="isMatched(card) || finished"
            >
                <span class="memory-card-inner">
                    <span class="memory-card-face memory-card-back">
                        <x-game-icon name="puzzle" class="w-7 h-7" />
                    </span>
                    <span class="memory-card-face memory-card-front" x-text="card.face"></span>
                </span>
            </button>
        </template>
    </div>

    <div x-show="finished && won" x-cloak class="game-result game-result-win anim-fade-up">
        <x-game-icon name="trophy" class="w-10 h-10 text-gold" />
        <p class="font-black text-xl">أحسنت! أنهيت اللعبة 🎉</p>
    </div>

    <div x-show="finished && !won" x-cloak class="game-result game-result-lose anim-fade-up">
        <x-game-icon name="wrong" class="w-8 h-8 text-rose" />
        <p class="font-bold">انتهى الوقت - حاول مرة أخرى</p>
    </div>

    <input type="hidden" name="submission" :value="submissionJson">
    <input type="hidden" name="start_token" value="{{ $startToken }}">
</div>