@extends('layouts.app')

@section('title', $campaign->title)

@section('content')
    <div class="max-w-4xl mx-auto space-y-8">
        @if ($isAdmin && ! $season->is_published)
            <div class="rounded-xl border border-gold/30 bg-gold/10 text-gold px-5 py-3 font-bold text-sm anim-fade-up">
                👁️ وضع المعاينة — غير منشور للجمهور بعد.
            </div>
        @endif

        <x-season-hero
            :season="$season"
            :campaign="$campaign"
            :availability-label="$availabilityLabel"
            :current-step="$currentStep"
            :percentage="$percentage"
        />

        @auth
            @if (! $campaignAvailable)
                <div class="rounded-xl border border-rose/30 bg-rose/10 text-rose px-5 py-4 font-bold anim-fade-up">
                    {{ $availabilityLabel }} - لا يمكن اللعب حالياً.
                </div>
            @elseif ($currentStep)
                <x-mission-card :campaign="$campaign" :step="$currentStep" />
            @endif
        @endauth

        <div class="puzzle-card !p-6 md:!p-9 anim-fade-up">
            <h2 class="font-display font-black text-xl text-white mb-6">خريطة الرحلة</h2>
            <x-story-map :campaign="$campaign" :stages-view="$stagesView" />
        </div>
    </div>
@endsection