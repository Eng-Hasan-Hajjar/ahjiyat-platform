@php
    /** @var array $board */
    $medals = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
@endphp
<section aria-labelledby="board-title" class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 id="board-title" class="font-display font-black text-lg text-white">{{ $board['is_final'] ? 'النتائج النهائية' : 'الترتيب المؤقت' }}</h2>
            @unless ($board['is_final'])
                <p class="text-xs text-amber-400 mt-0.5">هذا الترتيب مؤقت وغير نهائي، وقد يتغيّر حتى انتهاء المنافسة.</p>
            @endunless
        </div>
        @auth
            <div class="flex gap-2" role="group" aria-label="نطاق الترتيب">
                <a href="{{ route('competitions.show', $event) }}" class="chip {{ $scope === 'global' ? '!border-amethyst !text-amethyst' : '' }}" aria-current="{{ $scope === 'global' ? 'page' : 'false' }}">عام</a>
                <a href="{{ route('competitions.show', [$event, 'scope' => 'friends']) }}" class="chip {{ $scope === 'friends' ? '!border-amethyst !text-amethyst' : '' }}" aria-current="{{ $scope === 'friends' ? 'page' : 'false' }}">الأصدقاء</a>
            </div>
        @endauth
    </div>

    @if ($board['my_rank'] !== null)
        <div class="glass rounded-2xl px-4 py-3 text-sm text-white">
            رتبتك: <strong class="text-amethyst">{{ $board['my_rank'] }}</strong>
            · نقاطك: <strong>{{ $board['my_result']->score }}</strong>
            · زمنك: <strong>{{ number_format($board['my_result']->duration_ms / 1000, 2) }} ث</strong>
        </div>
    @endif

    <div class="glass rounded-2xl divide-y divide-white/5 overflow-hidden">
        @forelse ($board['rows'] as $row)
            <div class="flex items-center gap-3 px-4 py-3 {{ $row['is_me'] ? 'bg-amethyst/10' : '' }}">
                <span class="w-8 text-center font-black {{ $row['rank'] <= 3 ? 'text-gold text-lg' : 'text-slate-400' }}">{{ $medals[$row['rank']] ?? $row['rank'] }}</span>
                @if ($row['user'])
                    <x-friend-row :user="$row['user']" class="!px-0 !py-0 flex-1 min-w-0" />
                @endif
                <div class="text-end shrink-0">
                    <div class="font-black text-white">{{ $row['result']->score }}</div>
                    <div class="text-[11px] text-slate-500">{{ number_format($row['result']->duration_ms / 1000, 2) }} ث · {{ $row['result']->completed_at->format('m-d H:i') }}</div>
                </div>
            </div>
        @empty
            <p class="px-4 py-10 text-center text-slate-500 text-sm">{{ $scope === 'friends' ? 'لا نتائج لك أو لأصدقائك بعد.' : 'لا نتائج مسجَّلة بعد.' }}</p>
        @endforelse
    </div>

    <div>{{ $board['paginator']->links() }}</div>
</section>
