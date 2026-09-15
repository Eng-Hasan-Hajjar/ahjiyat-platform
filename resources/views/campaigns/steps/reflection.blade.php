@extends('layouts.app')

@section('title', $step->title)

@section('content')
    <div class="max-w-2xl mx-auto">
        <a href="{{ route('campaigns.show', $campaign) }}"
           class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-white transition mb-4 anim-fade-up">
            → {{ $campaign->title }}
        </a>

        <div class="puzzle-card !p-6 md:!p-9 anim-fade-up d-1">
            <span class="chip !py-1 !px-3 mb-4 inline-block">تأمّل</span>

            <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-4">{{ $step->title }}</h1>

            @if ($prompt = data_get($step->content, 'prompt', $step->subtitle))
                <p class="text-slate-300 leading-relaxed mb-6">{{ $prompt }}</p>
            @endif

            @if ($completed)
                <div class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-5 py-4 font-bold anim-fade-up d-2">
                    🎉 شكرًا لمشاركتك - تم إرسال إجابتك.
                </div>
            @else
                @php
                    $minChars = data_get($step->content, 'min_chars', 10);
                    $maxChars = data_get($step->content, 'max_chars', 2000);
                @endphp
                <form method="POST" action="{{ route('campaigns.steps.reflect', [$campaign, $step]) }}" class="anim-fade-up d-2">
                    @csrf
                    <textarea
                        name="response"
                        rows="6"
                        minlength="{{ $minChars }}"
                        maxlength="{{ $maxChars }}"
                        required
                        placeholder="اكتب إجابتك هنا..."
                        class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-3 text-white placeholder:text-slate-500 focus:border-amethyst/50 focus:outline-none mb-2"
                    >{{ old('response') }}</textarea>
                    <p class="text-xs text-slate-500 mb-4">بين {{ $minChars }} و{{ $maxChars }} حرفًا.</p>

                    @error('response')
                        <p class="text-sm text-rose mb-4">{{ $message }}</p>
                    @enderror

                    <button type="submit" class="btn-gem !py-3 !px-6">إرسال الإجابة</button>
                </form>
            @endif
        </div>
    </div>
@endsection