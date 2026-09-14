@extends('layouts.app')

@section('title', $step->title)

@section('content')
    <div class="max-w-2xl mx-auto">
        <a href="{{ route('campaigns.show', $campaign) }}"
           class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-white transition mb-4 anim-fade-up">
            → {{ $campaign->title }}
        </a>

        <div class="puzzle-card !p-6 md:!p-9 anim-fade-up d-1">
            <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-2">{{ $step->title }}</h1>

            @if ($step->subtitle)
                <p class="text-slate-400 mb-6">{{ $step->subtitle }}</p>
            @endif

            @if ($image = data_get($step->content, 'image'))
                <div class="rounded-2xl overflow-hidden border border-white/10 mb-6">
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($image) }}"
                         alt="{{ $step->title }}" class="w-full h-auto">
                </div>
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
                    <button type="submit" class="btn-gem !py-3 !px-6">متابعة</button>
                </form>
            @endif
        </div>
    </div>
@endsection