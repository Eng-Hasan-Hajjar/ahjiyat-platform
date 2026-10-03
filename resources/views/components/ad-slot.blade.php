{{-- E14 (بند 539/604): hasAd=false = صفر إخراج إطلاقًا - لا فراغ، لا تخطيط مكسور. --}}
@if ($result->hasAd && $result->providerType === 'direct')
    <div class="glass rounded-2xl p-4 relative overflow-hidden" data-ad-placement="{{ $result->placementId }}">
        <span class="absolute top-2 left-2 text-[10px] font-bold text-slate-400 bg-black/30 rounded-full px-2 py-0.5">
            إعلان مدعوم
        </span>
        <a href="{{ $result->clickUrl }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-4 mt-3">
            @if ($result->imagePath)
                <img src="{{ asset('storage/'.$result->imagePath) }}" alt="{{ e($result->altText ?? $result->title) }}" class="w-16 h-16 rounded-xl object-cover flex-shrink-0">
            @endif
            <div class="min-w-0">
                <p class="font-bold text-white text-sm truncate">{{ $result->title }}</p>
                @if ($result->body)
                    <p class="text-slate-400 text-xs line-clamp-2 mt-1">{{ $result->body }}</p>
                @endif
                @if ($result->ctaLabel)
                    <span class="inline-block mt-2 text-xs font-bold text-gem">{{ $result->ctaLabel }} ←</span>
                @endif
            </div>
        </a>
    </div>
@elseif ($result->hasAd && $result->providerType === 'external')
    {{-- E14 (بند 591/592): مُهيَّأ لمزوِّد خارجي - غير مُفعَّل حاليًا بقرار تشغيلي موثَّق بالتقرير النهائي. لا سكربت Google فعلي يُحمَّل هنا. --}}
    <div class="text-xs text-slate-500" data-ad-external-slot="{{ $result->externalSlotId }}"></div>
@endif
