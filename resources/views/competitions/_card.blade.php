@php
    /** @var \App\Models\CompetitiveEvent $event */
    $phaseLabel = match ($event->phase()) {
        'upcoming' => 'قادمة',
        'live' => 'مباشرة الآن',
        default => 'منتهية',
    };
@endphp
<a href="{{ route('competitions.show', $event) }}"
   class="glass rounded-2xl p-5 block hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition">
    <div class="flex items-center justify-between gap-2 mb-2">
        <span class="chip !py-0.5 !px-3 text-xs {{ $event->phase() === 'live' ? '!text-emerald-400' : '' }}">{{ $phaseLabel }}</span>
        @if ($event->is_featured)
            <span class="text-xs font-black text-gold">⭐ مميزة</span>
        @endif
    </div>
    <h3 class="font-display font-black text-white text-lg leading-snug">{{ $event->title }}</h3>
    <p class="text-xs text-slate-400 mt-2">
        {{ $event->starts_at->format('Y-m-d H:i') }} ← {{ $event->ends_at->format('Y-m-d H:i') }}
    </p>
    <p class="text-xs text-slate-500 mt-1">
        المشاركون: {{ $event->participants_count }}@if ($event->max_participants !== null) / {{ $event->max_participants }}@endif
    </p>
</a>
