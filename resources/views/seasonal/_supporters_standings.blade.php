{{--
    E44 - ترتيب «داعمو الموسم» (لاعبون فقط، أعلى 100، بالنقاط العلنية). الاسم العام فقط؛ لا مجهول ولا ملف E30 غير علني.
    المتغيّر: $board (SeasonSupporterLeaderboardService::publicBoard).
--}}
@php $tierNames = collect(config('seasonal_recognition.supporters.card_tiers'))->map(fn ($t) => $t['name']); @endphp
@if ($board['rows'] === [])
    <p class="text-sm text-slate-400 py-6 text-center">لا داعمين في الترتيب بعد.</p>
@else
    <ol class="space-y-2" data-standings data-finalized="{{ $board['finalized'] ? '1' : '0' }}">
        @foreach ($board['rows'] as $row)
            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-2xl border border-white/10 bg-white/5 px-4 py-3" data-rank="{{ $row['rank'] }}">
                <span class="w-9 shrink-0 text-center font-display font-black text-lg tabular-nums {{ $row['rank'] <= 3 ? 'text-gold' : 'text-slate-400' }}" aria-label="المرتبة {{ $row['rank'] }}">{{ $row['rank'] }}</span>
                <x-season-supporter-name :tier="$row['tier']" class="min-w-0 flex-1 font-black break-words">{{ $row['name'] }}</x-season-supporter-name>
                @if ($row['tier'])
                    <x-status-badge small>{{ $tierNames[$row['tier']] ?? '' }}</x-status-badge>
                @endif
                <span class="text-sm font-bold text-slate-200 tabular-nums" title="نقاط الدعم (للتقدير فقط)">{{ number_format($row['points']) }} نقطة</span>
            </li>
        @endforeach
    </ol>
@endif
