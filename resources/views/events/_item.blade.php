{{--
    E34: صف حدث واحد (تقويم/مركز المهام). الحالة والعدّ مشتقان خادميًا من تواريخ حقيقية فقط؛ لا عدّ بلا تاريخ. العناوين تُهرَّب. بلا ألوان ثابتة.
    @var \App\Services\LiveOps\Calendar\EventItem $item
--}}
@php
    $tone = match ($item->status) {
        \App\Services\LiveOps\Calendar\EventCalendarService::STATUS_LIVE => 'success',
        \App\Services\LiveOps\Calendar\EventCalendarService::STATUS_ENDING_SOON => 'warning',
        default => 'primary',
    };
    $cd = $item->countdownTo();
    $remaining = $cd ? \App\Services\LiveOps\MissionCenterService::formatRemaining(now(), $cd) : null;
    $upcoming = $item->status === \App\Services\LiveOps\Calendar\EventCalendarService::STATUS_UPCOMING;
@endphp
<li class="puzzle-card !p-4 flex items-start gap-3">
    @if ($item->image)
        <img src="{{ \Illuminate\Support\Facades\Storage::url($item->image) }}" alt="" loading="lazy" class="w-14 h-14 rounded-xl object-cover shrink-0">
    @endif
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2 mb-1">
            <x-status-badge :tone="$tone" small>{{ $item->statusLabel() }}</x-status-badge>
            <x-status-badge small>{{ $item->badge }}</x-status-badge>
        </div>
        <h3 class="font-bold text-white break-words"><a href="{{ $item->url }}" class="hover:text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $item->title }}</a></h3>
        <p class="text-xs text-slate-400 mt-1">
            @if ($item->startsAt)
                <span>يبدأ: <time datetime="{{ $item->startsAt->toIso8601String() }}">{{ $item->startsAt->format('Y-m-d H:i') }}</time></span>
            @endif
            @if ($item->endsAt)
                <span class="ms-2">ينتهي: <time datetime="{{ $item->endsAt->toIso8601String() }}">{{ $item->endsAt->format('Y-m-d H:i') }}</time></span>
            @endif
            @if (! $item->startsAt && ! $item->endsAt)
                <span>متاح بلا موعد انتهاء محدد.</span>
            @endif
        </p>
        @if ($remaining)
            <p class="text-xs font-bold text-slate-300 mt-1">{{ $upcoming ? 'يبدأ بعد' : 'يتبقى' }} {{ $remaining }}</p>
        @endif
    </div>
</li>
