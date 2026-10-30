@extends('layouts.app')

@section('title', $campaign->title)
@section('meta_description', 'موسم «'.$campaign->title.'» على أحجيات: مراحل ومهام وتحديات.')
@section('canonical', route('seasons.show', $season))

@section('content')
    <div class="max-w-4xl mx-auto space-y-8">
        <x-share-button kind="season" :param="$season" :season-id="$season->id" :title="$campaign->title" />

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
            :starts-at="$windowStart"
            :ends-at="$windowEnd"
            :unavailable-label="$unavailableLabel"
        />

        {{-- E42: مركز الموسم (مراحل/إعلانات/محتوى مرتبط/مسار/جدول). الأقسام الفارغة لا تُرسم فيبقى الموسم القديم كما كان. --}}
        @include('seasons._hub')

        {{-- E44: مدخلا كتّاب الموسم وداعمي الموسم (صفر إخراج بلا دوري/تقدير). --}}
        <x-seasonal-recognition-strip :season="$season" />

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