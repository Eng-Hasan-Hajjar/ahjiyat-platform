@props(['challenge', 'teamId'])
@php
    $other = $challenge->challenger_team_id === $teamId ? $challenge->opponent : $challenge->challenger;
    $labels = ['pending' => 'بانتظار الرد', 'accepted' => 'جارٍ', 'completed' => 'انتهى', 'declined' => 'مرفوض', 'cancelled' => 'مُلغى', 'expired' => 'منتهي'];
    $status = $challenge->effectiveStatus();
    $mine = $challenge->results->firstWhere('team_id', $teamId);
@endphp
<a href="{{ route('teams.challenges.show', $challenge) }}" class="glass rounded-2xl p-4 flex flex-wrap items-center justify-between gap-3 hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition"
   aria-label="تحدّي ضد {{ $other->name }}">
    <div class="min-w-0">
        <div class="font-bold text-white truncate">{{ $challenge->challenger_team_id === $teamId ? 'ضد' : 'من' }} {{ $other->name }}</div>
        <div class="text-xs text-slate-400">{{ $challenge->puzzle->title }}</div>
    </div>
    <div class="text-xs text-slate-300 text-end">
        <span class="chip !py-0.5 !px-2">{{ $labels[$status] ?? $status }}</span>
        @if ($status === 'completed')
            <div class="mt-1">{{ $challenge->is_draw ? 'تعادل' : ($challenge->winner_team_id === $teamId ? 'فوز' : 'خسارة') }} · {{ $mine?->score }} - {{ $challenge->results->firstWhere('team_id', '!=', $teamId)?->score }}</div>
        @endif
    </div>
</a>
