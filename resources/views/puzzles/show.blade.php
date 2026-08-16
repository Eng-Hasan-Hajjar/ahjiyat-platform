@extends('layouts.app')

@section('title', $puzzle->title ?? 'أحجية')

@section('content')

    @php
        $difficultyLabel = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'][$puzzle->difficulty] ?? $puzzle->difficulty;
        $diffStyle = [
            'easy' => 'bg-emerald/10 text-emerald border-emerald/30',
            'medium' => 'bg-amber-400/10 text-amber-300 border-amber-400/30',
            'hard' => 'bg-rose/10 text-rose border-rose/30',
        ][$puzzle->difficulty] ?? 'bg-white/10 text-slate-300 border-white/20';
    @endphp

    <div class="max-w-3xl mx-auto">
        <a href="{{ route('puzzles.index') }}"
           class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-white transition mb-4 anim-fade-up">
            → كل الأحجيات
        </a>

        <div class="puzzle-card !p-6 md:!p-9 anim-fade-up d-1">
            <div class="flex flex-wrap items-center gap-2 mb-5">
                <span class="chip !py-1 !px-3">{{ $puzzle->category->name }}</span>
                <span class="rounded-full border px-3 py-1 text-xs font-black {{ $diffStyle }}">
                    {{ $difficultyLabel }}
                </span>
                <span class="ms-auto text-sm font-black text-gold">
                    +{{ $puzzle->gem_reward ?? 0 }} 💎
                </span>
            </div>

            <h1 class="font-display font-black text-2xl md:text-4xl text-white mb-4 leading-tight">
                {{ $puzzle->prompt }}
            </h1>

            @if ($puzzle->image_path)
                <div class="rounded-2xl overflow-hidden border border-white/10 mb-6">
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($puzzle->image_path) }}"
                         alt="{{ $puzzle->title }}"
                         class="w-full h-auto">
                </div>
            @endif

            {{-- ===== حالات الحل ===== --}}
            @if ($alreadySolved)
                <div class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-5 py-4 font-bold anim-fade-up d-2">
                    🎉 أحسنت! سبق أن حللت هذه الأحجية بنجاح
                </div>
            @elseif ($attemptsUsed >= $puzzle->max_attempts)
                <div class="rounded-xl border border-rose/30 bg-rose/10 text-rose px-5 py-4 font-bold anim-fade-up d-2">
                    ❌ استنفدت جميع محاولاتك المسموحة لهذه الأحجية
                </div>
            @else
                <form method="POST" action="{{ route('puzzles.attempt', $puzzle) }}" class="mt-6 anim-fade-up d-2">
                    @csrf

                    @if ($puzzle->type === 'multiple_choice' && $puzzle->choices)
                        <div class="space-y-3 mb-5">
                            @foreach ($puzzle->choices as $choice)
                                <label class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-4 py-4 cursor-pointer hover:border-amethyst hover:bg-amethyst/10 transition">
                                    <input type="radio" name="answer" value="{{ $choice }}" required class="accent-amethyst">
                                    <span class="text-slate-200 font-semibold">{{ $choice }}</span>
                                </label>
                            @endforeach
                        </div>
                    @else
                        <input type="text" name="answer" required placeholder="اكتب إجابتك هنا..."
                               class="input-gem mb-5">
                    @endif

                    <div class="flex items-center justify-between">
                        <span class="text-sm font-bold text-slate-400">
                            محاولة {{ $attemptsUsed + 1 }} من {{ $puzzle->max_attempts }}
                        </span>
                        <button type="submit" class="btn-gem !py-3 !px-6">
                            إرسال الإجابة
                        </button>
                    </div>
                </form>

                @if ($puzzle->hint)
                    <form method="POST" action="{{ route('puzzles.hint', $puzzle) }}" class="mt-6 pt-6 border-t border-white/5">
                        @csrf
                        <button type="submit"
                                class="text-sm font-bold text-slate-400 hover:text-gold transition underline decoration-gold decoration-2 underline-offset-4">
                            💡 إظهار التلميح (مقابل {{ config('gems.hint_cost') }} جواهر)
                        </button>
                    </form>
                @endif
            @endif
        </div>
    </div>

@endsection