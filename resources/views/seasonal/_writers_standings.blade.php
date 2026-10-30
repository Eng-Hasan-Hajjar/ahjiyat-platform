{{--
    E44 - الترتيب الحيّ لكتّاب الموسم (جزء علني بالكامل: الاسم العام، النجوم، عدد الناخبين، المرتبة، النتيجة بعد الإنهاء). لا هوية ناخب ولا توزيع فردي.
    يُرسَم مع الصفحة ويُستبدل عبر البثّ/الاستطلاع. المتغيّر: $board (SeasonWriterLeaderboardService::publicBoard).
--}}
@php
    $outcomes = [
        'first_place' => ['المركز الأول', 'success'],
        'second_place' => ['المركز الثاني', 'primary'],
        'third_place' => ['المركز الثالث', 'primary'],
        'participant' => ['مشارك', 'neutral'],
        'ineligible_next_season' => ['خارج قائمة الموسم التالي', 'warning'],
    ];
@endphp
@if ($board['rows'] === [])
    <p class="text-sm text-slate-400 py-6 text-center">لا كتّاب في الترتيب بعد.</p>
@else
    <ol class="space-y-2" data-standings data-finalized="{{ $board['finalized'] ? '1' : '0' }}">
        @foreach ($board['rows'] as $row)
            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-2xl border border-white/10 bg-white/5 px-4 py-3" data-rank="{{ $row['rank'] }}">
                <span class="w-9 shrink-0 text-center font-display font-black text-lg tabular-nums {{ $row['rank'] <= 3 ? 'text-gold' : 'text-slate-400' }}" aria-label="المرتبة {{ $row['rank'] }}">{{ $row['rank'] }}</span>
                <a href="{{ route('creators.show', $row['slug']) }}" class="min-w-0 flex-1 font-black text-white hover:underline break-words focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">{{ $row['name'] }}</a>
                @if ($board['finalized'] && isset($outcomes[$row['outcome']]))
                    <x-status-badge :tone="$outcomes[$row['outcome']][1]" small>{{ $outcomes[$row['outcome']][0] }}</x-status-badge>
                @endif
                <span class="text-sm font-bold text-slate-200 tabular-nums" title="مجموع النجوم">★ {{ number_format($row['stars']) }}</span>
                <span class="text-xs text-slate-500 tabular-nums">{{ number_format($row['voters']) }} ناخب</span>
            </li>
        @endforeach
    </ol>
@endif
