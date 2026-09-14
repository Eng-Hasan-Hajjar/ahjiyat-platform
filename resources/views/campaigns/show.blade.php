@extends('layouts.app')

@section('title', $campaign->title)

@section('content')
    <div class="max-w-4xl mx-auto">
        <a href="{{ route('campaigns.index') }}"
           class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-white transition mb-4 anim-fade-up">
            → كل الحملات
        </a>

        <div class="puzzle-card !p-6 md:!p-9 anim-fade-up d-1">
            @if ($campaign->cover_image)
                <div class="rounded-2xl overflow-hidden border border-white/10 mb-6">
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($campaign->cover_image) }}"
                         alt="{{ $campaign->title }}" class="w-full h-auto">
                </div>
            @endif

            <h1 class="font-display font-black text-2xl md:text-4xl text-white mb-3">{{ $campaign->title }}</h1>

            @if ($campaign->description)
                <p class="text-slate-400 mb-6">{{ $campaign->description }}</p>
            @endif

            @auth
                <div class="mb-8">
                    <div class="flex items-center justify-between text-sm font-bold text-slate-400 mb-2">
                        <span>تقدّمك</span>
                        <span class="text-gold">{{ $percentage }}٪</span>
                    </div>
                    <div class="h-2 rounded-full bg-white/5 overflow-hidden">
                        <div class="h-full bg-gradient-to-l from-amethyst to-gold" style="width: {{ $percentage }}%"></div>
                    </div>
                </div>

                @if ($currentStep)
                    <a href="{{ route('campaigns.steps.show', [$campaign, $currentStep]) }}"
                       class="btn-gem !py-3 !px-6 inline-flex items-center gap-2 mb-8">
                        متابعة: {{ $currentStep->title }} ←
                    </a>
                @else
                    <div class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-5 py-4 font-bold mb-8">
                        🎉 أكملت هذه الحملة بالكامل!
                    </div>
                @endif
            @else
                <div class="rounded-xl border border-amethyst/30 bg-amethyst/10 text-white px-5 py-4 font-bold mb-8 flex items-center justify-between gap-3">
                    <span>سجّل الدخول لبدء هذه الحملة وتتبّع تقدّمك</span>
                    <a href="{{ route('login') }}" class="btn-gem !py-2 !px-4 shrink-0">تسجيل الدخول</a>
                </div>
            @endauth

            <div class="space-y-8">
                @foreach ($stagesView as $stageView)
                    <div class="anim-fade-up">
                        <h2 class="font-display font-black text-lg text-white mb-3 flex items-center gap-2">
                            @unless ($stageView->unlocked)
                                <span class="text-slate-500">🔒</span>
                            @endunless
                            {{ $stageView->model->title }}
                        </h2>

                        <div class="space-y-3 ps-4 border-s-2 border-white/5">
                            @foreach ($stageView->gates as $gateView)
                                <div class="rounded-xl border border-white/10 p-4">
                                    <div class="flex items-center justify-between mb-3">
                                        <span class="font-bold text-white">{{ $gateView->model->title }}</span>
                                        <x-campaign-state-badge :state="$gateView->state" />
                                    </div>

                                    @if ($gateView->state === \App\Services\CampaignProgressService::STATE_NOT_QUALIFIED)
                                        <p class="text-xs text-slate-400 mb-3">
                                            اكتملت هذه المرحلة، لكن لم يتم التأهل للمرحلة التالية.
                                        </p>
                                    @endif

                                    <div class="grid gap-2">
                                        @foreach ($gateView->steps as $stepView)
                                            @if ($stepView->state === \App\Services\CampaignProgressService::STATE_LOCKED)
                                                <div class="flex items-center gap-2 text-sm text-slate-500 px-3 py-2 rounded-lg bg-white/5">
                                                    <span>🔒</span> خطوة مقفلة
                                                </div>
                                            @else
                                                <a href="{{ route('campaigns.steps.show', [$campaign, $stepView->model]) }}"
                                                   class="flex items-center justify-between gap-2 text-sm px-3 py-2 rounded-lg bg-white/5 hover:bg-white/10 transition">
                                                    <span class="text-white">{{ $stepView->model->title }}</span>
                                                    <x-campaign-state-badge :state="$stepView->state" small />
                                                </a>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endsection