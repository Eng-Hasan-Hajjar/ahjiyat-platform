@extends('layouts.app')

@section('title', 'تحدّي فريق')

@section('content')
    <div class="max-w-3xl mx-auto space-y-6">
        <x-page-header :title="'تحدّي فريق «'.$opponent->name.'»'" icon="bolt" subtitle="اختر الأحجية والروستر. يُختار روستر الخصم عند قبوله، ويُقفل الروستران عند القبول فلا تبديل بعده. لا رسوم ولا جوائز." :back="route('teams.show', $opponent)" backLabel="صفحة الفريق" class="anim-fade-up !mb-0" />

        <form method="GET" action="{{ route('teams.challenges.create') }}" role="search" class="glass rounded-3xl p-5 flex flex-wrap items-center gap-3">
            <input type="hidden" name="opponent" value="{{ $opponent->slug }}">
            <label for="q" class="sr-only">بحث عن أحجية</label>
            <input id="q" type="search" name="q" value="{{ $term }}" maxlength="50" placeholder="ابحث بعنوان الأحجية" class="flex-1 min-w-[12rem] rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
            <button type="submit" class="btn-gem !py-2 !px-5 text-sm">بحث</button>
        </form>

        <form method="POST" action="{{ route('teams.challenges.store') }}" class="glass rounded-3xl p-6 space-y-6">
            @csrf
            <input type="hidden" name="opponent" value="{{ $opponent->slug }}">

            <fieldset>
                <legend class="font-bold text-white mb-2">الأحجية</legend>
                <div class="space-y-2">
                    @forelse ($puzzles as $puzzle)
                        <label class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm cursor-pointer">
                            <input type="radio" name="puzzle_id" value="{{ $puzzle->id }}" required @checked((int) old('puzzle_id') === $puzzle->id) class="accent-violet-500">
                            <span class="text-white">{{ $puzzle->title }}</span>
                            <span class="ms-auto text-xs text-slate-400">{{ $puzzle->time_limit_seconds ? $puzzle->time_limit_seconds.' ث' : 'بلا حد زمني' }}</span>
                        </label>
                    @empty
                        <p class="text-sm text-slate-500">لا أحجيات صالحة للمنافسة مطابقة.</p>
                    @endforelse
                </div>
                <div class="mt-3">{{ $puzzles->links() }}</div>
            </fieldset>

            <fieldset>
                <legend class="font-bold text-white mb-1">روستر فريقكم</legend>
                <p class="text-xs text-slate-500 mb-2">من {{ config('teams.challenges.roster_min') }} إلى {{ config('teams.challenges.roster_max') }} لاعبين؛ يُحتسب أفضل {{ config('teams.ranking_top_n') }} نتائج صحيحة منهم.</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($candidates as $m)
                        <label class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm cursor-pointer">
                            <input type="checkbox" name="roster[]" value="{{ $m->user->public_id }}" @checked(in_array($m->user->public_id, (array) old('roster', []), true)) class="accent-violet-500">
                            <span class="text-white truncate">{{ $m->user->name }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <button type="submit" class="btn-gem w-full">أرسل التحدّي</button>
        </form>
    </div>
@endsection
