@extends('layouts.app')

@section('title', $step->title)

@section('content')
    <x-step-shell :campaign="$campaign" :step="$step" :mission-number="$missionNumber ?? null">
        <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-2 mt-4">{{ $step->title }}</h1>

        @if ($step->subtitle)
            <p class="text-slate-400 mb-6">{{ $step->subtitle }}</p>
        @endif

        @if (data_get($step->content, 'media_path'))
            <x-media-or-placeholder
                :path="data_get($step->content, 'media_path')"
                :type="data_get($step->content, 'media_type', 'image')"
                :caption="data_get($step->content, 'caption')"
            />
        @endif

        @if ($body = data_get($step->content, 'body'))
            <div class="text-slate-200 leading-relaxed whitespace-pre-line mb-8">{{ $body }}</div>
        @endif

        @if ($completed)
            <div class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-5 py-4 font-bold anim-fade-up d-2">
                🎉 أكملت هذه الخطوة
            </div>
        @else
            <form method="POST" action="{{ route('campaigns.steps.complete', [$campaign, $step]) }}" class="anim-fade-up d-2">
                @csrf
                <button type="submit" class="btn-gem !py-3 !px-6">متابعة القصة</button>
            </form>
        @endif
    </x-step-shell>
@endsection