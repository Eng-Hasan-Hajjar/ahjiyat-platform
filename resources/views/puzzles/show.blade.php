@extends('layouts.app')

@section('title', $puzzle->title)

@section('content')
    @php
        $difficultyLabel = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'][$puzzle->difficulty] ?? $puzzle->difficulty;
    @endphp

    <div class="max-w-xl mx-auto bg-white border border-ink/10 rounded-2xl p-6 md:p-8">
        <div class="flex items-center justify-between mb-4">
            <span class="text-xs font-bold px-2 py-1 rounded-full bg-amethyst-50 text-amethyst-700">{{ $puzzle->category->name }}</span>
            <span class="text-xs font-bold px-2 py-1 rounded-full bg-ink/5 text-ink/60">{{ $difficultyLabel }}</span>
        </div>

        <h1 class="font-display font-bold text-xl text-ink mb-2">{{ $puzzle->title }}</h1>

        <p class="text-ink/80 leading-relaxed mb-6">{{ $puzzle->prompt }}</p>

        @if ($puzzle->image_path)
            <img src="{{ \Illuminate\Support\Facades\Storage::url($puzzle->image_path) }}" alt="{{ $puzzle->title }}" class="rounded-lg mb-6 w-full">
        @endif

        @if ($alreadySolved)
            <div class="rounded-lg bg-emerald-50 border border-emerald text-emerald-600 px-4 py-3 text-sm">
                أحسنت! سبق أن حللت هذه الأحجية بنجاح.
            </div>
        @elseif ($attemptsUsed >= $puzzle->max_attempts)
            <div class="rounded-lg bg-rose-50 border border-rose text-rose px-4 py-3 text-sm">
                استنفدت جميع محاولاتك المسموحة لهذه الأحجية.
            </div>
        @else
            <form method="POST" action="{{ route('puzzles.attempt', $puzzle) }}" class="space-y-4">
                @csrf

                @if ($puzzle->type === 'multiple_choice' && $puzzle->choices)
                    <div class="space-y-2">
                        @foreach ($puzzle->choices as $choice)
                            <label class="flex items-center gap-2 border border-ink/10 rounded-lg px-4 py-3 cursor-pointer hover:border-amethyst">
                                <input type="radio" name="answer" value="{{ $choice }}" required class="accent-amethyst">
                                {{ $choice }}
                            </label>
                        @endforeach
                    </div>
                @else
                    <input type="text" name="answer" required placeholder="اكتب إجابتك هنا"
                           class="w-full rounded-lg border border-ink/20 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-amethyst">
                @endif

                <div class="flex items-center justify-between">
                    <span class="text-xs text-ink/50">
                        محاولة {{ $attemptsUsed + 1 }} من {{ $puzzle->max_attempts }}
                    </span>
                    <button type="submit"
                            class="bg-amethyst text-white font-bold px-5 py-2.5 rounded-lg hover:bg-amethyst-700 transition">
                        إرسال الإجابة
                    </button>
                </div>
            </form>

            @if ($puzzle->hint)
                <form method="POST" action="{{ route('puzzles.hint', $puzzle) }}" class="mt-4">
                    @csrf
                    <button type="submit" class="text-sm text-ink/60 hover:text-amethyst underline">
                        إظهار التلميح (مقابل {{ config('gems.hint_cost') }} جواهر)
                    </button>
                </form>
            @endif
        @endif
    </div>
@endsection
