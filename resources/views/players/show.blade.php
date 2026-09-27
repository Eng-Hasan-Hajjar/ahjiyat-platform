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

        @if ($isOwner && ! $player->isProfilePublic())
            <div class="mt-4 rounded-xl bg-white/5 border border-white/10 text-slate-400 text-xs font-bold px-4 py-3 text-center anim-fade-up">
                هذه معاينة لملفك الشخصي - غير مرئي للآخرين حاليًا ({{ $player->isProfilePrivate() ? 'خاص' : 'للأعضاء فقط' }}).
                <a href="{{ route('profile.edit') }}" class="text-amethyst underline">تغيير الخصوصية</a>
            </div>
        @endif

        <div class="grid grid-cols-3 gap-3 mt-6 anim-fade-up d-1">
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
        </div>

    </div>

@endsection