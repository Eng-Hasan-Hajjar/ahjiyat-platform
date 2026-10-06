@extends('layouts.app')

@section('title', $player->name)

@push('head')
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')

    <div class="max-w-2xl mx-auto">

        <x-player-identity
            :name="$player->name"
            :avatar="$loadout[\App\Models\StoreItem::SLOT_AVATAR]"
            :frame="$loadout[\App\Models\StoreItem::SLOT_FRAME]"
            :badge="$loadout[\App\Models\StoreItem::SLOT_BADGE]"
            :title="$loadout[\App\Models\StoreItem::SLOT_TITLE]"
            :background="$loadout[\App\Models\StoreItem::SLOT_BACKGROUND]"
            class="anim-fade-up"
        />

        <div class="flex justify-center -mt-2 mb-2">
            <span class="chip !py-1 !px-4 text-xs !text-amethyst">المستوى {{ $stats['current_level'] }}</span>
            <span class="chip !py-1 !px-4 text-xs me-2">الأصدقاء {{ $friendsCount }}</span>
        </div>

        @if ($friendRelation !== null)
            <div class="mt-4 glass rounded-2xl p-4 anim-fade-up">
                @include('friends._actions', ['other' => $player, 'relation' => $friendRelation, 'withBlock' => true])
            </div>
        @endif

        @if ($isOwner && ! $player->isProfilePublic())
            <div class="mt-4 rounded-xl bg-white/5 border border-white/10 text-slate-400 text-xs font-bold px-4 py-3 text-center anim-fade-up">
                هذه معاينة لملفك الشخصي - غير مرئي للآخرين حاليًا ({{ $player->isProfilePrivate() ? 'خاص' : 'للأعضاء فقط' }}).
                <a href="{{ route('profile.edit') }}" class="text-amethyst underline">تغيير الخصوصية</a>
            </div>
        @endif

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-6 anim-fade-up d-1">
            <div class="puzzle-card text-center">
                <span class="block font-display font-black text-2xl text-white">{{ $stats['puzzles_solved'] }}</span>
                <span class="text-xs text-slate-400">أحجية محلولة</span>
            </div>
            <div class="puzzle-card text-center">
                <span class="block font-display font-black text-2xl text-white">{{ $stats['challenges_participated'] }}</span>
                <span class="text-xs text-slate-400">تحدٍّ شارك به</span>
            </div>
            <div class="puzzle-card text-center">
                <span class="block font-display font-black text-2xl text-white">{{ $stats['gate_qualifications'] }}</span>
                <span class="text-xs text-slate-400">تأهُّل ضمن حملات</span>
            </div>
            <div class="puzzle-card text-center">
                <span class="block font-display font-black text-2xl text-white">{{ $stats['achievements_unlocked'] }}</span>
                <span class="text-xs text-slate-400">إنجاز مفتوح</span>
            </div>
        </div>

        {{-- E18: ملخص المنافسات (أرقام مجمَّعة من نتائج معتمَدة) + رابط خزانة الجوائز والتاريخ --}}
        <section class="glass rounded-2xl p-5 mt-4 anim-fade-up" aria-labelledby="competitive-title">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="competitive-title" class="font-display font-black text-lg text-white">المنافسات</h2>
                <a href="{{ route('players.competitive', $player) }}" class="chip text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" aria-label="خزانة الجوائز وسجل منافسات {{ $player->name }}">خزانة الجوائز والسجل ←</a>
            </div>
            <dl class="grid grid-cols-3 gap-3 mt-3 text-center">
                <div><dd class="font-display font-black text-xl text-gold">{{ $competitive['events_won'] }}</dd><dt class="text-xs text-slate-400">فوز</dt></div>
                <div><dd class="font-display font-black text-xl text-white">{{ $competitive['top3'] }}</dd><dt class="text-xs text-slate-400">ضمن الثلاثة الأوائل</dt></div>
                <div><dd class="font-display font-black text-xl text-white">{{ $competitive['best_rank'] ?? '—' }}</dd><dt class="text-xs text-slate-400">أفضل مركز</dt></div>
            </dl>
        </section>

    </div>

@endsection