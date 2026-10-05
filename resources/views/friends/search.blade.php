@extends('layouts.app')

@section('title', 'البحث عن لاعبين')

@section('content')
    <div class="max-w-3xl mx-auto space-y-5">

        <div class="flex flex-wrap items-center justify-between gap-3 anim-fade-up">
            <h1 class="font-display font-black text-2xl text-white">البحث عن لاعبين</h1>
            <a href="{{ route('friends.index') }}" class="chip">← الأصدقاء</a>
        </div>

        <form method="GET" action="{{ route('friends.search') }}" role="search" class="glass rounded-3xl p-5 flex flex-wrap items-center gap-3 anim-fade-up">
            <label for="q" class="sr-only">اسم اللاعب</label>
            <input id="q" type="search" name="q" value="{{ $term }}" maxlength="50" minlength="{{ $minLength }}" placeholder="اسم اللاعب (حرفان على الأقل)"
                class="flex-1 min-w-[12rem] rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white placeholder:text-slate-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" autocomplete="off">
            <button type="submit" class="btn-gem !py-2 !px-5 text-sm">بحث</button>
        </form>

        @if ($errors->has('q'))
            <p class="text-rose-400 text-sm">{{ $errors->first('q') }}</p>
        @endif

        @if ($results === null)
            <p class="text-center text-slate-500 text-sm py-8">
                @if ($term === '') اكتب اسم لاعب للبحث. @else اكتب {{ $minLength }} أحرف على الأقل. @endif
            </p>
        @else
            <div class="glass rounded-2xl divide-y divide-white/5 anim-fade-up">
                @forelse ($results as $person)
                    <x-friend-row :user="$person">
                        @include('friends._actions', ['other' => $person, 'relation' => $relations[$person->id] ?? \App\Services\Social\FriendRelation::Unavailable])
                    </x-friend-row>
                @empty
                    <p class="px-4 py-10 text-center text-slate-500 text-sm">لا نتائج مطابقة.</p>
                @endforelse
            </div>
            <div>{{ $results->links() }}</div>
        @endif
    </div>
@endsection
