{{--
    خريطة القصة (Story Map) - Stage → Gate → Mission. كل الحالات (locked/
    available/in_progress/completed/not_qualified) قادمة جاهزة من
    $stagesView (مبنية بالـController عبر CampaignProgressService) - لا
    اشتقاق منطق هون إطلاقاً. contentStatus (technical_pending/content_pending)
    Metadata إدارية فقط، null دائماً لغير Admin - لا تُعرض للجمهور مطلقاً.
--}}
@props(['campaign', 'stagesView'])

@php $missionNumber = 0; @endphp

<div class="story-map">
    @foreach ($stagesView as $stageView)
        <div class="story-map-stage">
            <h2 class="font-display font-black text-lg text-white mb-3 flex items-center gap-2">
                @unless ($stageView->unlocked)
                    <span class="text-slate-500">🔒</span>
                @endunless
                {{ $stageView->model->title }}
            </h2>

            <div class="space-y-4">
                @foreach ($stageView->gates as $gateView)
                    <div class="rounded-xl border border-white/10 p-4">
                                                <div class="flex items-center justify-between mb-3">
                            <span class="font-bold text-white text-sm">{{ $gateView->model->title }}</span>
                            <span class="flex items-center gap-1.5">
                                @if ($gateView->qualifiedRank ?? null)
                                    <span class="chip !py-0.5 !px-2 text-[10px] !text-gold">تأهّلت - الترتيب #{{ $gateView->qualifiedRank }}</span>
                                @endif
                                <x-campaign-state-badge :state="$gateView->state" small />
                            </span>
                        </div>

                        @if ($gateView->state === \App\Services\CampaignProgressService::STATE_NOT_QUALIFIED)
                            <p class="text-xs text-slate-400 mb-3">
                                أكملت هذه المرحلة، لكن المقاعد المتاحة للتأهل للمرحلة التالية اكتملت.
                            </p>
                        @endif

                    

                        <div class="grid gap-2">
                            @foreach ($gateView->steps as $stepView)
                                @php $missionNumber++; @endphp

                                @if ($stepView->state === \App\Services\CampaignProgressService::STATE_LOCKED)
                                    <div class="mission-node mission-node--locked">
                                        <span class="text-sm">🔒 مهمة مقفلة</span>
                                        <span class="text-xs">#{{ $missionNumber }}</span>
                                    </div>
                                @else
                                    <a href="{{ route('campaigns.steps.show', [$campaign, $stepView->model]) }}"
                                       class="mission-node mission-node--{{ $stepView->state }}">
                                        <span class="text-sm text-white">#{{ $missionNumber }} — {{ $stepView->model->title }}</span>
                                        <span class="flex items-center gap-1.5">
                                            @if (($stepView->contentStatus ?? null) && $stepView->contentStatus !== \App\Models\CampaignStep::CONTENT_STATUS_FINAL)
                                                <x-campaign-state-badge :state="$stepView->contentStatus" small />
                                            @endif
                                            <x-campaign-state-badge :state="$stepView->state" small />
                                        </span>
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