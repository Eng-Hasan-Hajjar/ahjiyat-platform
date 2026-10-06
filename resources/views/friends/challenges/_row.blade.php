@php
    /** @var \App\Models\FriendChallenge $challenge */
    $other = $challenge->challenger_id === auth()->id() ? $challenge->opponent : $challenge->challenger;
    $status = $challenge->effectiveStatus();
    $labels = ['pending' => 'قيد الانتظار', 'accepted' => 'نشط', 'completed' => 'مكتمل', 'declined' => 'مرفوض', 'cancelled' => 'مُلغى', 'expired' => 'منتهٍ'];
    $mine = $challenge->results->firstWhere('user_id', auth()->id());
    $theirs = $status === 'completed' ? $challenge->results->firstWhere('user_id', $other->id) : null;
    $outcome = $status === 'completed' ? ($challenge->is_draw ? 'تعادل' : ($challenge->winner_user_id === auth()->id() ? 'فوز' : 'خسارة')) : null;
@endphp
<x-friend-row :user="$other">
    <div class="text-end">
        <a href="{{ route('friends.challenges.show', $challenge) }}" class="font-bold text-white hover:text-amethyst focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded text-sm block" aria-label="تفاصيل التحدي مع {{ $other->name }}">{{ $challenge->puzzle->title }}</a>
        <div class="text-[11px] text-slate-500 mt-0.5">
            {{ $labels[$status] ?? $status }}
            @if ($outcome) · <strong class="{{ $outcome === 'فوز' ? 'text-emerald-400' : ($outcome === 'خسارة' ? 'text-rose-400' : 'text-slate-300') }}">{{ $outcome }}</strong>
                @if ($mine && $theirs) · {{ $mine->score }} - {{ $theirs->score }} @endif
            @endif
            · {{ ($challenge->completed_at ?? $challenge->created_at)->format('Y-m-d') }}
        </div>
    </div>
</x-friend-row>
