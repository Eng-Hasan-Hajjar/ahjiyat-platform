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
            :campaign-available="$campaignAvailable"
        />

        {{-- E30: «برعاية» (بيانات منظَّمة معزولة الفشل؛ بلا رعاة = لا إخراج). --}}
        <x-sponsor-strip type="season" :target-id="$season->id" />

        @auth
            @if ($campaignAvailable && $currentStep)
                <x-mission-card
                    :campaign="$campaign"
                    :step="$currentStep"
                    :mission-number="$currentStepMissionNumber"
                />
            @endif
        @endauth

        <div class="puzzle-card !p-6 md:!p-9 anim-fade-up">
            <h2 class="font-display font-black text-xl text-white mb-6">خريطة الرحلة</h2>
            <x-story-map :campaign="$campaign" :stages-view="$stagesView" />
        </div>
    </div>
@endsection