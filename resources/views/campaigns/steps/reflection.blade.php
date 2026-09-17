@extends('layouts.app')

@section('title', $step->title)

@section('content')
    <x-step-shell :campaign="$campaign" :step="$step" :mission-number="$missionNumber ?? null">
        <span class="chip !py-1 !px-3 mb-4 mt-4 inline-block">تأمّل</span>

        <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-4">{{ $step->title }}</h1>

        @if (data_get($step->content, 'media_path'))
            <x-media-or-placeholder
                :path="data_get($step->content, 'media_path')"
                :type="data_get($step->content, 'media_type', 'image')"
                :caption="data_get($step->content, 'caption')"
            />
        @endif

        @if ($intro = data_get($step->content, 'intro'))
            <p class="text-slate-400 leading-relaxed mb-4">{{ $intro }}</p>
        @endif

        @if ($prompt = data_get($step->content, 'prompt', $step->subtitle))
            <p class="text-slate-300 leading-relaxed mb-6">{{ $prompt }}</p>
        @endif

        @if ($completed)
            <div class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-5 py-4 font-bold anim-fade-up d-2">
                🎉 شكرًا لمشاركتك - تم إرسال إجابتك بأمان، ولا يراها أحد سواك وفريق المنصة.
            </div>
        @else
            @php
                $minChars = (int) data_get($step->content, 'min_chars', 10);
                $maxChars = (int) data_get($step->content, 'max_chars', 2000);
            @endphp
            <form
                method="POST"
                action="{{ route('campaigns.steps.reflect', [$campaign, $step]) }}"
                class="anim-fade-up d-2"
                x-data="{ text: {{ Illuminate\Support\Js::from(old('response', '')) }} }"
            >
                @csrf
                <label for="reflection-response" class="sr-only">إجابتك</label>
                <textarea
                    id="reflection-response"
                    name="response"
                    rows="6"
                    minlength="{{ $minChars }}"
                    maxlength="{{ $maxChars }}"
                    required
                    x-model="text"
                    placeholder="اكتب إجابتك هنا..."
                    class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-3 text-white placeholder:text-slate-500 focus:border-amethyst/50 focus:outline-none mb-2"
                ></textarea>

                <div class="flex items-center justify-between text-xs text-slate-500 mb-4">
                    <span>الحد الأدنى {{ $minChars }} حرفًا</span>
                    <span :class="{ 'text-rose': text.length > {{ $maxChars }} }">
                        <span x-text="text.length"></span> / {{ $maxChars }}
                    </span>
                </div>

                @error('response')
                    <p class="text-sm text-rose mb-4">{{ $message }}</p>
                @enderror

                <button type="submit" class="btn-gem !py-3 !px-6">إرسال الإجابة</button>
            </form>
        @endif
    </x-step-shell>
@endsection