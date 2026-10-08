@extends('layouts.app')

@section('title', 'الأصدقاء')

@section('content')
    <div class="max-w-3xl mx-auto space-y-6">

        <x-page-header title="الأصدقاء" subtitle="قائمة أصدقائك خاصة بك ولا تظهر لغيرك." icon="friends" class="!mb-0 anim-fade-up">
            <x-slot:actions>
                <a href="{{ route('friends.challenges.index') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="bolt" class="w-4 h-4" />تحدّياتي</a>
                <a href="{{ route('friends.search') }}" class="btn-gem !py-2 !px-4 text-sm">ابحث عن لاعبين</a>
            </x-slot:actions>
        </x-page-header>

        {{-- إعداد الخصوصية: استقبال طلبات جديدة. الأصدقاء الحاليون لا يتأثرون بالتعطيل. --}}
        <form method="POST" action="{{ route('friends.settings') }}" class="glass rounded-3xl p-5 flex items-center justify-between gap-4 anim-fade-up">
            @csrf @method('PATCH')
            <label class="flex items-start gap-3 cursor-pointer min-w-0">
                <input type="hidden" name="friend_requests_enabled" value="0">
                <input type="checkbox" name="friend_requests_enabled" value="1" @checked($requestsEnabled)
                    class="mt-1 w-5 h-5 rounded accent-violet-500 focus:ring-2 focus:ring-amethyst">
                <span>
                    <span class="block font-bold text-white text-sm">السماح بطلبات صداقة جديدة</span>
                    <span class="block text-xs text-slate-400 mt-0.5">عند التعطيل لا يستطيع أحد إرسال طلب جديد لك. أصدقاؤك الحاليون يبقون كما هم.</span>
                </span>
            </label>
            <button type="submit" class="chip shrink-0">حفظ</button>
        </form>

        <section class="anim-fade-up" aria-labelledby="incoming-title">
            <h2 id="incoming-title" class="font-display font-black text-lg text-white mb-3">الطلبات الواردة @if ($incoming->isNotEmpty())<span class="text-amethyst text-sm">({{ $incoming->count() }})</span>@endif</h2>
            <div class="glass rounded-2xl divide-y divide-white/5">
                @forelse ($incoming as $request)
                    <x-friend-row :user="$request->requester">
                        @include('friends._actions', ['other' => $request->requester, 'relation' => \App\Services\Social\FriendRelation::IncomingPending])
                    </x-friend-row>
                @empty
                    <p class="px-4 py-8 text-center text-slate-500 text-sm">لا توجد طلبات واردة.</p>
                @endforelse
            </div>
        </section>

        <section class="anim-fade-up" aria-labelledby="outgoing-title">
            <h2 id="outgoing-title" class="font-display font-black text-lg text-white mb-3">الطلبات المرسلة</h2>
            <div class="glass rounded-2xl divide-y divide-white/5">
                @forelse ($outgoing as $request)
                    <x-friend-row :user="$request->addressee">
                        @include('friends._actions', ['other' => $request->addressee, 'relation' => \App\Services\Social\FriendRelation::OutgoingPending])
                    </x-friend-row>
                @empty
                    <p class="px-4 py-8 text-center text-slate-500 text-sm">لم ترسل أي طلب بعد.</p>
                @endforelse
            </div>
        </section>

        <section class="anim-fade-up" aria-labelledby="friends-title">
            <h2 id="friends-title" class="font-display font-black text-lg text-white mb-3">أصدقائي</h2>
            <div class="glass rounded-2xl divide-y divide-white/5">
                @forelse ($friends as $friend)
                    <x-friend-row :user="$friend">
                        <a href="{{ route('messages.direct', $friend) }}" class="chip text-xs inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="chat" class="w-3.5 h-3.5" /> راسل</a>
                        @include('friends._actions', ['other' => $friend, 'relation' => \App\Services\Social\FriendRelation::Friends])
                    </x-friend-row>
                @empty
                    <div class="px-4 py-10 text-center text-slate-500 text-sm">
                        لا أصدقاء بعد. <a href="{{ route('friends.search') }}" class="text-amethyst underline">ابحث عن لاعبين</a> وأرسل طلب صداقة.
                    </div>
                @endforelse
            </div>
            <div class="mt-4">{{ $friends->links() }}</div>
        </section>

        <section class="anim-fade-up" aria-labelledby="blocked-title">
            <h2 id="blocked-title" class="font-display font-black text-lg text-white mb-3">المحظورون</h2>
            <div class="glass rounded-2xl divide-y divide-white/5">
                @forelse ($blocked as $person)
                    <x-friend-row :user="$person">
                        @include('friends._actions', ['other' => $person, 'relation' => \App\Services\Social\FriendRelation::BlockedByMe])
                    </x-friend-row>
                @empty
                    <p class="px-4 py-8 text-center text-slate-500 text-sm">لم تحظر أحدًا.</p>
                @endforelse
            </div>
        </section>
    </div>
@endsection
