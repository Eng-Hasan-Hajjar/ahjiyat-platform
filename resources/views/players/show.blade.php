@extends('layouts.app')

@section('title', $player->name)
@section('content_width', 'max-w-4xl')

@push('head')
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')
    @php
        use App\Services\Social\FriendRelation;
        $slot = fn (string $s) => $loadout[$s];
        $canMessage = $friendRelation === FriendRelation::Friends;
        $hasIdentity = collect($loadout)->filter()->isNotEmpty();
    @endphp

    <div class="space-y-5">
        {{-- E22: غلاف الملف: الخلفية المجهَّزة = الغلاف، الأفاتار متداخل، والإجراءات منظَّمة (صاحب الملف: تخصيص الهوية + تعديل الحساب | زائر: مراسلة + علاقة الصداقة). --}}
        <x-profile-hero id="profile-hero" class="anim-fade-up"
            :name="$player->name" :avatar="$slot(\App\Models\StoreItem::SLOT_AVATAR)" :frame="$slot(\App\Models\StoreItem::SLOT_FRAME)" :badge="$slot(\App\Models\StoreItem::SLOT_BADGE)"
            :title="$slot(\App\Models\StoreItem::SLOT_TITLE)" :background="$slot(\App\Models\StoreItem::SLOT_BACKGROUND)"
            :level="$stats['current_level']" :team="$team" :friends-count="$friendsCount">
            <x-slot:actions>
                @if ($isOwner)
                    <a href="{{ route('profile.customize') }}" data-cta="customize-identity" class="btn-gem !py-2.5 !px-5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                        <x-ui-icon name="palette" class="w-4 h-4" /> تخصيص الهوية
                    </a>
                    <a href="{{ route('profile.edit') }}" data-cta="edit-account" class="chip !py-2 inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                        <x-ui-icon name="settings" class="w-4 h-4" /> تعديل الحساب
                    </a>
                @elseif ($friendRelation !== null)
                    @if ($canMessage)
                        <a href="{{ route('messages.direct', $player) }}" data-cta="message" class="btn-gem !py-2.5 !px-5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                            <x-ui-icon name="chat" class="w-4 h-4" /> مراسلة
                        </a>
                    @endif
                @endif
            </x-slot:actions>
        </x-profile-hero>

        @if (! $isOwner && $friendRelation !== null)
            <section class="glass rounded-2xl px-4 py-3 anim-fade-up" aria-label="العلاقة مع {{ $player->name }}">
                @include('friends._actions', ['other' => $player, 'relation' => $friendRelation, 'withBlock' => true])
            </section>
        @endif

        @if ($isOwner && ! $player->isProfilePublic())
            <div class="flex items-start gap-3 rounded-2xl bg-white/5 border border-white/10 text-slate-300 text-xs font-bold px-4 py-3 anim-fade-up" role="note">
                <x-ui-icon name="lock" class="w-4 h-4 shrink-0 mt-0.5" />
                <p>هذه معاينة لملفك الشخصي - غير مرئي للآخرين حاليًا ({{ $player->isProfilePrivate() ? 'خاص' : 'للأعضاء فقط' }}).
                    <a href="{{ route('profile.edit') }}#privacy" class="text-amethyst underline">تغيير الخصوصية</a></p>
            </div>
        @endif

        @if ($isOwner && ! $hasIdentity)
            <div class="rounded-2xl border border-amethyst/30 bg-amethyst/10 px-5 py-4 flex flex-wrap items-center justify-between gap-3 anim-fade-up">
                <p class="text-sm font-bold text-white min-w-0">ملفك ما زال بهويته الافتراضية. أضف صورة رمزية وغلافًا ولقبًا يعبّر عنك.</p>
                <a href="{{ route('profile.customize') }}" class="chip !py-2 shrink-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">ابدأ التخصيص</a>
            </div>
        @endif

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 anim-fade-up d-1">
            @foreach ([['puzzles_solved', 'أحجية محلولة'], ['challenges_participated', 'تحدٍّ شارك به'], ['gate_qualifications', 'تأهُّل ضمن حملات'], ['achievements_unlocked', 'إنجاز مفتوح']] as [$key, $label])
                <div class="puzzle-card text-center !p-4">
                    <span class="block font-display font-black text-2xl text-white">{{ $stats[$key] }}</span>
                    <span class="text-xs text-slate-400">{{ $label }}</span>
                </div>
            @endforeach
        </div>

        {{-- E18: ملخص المنافسات (أرقام مجمَّعة من نتائج معتمَدة) + رابط خزانة الجوائز والتاريخ --}}
        <section class="glass rounded-2xl p-5 anim-fade-up" aria-labelledby="competitive-title">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="competitive-title" class="font-display font-black text-lg text-white">المنافسات</h2>
                <a href="{{ route('players.competitive', $player) }}" class="chip text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" aria-label="خزانة الجوائز وسجل منافسات {{ $player->name }}">خزانة الجوائز والسجل ←</a>
            </div>
            @if ($team)
                <p class="mt-3 text-sm text-slate-300">الفريق: <a href="{{ route('teams.show', $team) }}" class="font-bold text-white hover:text-amethyst focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">{{ $team->name }}</a></p>
            @endif
            <dl class="grid grid-cols-3 gap-3 mt-3 text-center">
                <div><dd class="font-display font-black text-xl text-gold">{{ $competitive['events_won'] }}</dd><dt class="text-xs text-slate-400">فوز</dt></div>
                <div><dd class="font-display font-black text-xl text-white">{{ $competitive['top3'] }}</dd><dt class="text-xs text-slate-400">ضمن الثلاثة الأوائل</dt></div>
                <div><dd class="font-display font-black text-xl text-white">{{ $competitive['best_rank'] ?? '—' }}</dd><dt class="text-xs text-slate-400">أفضل مركز</dt></div>
            </dl>
        </section>
    </div>
@endsection
