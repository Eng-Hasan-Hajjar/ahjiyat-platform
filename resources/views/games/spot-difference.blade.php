@auth
    @if (auth()->user()->hasVerifiedEmail())
        @php
            $spotPayload = app(\App\GameEngine\GameTypeRegistry::class)
                ->definitionFor($puzzle->game_type)
                ->publicPayload($puzzle);

            // Refactor صغير عام (C8.8): الـView نفسها تُعاد استخدامها حرفياً
            // بالحملة (campaigns.steps.session) والاستقلال (game-sessions.start)
            // - لا نسخة "campaign-spot-difference" منفصلة. المتصل يمرر
            // sessionStartUrl صراحة؛ الافتراضي هون فقط شبكة أمان للاستقلال.
            $sessionStartUrl ??= route('game-sessions.start', $puzzle);
        @endphp

        <div
            class="game-surface"
            x-data="spotDifferenceGame({{ Illuminate\Support\Js::from($spotPayload) }}, '{{ $sessionStartUrl }}')"
            x-init="init()"
        >
                            <div class="game-hud-item">
                    <x-game-icon name="target" class="w-5 h-5 text-amethyst" />
                    <span>وجدت <span class="font-black text-white" x-text="found"></span> من <span class="font-black text-white" x-text="required"></span></span>
                </div>

            <div
                class="spot-difference-stage"
                :class="{ 'is-locked': finished }"
                @pointerdown="handlePointerDown($event)"
            >
                              <template x-if="images.before">
                    <div class="spot-difference-image-wrap">
                        <span class="spot-difference-image-label">قبل</span>
                        <img :src="images.before" class="spot-difference-image" alt="قبل" draggable="false">
                    </div>
                </template>
                <template x-if="images.after">
                    <div class="spot-difference-image-wrap">
                        <span class="spot-difference-image-label">بعد (اضغط لاكتشاف الفروق)</span>
                        <img :src="images.after" class="spot-difference-image" alt="بعد" draggable="false">
                    </div>
                </template>
                <template x-for="marker in markers" :key="marker.id">
                    <span class="spot-difference-marker" :style="marker.style"></span>
                </template>

                <div x-show="lastMiss" x-cloak class="spot-difference-miss-flash"></div>
            </div>

            <div x-show="finished && won" x-cloak class="game-result game-result-win anim-fade-up">
                <x-game-icon name="trophy" class="w-10 h-10 text-gold" />
                <p class="font-black text-xl">أحسنت! وجدت كل الفروق 🎉</p>
            </div>

            <div x-show="finished && !won" x-cloak class="game-result game-result-lose anim-fade-up">
                <x-game-icon name="wrong" class="w-8 h-8 text-rose" />
                <p class="font-bold">انتهى الوقت أو استُنفدت المحاولات - حاول مرة أخرى</p>
            </div>
        </div>
    @else
        <div class="game-result anim-fade-up">
            <x-game-icon name="lock" class="w-10 h-10 text-gold" />
            <p class="font-bold">وثّق بريدك الإلكتروني أولاً لتتمكن من بدء هذا التحدي.</p>
            <a href="{{ route('verification.notice') }}" class="btn-gem !py-3 !px-6 mt-3">توثيق البريد</a>
        </div>
    @endif
@else
    <div class="game-result anim-fade-up">
        <x-game-icon name="lock" class="w-10 h-10 text-gold" />
        <p class="font-bold">سجّل الدخول لبدء تحدي اكتشاف الفروق والحصول على جواهرك 💎</p>
        <a href="{{ route('login') }}" class="btn-gem !py-3 !px-6 mt-3">تسجيل الدخول</a>
    </div>
@endauth