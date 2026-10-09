@php
    /** @var \App\Models\CompetitiveEvent $event */
    $phase = $event->phase();
    $phaseLabel = match ($phase) {
        'upcoming' => 'قادمة',
        'live' => 'مباشرة الآن',
        default => 'منتهية',
    };
    $cta = match ($phase) {
        'live' => 'شارك الآن',
        'upcoming' => 'التفاصيل والتسجيل',
        default => 'عرض النتائج',
    };
@endphp
{{-- E22: بطاقة حدث: مؤشر «مباشر» وCTA حسب الحالة. البطاقة كلها رابط واحد (هدف لمس واسع). --}}
<a href="{{ route('competitions.show', $event) }}"
   class="glass rounded-2xl p-5 flex flex-col h-full hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none {{ $phase === 'live' ? '!border-emerald/40' : '' }}">
    <div class="flex items-center justify-between gap-2 mb-2">
        <x-status-badge :tone="$phase === 'live' ? 'success' : ($phase === 'upcoming' ? 'warning' : 'neutral')">
            @if ($phase === 'live')<span aria-hidden="true" class="w-1.5 h-1.5 rounded-full bg-emerald"></span>@endif{{ $phaseLabel }}
        </x-status-badge>
        @if ($event->is_featured)
            <span class="inline-flex items-center gap-1 text-xs font-black text-gold"><x-ui-icon name="sparkles" class="w-3.5 h-3.5" /> مميزة</span>
        @endif
    </div>
    <h3 class="font-display font-black text-white text-lg leading-snug">{{ $event->title }}</h3>
    <p class="text-xs text-slate-400 mt-2">
        {{ $event->starts_at->format('Y-m-d H:i') }} ← {{ $event->ends_at->format('Y-m-d H:i') }}
    </p>
    <p class="text-xs text-slate-500 mt-1">
        المشاركون: {{ $event->participants_count }}@if ($event->max_participants !== null) / {{ $event->max_participants }}@endif
    </p>
    <span class="mt-auto pt-4 inline-flex items-center gap-1 text-sm font-bold {{ $phase === 'ended' ? 'text-slate-400' : 'text-amethyst' }}">{{ $cta }} <x-ui-icon name="chevron-left" class="w-3.5 h-3.5" /></span>
</a>
