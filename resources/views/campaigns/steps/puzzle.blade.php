@extends('layouts.app')

@section('title', $step->title)

@section('content')
    <div class="max-w-3xl mx-auto">
        <a href="{{ route('campaigns.show', $campaign) }}"
           class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-white transition mb-4 anim-fade-up">
            → {{ $campaign->title }}
        </a>

        <div class="puzzle-card !p-6 md:!p-9 anim-fade-up d-1">
            <div class="flex flex-wrap items-center gap-2 mb-5">
                <span class="chip !py-1 !px-3">{{ $step->title }}</span>
                @if ($step->reward_mode === \App\Models\CampaignStep::REWARD_MODE_NONE)
                    <span class="ms-auto text-sm font-black text-slate-500">بلا مكافأة</span>
                @elseif ($step->reward_mode === \App\Models\CampaignStep::REWARD_MODE_OVERRIDE)
                    <span class="ms-auto text-sm font-black text-gold">+{{ $step->reward_override_amount }} 💎</span>
                @else
                    <span class="ms-auto text-sm font-black text-gold">+{{ $puzzle->gem_reward ?? 0 }} 💎</span>
                @endif
            </div>

            <h1 class="font-display font-black text-2xl md:text-4xl text-white mb-4 leading-tight">
                {{ $puzzle->prompt }}
            </h1>

            @if (data_get($step->content, 'media_path'))
                <x-media-or-placeholder
                    :path="data_get($step->content, 'media_path')"
                    :type="data_get($step->content, 'media_type', 'image')"
                    :caption="data_get($step->content, 'caption')"
                />
            @endif

            @if ($completed)
                <div class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-5 py-4 font-bold anim-fade-up d-2">
                    🎉 أحسنت! أكملت هذه الخطوة
                </div>
            @elseif ($attemptsUsed >= $puzzle->max_attempts)
                <div class="rounded-xl border border-rose/30 bg-rose/10 text-rose px-5 py-4 font-bold anim-fade-up d-2">
                    ❌ استنفدت جميع محاولاتك المسموحة لهذه الخطوة
                </div>
            @else
                @if ($usesGameSession)
                    @php($sessionStartUrl = route('campaigns.steps.session', [$campaign, $step]))
                    <div class="mt-6 anim-fade-up d-2">
                        @include($renderer)
                    </div>
                @else
                    <form method="POST" action="{{ route('campaigns.steps.attempt', [$campaign, $step]) }}" class="mt-6 anim-fade-up d-2">
                        @csrf
                        @include($renderer)
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span class="text-sm font-bold text-slate-400">
                                محاولة {{ $attemptsUsed + 1 }} من {{ $puzzle->max_attempts }}
                            </span>
                            <button type="submit" class="btn-gem !py-3 !px-6">إرسال الإجابة</button>
                        </div>
                    </form>
                @endif
            @endif
        </div>
    </div>
@endsection