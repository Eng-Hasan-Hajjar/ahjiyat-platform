@extends('layouts.app')

@section('title', 'الفرق')

@section('content')
    <div class="max-w-5xl mx-auto space-y-6">
        <x-page-header title="الفرق" subtitle="انضم إلى فريق أو أنشئ فريقك. الفريق للتعارف والمنافسة المشتركة، ولا يمنح نقاطًا ولا جوائز ولا أفضلية." icon="team" class="!mb-0 anim-fade-up">
            <x-slot:actions>
                <a href="{{ route('teams.leaderboard') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="chart" class="w-4 h-4" />جدول الفرق</a>
                <a href="{{ route('team-championships.index') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="trophy" class="w-4 h-4" />بطولات الفرق</a>
                @auth
                    @if (auth()->user()->hasVerifiedEmail())
                        <a href="{{ route('teams.challenges.index') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="bolt" class="w-4 h-4" />تحدّياتي</a>
                        <a href="{{ route('teams.invitations') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="mail" class="w-4 h-4" />دعواتي</a>
                        <a href="{{ route('teams.mine') }}" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">فريقي</a>
                        <a href="{{ route('teams.create') }}" class="btn-gem !py-2 !px-5 text-sm">إنشاء فريق</a>
                    @endif
                @endauth
            </x-slot:actions>
        </x-page-header>

        <form method="GET" action="{{ route('teams.index') }}" role="search" class="glass rounded-3xl p-5 flex flex-wrap items-center gap-3 anim-fade-up">
            <label for="q" class="sr-only">اسم الفريق</label>
            <input id="q" type="search" name="q" value="{{ $term }}" maxlength="40" placeholder="ابحث باسم الفريق" autocomplete="off"
                class="flex-1 min-w-[12rem] rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white placeholder:text-slate-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
            <button type="submit" class="btn-gem !py-2 !px-5 text-sm">بحث</button>
        </form>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($teams as $team)
                <a href="{{ route('teams.show', $team) }}" class="glass rounded-3xl p-5 block hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition anim-fade-up"
                   aria-label="فريق {{ $team->name }}">
                    <div class="flex items-center gap-3">
                        @include('teams._avatar', ['team' => $team])
                        <div class="min-w-0">
                            <div class="font-bold text-white truncate">{{ $team->name }}</div>
                            <div class="text-xs text-slate-400">{{ $team->members_count }} / {{ $team->capacity() }} عضو</div>
                        </div>
                    </div>
                    @if ($team->description)
                        <p class="text-sm text-slate-400 mt-3 line-clamp-2">{{ $team->description }}</p>
                    @endif
                    <div class="mt-3 text-xs text-slate-500">{{ ['open' => 'انضمام مفتوح', 'request' => 'بطلب انضمام', 'invite_only' => 'بدعوة فقط'][$team->join_policy] ?? '' }}{{ $team->isFull() ? ' · مكتمل' : '' }}</div>
                </a>
            @empty
                <x-empty-state icon="team" title="لا فرق مطابقة بعد." message="جرّب اسمًا آخر، أو أنشئ فريقك وادعُ أصدقاءك." class="sm:col-span-2 lg:col-span-3" />
            @endforelse
        </div>

        <div>{{ $teams->links() }}</div>
    </div>
@endsection
