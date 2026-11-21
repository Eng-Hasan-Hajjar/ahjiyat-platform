@extends('layouts.app')

@section('title', $exercise->title.' - مختبر الأحجيات')
@section('meta_description', 'تمرين تدريبي: '.$exercise->title.'. للتدريب فقط، بلا جواهر أو نقاط خبرة.')

@section('content')

    <div class="max-w-3xl mx-auto">

        <x-page-header :title="$exercise->title" icon="puzzle" :back="route('puzzle-lab.index')" backLabel="مختبر الأحجيات" class="anim-fade-up" />

        @if ($preview)
            {{-- معاينة إدارية: حالة التمرين ظاهرة، وهذه الصفحة لا تجعله متاحًا للاعبين. --}}
            <div class="lab-preview-banner anim-fade-up" role="note">
                <x-ui-icon name="eye-slash" class="w-5 h-5 shrink-0" />
                <div class="min-w-0">
                    <p class="font-black">معاينة إدارية — الحالة: {{ $exercise->statusLabel() }}</p>
                    <p class="text-sm">
                        @if ($exercise->isPublished())
                            هذا التمرين ظاهر للاعبين الآن.
                        @else
                            هذه المعاينة لك فقط؛ لا يراه اللاعبون ولا تظهر في قائمة المختبر حتى يُنشر.
                        @endif
                        الجولة هنا تدريب أيضًا ولا تُسجَّل.
                    </p>
                    @can('update', $exercise)
                        <a href="{{ \App\Filament\Resources\PuzzleLabExerciseResource::getUrl('edit', ['record' => $exercise]) }}" class="text-sm font-black underline underline-offset-2">العودة إلى تعديل التمرين</a>
                    @endcan
                </div>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2 mb-5 anim-fade-up d-1">
            <span class="chip !py-1 !px-3">{{ $exercise->typeLabel() }}</span>
            <x-difficulty-badge :difficulty="$exercise->level" />
        </div>

        @if ($problems !== [])
            <div class="lab-problems" role="alert">
                <p class="font-black mb-2">التمرين غير مكتمل، فلا يمكن تشغيل جولة قبل إصلاح ما يلي:</p>
                <ul class="list-disc ps-5 space-y-1 text-sm">
                    @foreach ($problems as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            </div>
        @else
            <div
                x-data="labRound({{ \Illuminate\Support\Js::from([
                    'type' => $exercise->game_type,
                    'startUrl' => route('puzzle-lab.rounds.start', $exercise),
                    'flipUrl' => route('puzzle-lab.rounds.flip', $exercise),
                    'checkUrl' => route('puzzle-lab.rounds.check', $exercise),
                    'indexUrl' => route('puzzle-lab.index'),
                ]) }})"
                class="game-surface lab-stage anim-fade-up d-2"
            >
                {{-- التعليمات: تُقرأ قبل البدء وتبقى ظاهرة أثناء اللعب. --}}
                <section class="lab-instructions" aria-labelledby="lab-instructions-title">
                    <h2 id="lab-instructions-title" class="font-display font-black text-lg text-white mb-2">التعليمات</h2>
                    <p class="lab-prose">{{ $exercise->instructions }}</p>
                </section>

                <noscript>
                    <p class="lab-problems">يحتاج التمرين إلى تفعيل JavaScript في المتصفح.</p>
                </noscript>

                {{-- قبل البدء: العبارة الإلزامية قرب زر البدء. --}}
                <div x-show="phase === 'idle'" class="lab-start">
                    <p id="lab-training-note" class="lab-notice">
                        <x-ui-icon name="info" class="w-4 h-4 shrink-0" />
                        <span>للتدريب فقط. لا تُحتسب جواهر أو نقاط خبرة.</span>
                    </p>
                    <button type="button" class="btn-gem lab-start-btn" @click="startRound()" :disabled="loading" aria-describedby="lab-training-note">
                        <x-game-icon name="play" class="w-5 h-5" />
                        <span x-text="loading ? 'جارٍ التحضير...' : 'ابدأ التدريب'"></span>
                    </button>
                </div>

                <p x-show="error" x-cloak class="lab-problems" role="alert" x-text="error"></p>

                {{-- أثناء الجولة --}}
                <div x-show="phase === 'playing' || phase === 'result'" x-cloak class="lab-play" x-ref="play" tabindex="-1">
                    <p class="lab-notice lab-notice-compact">
                        <x-ui-icon name="info" class="w-4 h-4 shrink-0" />
                        <span>للتدريب فقط. لا تُحتسب جواهر أو نقاط خبرة.</span>
                    </p>

                    @if ($exercise->game_type === \App\Models\PuzzleLabExercise::TYPE_MEMORY)
                        <div class="game-hud">
                            <div class="game-hud-item">
                                <x-game-icon name="target" class="w-5 h-5 text-amethyst" />
                                <span x-text="moves"></span> محاولة
                            </div>
                            <div class="game-hud-item">
                                <x-game-icon name="check" class="w-5 h-5 text-emerald" />
                                <span x-text="matchedPairs.length"></span> من <span x-text="pairs"></span> أزواج
                            </div>
                        </div>

                        <div class="memory-grid lab-board" :class="board.length > 12 ? 'memory-grid-lg' : ''" role="group" aria-label="بطاقات الذاكرة">
                            <template x-for="(card, position) in board" :key="card.id">
                                <button
                                    type="button"
                                    class="memory-card game-press"
                                    :class="{ 'is-flipped': isFlipped(card), 'is-matched': isMatched(card), 'is-shaking': shakingIds.includes(card.id) }"
                                    @click="flip(card)"
                                    :disabled="isMatched(card) || phase === 'result'"
                                    :aria-label="cardLabel(card, position)"
                                >
                                    <span class="memory-card-inner">
                                        <span class="memory-card-face memory-card-back" aria-hidden="true">
                                            <x-game-icon name="puzzle" class="w-7 h-7" />
                                        </span>
                                        <span class="memory-card-face memory-card-front" aria-hidden="true">
                                            <span class="lab-face" x-text="faces[card.id]"></span>
                                            <span class="lab-card-check" x-show="isMatched(card)"><x-game-icon name="check" class="w-4 h-4" /></span>
                                        </span>
                                    </span>
                                </button>
                            </template>
                        </div>

                        <p x-show="note && phase === 'playing'" x-cloak class="lab-note" role="status">
                            <x-game-icon name="hint" class="w-5 h-5 shrink-0" />
                            <span><strong>ملاحظة:</strong> <span x-text="note"></span></span>
                        </p>
                    @else
                        <ol class="lab-seq" aria-label="عناصر التسلسل">
                            {{-- المفتاح = الموضع (لا معرّف العنصر): تبقى عقد الصفحة في مكانها ويتبدّل نصّها فقط، فلا يفقد الزر التركيز لحظة التحريك. --}}
                            <template x-for="(item, position) in order" :key="position">
                                <li class="lab-seq-item" :data-pos="position">
                                    <span class="lab-seq-pos" x-text="position + 1"></span>
                                    <span class="lab-seq-label" x-text="item.label"></span>
                                    <span class="lab-seq-moves">
                                        <button type="button" class="lab-move" @click="moveUp(position)" :disabled="position === 0 || phase === 'result'" :aria-label="'تحريك لأعلى: ' + item.label" data-move="up">
                                            <x-ui-icon name="chevron-down" class="w-5 h-5 lab-icon-up" />
                                            <span class="lab-move-text">تحريك لأعلى</span>
                                        </button>
                                        <button type="button" class="lab-move" @click="moveDown(position)" :disabled="position === order.length - 1 || phase === 'result'" :aria-label="'تحريك لأسفل: ' + item.label" data-move="down">
                                            <x-ui-icon name="chevron-down" class="w-5 h-5" />
                                            <span class="lab-move-text">تحريك لأسفل</span>
                                        </button>
                                    </span>
                                </li>
                            </template>
                        </ol>
                    @endif

                    <p class="sr-only" role="status" aria-live="polite" x-text="announce"></p>

                    {{-- النتيجة: أيقونة + نص دائمًا (لا اللون وحده) --}}
                    <div x-show="result" x-cloak class="lab-result" :class="result && result.correct ? 'lab-result--ok' : 'lab-result--wrong'" role="status" x-ref="result" tabindex="-1">
                        <template x-if="result && result.correct">
                            <div class="lab-result-body">
                                <x-game-icon name="trophy" class="w-8 h-8 shrink-0" />
                                <div>
                                    <p class="lab-result-title">أحسنت! أكملت التمرين.</p>
                                    <p class="lab-prose" x-show="result.explanation" x-text="result.explanation"></p>
                                </div>
                            </div>
                        </template>
                        <template x-if="result && !result.correct">
                            <div class="lab-result-body">
                                <x-game-icon name="wrong" class="w-8 h-8 shrink-0" />
                                <div>
                                    <p class="lab-result-title">ليست هذه الإجابة الصحيحة بعد.</p>
                                    <p class="lab-prose" x-text="result.note"></p>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="lab-actions">
                        @if ($exercise->game_type === \App\Models\PuzzleLabExercise::TYPE_SEQUENCE)
                            <button type="button" class="btn-gem" x-show="phase === 'playing'" @click="submitOrder()" :disabled="busy">
                                <x-game-icon name="check" class="w-5 h-5" />
                                <span x-text="busy ? 'جارٍ التحقق...' : 'تحقّق من الترتيب'"></span>
                            </button>
                        @endif
                        <button type="button" class="btn-secondary" @click="startRound()" :disabled="busy || loading">
                            <x-game-icon name="restart" class="w-5 h-5" />
                            أعد المحاولة
                        </button>
                        <a x-show="phase === 'result' && result && result.correct" x-cloak href="{{ route('puzzle-lab.index') }}" class="btn-secondary">
                            تمرين آخر
                        </a>
                    </div>
                </div>
            </div>
        @endif
    </div>

@endsection
