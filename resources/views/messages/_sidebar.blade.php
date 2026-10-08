{{--
    E22: قائمة المحادثات (عامة، فريق، مباشرة). تُستعمل بصفحة القائمة (كل الأحجام) وبصفحة الغرفة (سطح المكتب فقط). المتغيرات: $conversations, $team, $cap, $teamUnread, $globalUnread.
    العنصر الحالي يُميَّز (aria-current). الشارات عدّادات فقط (لا محتوى رسائل خاصة غير آخر معاينة لصاحب المحادثة). نص عادي فقط، مهرَّب بـ{{ }}.
--}}
@php
    $badge = fn ($n) => $n > $cap ? $cap.'+' : $n;
    $routeUser = request()->route('user');
    $item = 'flex items-center gap-3 rounded-2xl px-3 py-2.5 transition motion-reduce:transition-none focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst';
    $on = 'bg-amethyst/15';
    $off = 'hover:bg-white/5';
@endphp
<nav aria-label="المحادثات" class="glass rounded-3xl p-2 space-y-1">
    <a href="{{ route('community.chat') }}" @if (request()->routeIs('community.chat')) aria-current="page" @endif class="{{ $item }} {{ request()->routeIs('community.chat') ? $on : $off }}">
        <span class="grid place-items-center w-10 h-10 shrink-0 rounded-full bg-emerald/15 text-emerald"><x-ui-icon name="globe" class="w-5 h-5" /></span>
        <span class="min-w-0 flex-1 font-bold text-white text-sm truncate">الدردشة العامة</span>
        @if ($globalUnread > 0)<span class="chip !py-0.5 !px-2 text-xs !text-gold" aria-label="غير مقروء">{{ $badge($globalUnread) }}</span>@endif
    </a>

    @if ($team)
        <a href="{{ route('teams.chat', $team) }}" @if (request()->routeIs('teams.chat')) aria-current="page" @endif class="{{ $item }} {{ request()->routeIs('teams.chat') ? $on : $off }}">
            <span class="grid place-items-center w-10 h-10 shrink-0 rounded-full bg-amethyst/15 text-amethyst"><x-ui-icon name="team" class="w-5 h-5" /></span>
            <span class="min-w-0 flex-1 font-bold text-white text-sm truncate">دردشة {{ $team->name }}</span>
            @if ($teamUnread > 0)<span class="chip !py-0.5 !px-2 text-xs !text-gold" aria-label="غير مقروء">{{ $badge($teamUnread) }}</span>@endif
        </a>
    @endif

    <p class="px-3 pt-3 pb-1 text-[11px] font-black tracking-wide text-slate-500">المحادثات الخاصة</p>

    @forelse ($conversations as $c)
        @if ($c['other'])
            @php $current = $routeUser && $routeUser->is($c['other']); @endphp
            <a href="{{ route('messages.direct', $c['other']) }}" @if ($current) aria-current="page" @endif class="{{ $item }} {{ $current ? $on : $off }}">
                <span class="grid place-items-center w-10 h-10 shrink-0 rounded-full bg-amethyst/20 font-bold text-white" aria-hidden="true">{{ mb_substr($c['other']->name, 0, 1) }}</span>
                <span class="min-w-0 flex-1">
                    <span class="block font-bold text-white text-sm truncate">{{ $c['other']->name }}</span>
                    <span class="block text-xs text-slate-400 truncate">
                        @if ($c['last'])
                            {{ $c['last']->isDeleted() ? 'تم حذف هذه الرسالة' : ($c['last']->isHidden() ? 'أُخفيت هذه الرسالة' : \Illuminate\Support\Str::limit($c['last']->body, 40)) }}
                        @endif
                    </span>
                </span>
                <span class="shrink-0 text-end">
                    <span class="block text-[11px] text-slate-500">{{ $c['thread']->last_message_at?->locale('ar')->diffForHumans(short: true) }}</span>
                    @if ($c['unread'] > 0)<span class="chip !py-0.5 !px-2 text-xs !text-gold" aria-label="غير مقروء">{{ $badge($c['unread']) }}</span>@endif
                </span>
            </a>
        @endif
    @empty
        <p class="px-3 py-6 text-center text-sm text-slate-400">لا محادثات خاصة بعد. افتح صفحة صديق من <a href="{{ route('friends.index') }}" class="text-amethyst underline">الأصدقاء</a> وابدأ المراسلة.</p>
    @endforelse
</nav>
