@extends('layouts.app')

@section('title', 'الرسائل')

@section('content')
    @php $badge = fn ($n) => $n > $cap ? $cap.'+' : $n; @endphp
    <div class="max-w-3xl mx-auto space-y-5">
        <div>
            <h1 class="font-display font-black text-2xl text-white">الرسائل</h1>
            <p class="text-sm text-slate-400 mt-1">محادثات الأصدقاء الخاصة، ودردشة فريقك، والدردشة العامة. نص عادي فقط.</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <a href="{{ route('community.chat') }}" class="glass rounded-2xl p-4 flex items-center justify-between hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition">
                <span class="font-bold text-white">🌍 الدردشة العامة</span>
                @if ($globalUnread > 0)<span class="chip !py-0.5 !px-2 text-xs text-gold" aria-label="غير مقروء">{{ $badge($globalUnread) }}</span>@endif
            </a>
            @if ($team)
                <a href="{{ route('teams.chat', $team) }}" class="glass rounded-2xl p-4 flex items-center justify-between hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition">
                    <span class="font-bold text-white truncate">👥 دردشة {{ $team->name }}</span>
                    @if ($teamUnread > 0)<span class="chip !py-0.5 !px-2 text-xs text-gold" aria-label="غير مقروء">{{ $badge($teamUnread) }}</span>@endif
                </a>
            @endif
        </div>

        <section class="space-y-2" aria-labelledby="dm-title">
            <h2 id="dm-title" class="font-display font-black text-lg text-white">المحادثات الخاصة</h2>
            @forelse ($conversations as $c)
                @if ($c['other'])
                    <a href="{{ route('messages.direct', $c['other']) }}" class="glass rounded-2xl p-3 flex items-center gap-3 hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition">
                        <span class="shrink-0 w-10 h-10 rounded-full bg-amethyst/20 grid place-items-center font-bold text-white" aria-hidden="true">{{ mb_substr($c['other']->name, 0, 1) }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-bold text-white truncate">{{ $c['other']->name }}</span>
                            <span class="block text-xs text-slate-400 truncate">
                                @if ($c['last'])
                                    {{ $c['last']->isDeleted() ? 'تم حذف هذه الرسالة' : ($c['last']->isHidden() ? 'أُخفيت هذه الرسالة' : \Illuminate\Support\Str::limit($c['last']->body, 70)) }}
                                @endif
                            </span>
                        </span>
                        <span class="shrink-0 text-end">
                            <span class="block text-[11px] text-slate-500">{{ $c['thread']->last_message_at?->diffForHumans(short: true) }}</span>
                            @if ($c['unread'] > 0)<span class="chip !py-0.5 !px-2 text-xs text-gold" aria-label="غير مقروء">{{ $badge($c['unread']) }}</span>@endif
                        </span>
                    </a>
                @endif
            @empty
                <p class="glass rounded-2xl px-4 py-8 text-center text-sm text-slate-500">لا محادثات خاصة بعد. افتح صفحة صديق من <a href="{{ route('friends.index') }}" class="text-amethyst underline">الأصدقاء</a> وابدأ المحادثة.</p>
            @endforelse
        </section>
    </div>
@endsection
