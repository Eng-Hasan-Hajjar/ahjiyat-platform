@extends('layouts.app')

@section('title', 'إدارة فريق '.$team->name)

@section('content')
    @php
        $roleLabels = ['owner' => 'مالك', 'admin' => 'مشرف', 'member' => 'عضو'];
        $isOwner = $myRole === 'owner';
        $input = 'w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst';
    @endphp
    <div class="max-w-4xl mx-auto space-y-6">
        <x-page-header :title="'إدارة فريق '.$team->name" icon="settings" :back="route('teams.show', $team)" backLabel="صفحة الفريق" class="anim-fade-up !mb-0" />

        @unless ($team->is_active)
            <p class="rounded-xl border border-rose/30 bg-rose/10 text-rose px-4 py-3 text-sm font-bold" role="status">هذا الفريق غير مفعَّل: الصفحة للقراءة فقط.</p>
        @endunless

        {{-- دعوة لاعبين --}}
        @if ($canManage)
            <section class="glass rounded-3xl p-5 space-y-4 anim-fade-up" aria-labelledby="invite-title">
                <h2 id="invite-title" class="font-display font-black text-lg text-white">دعوة لاعب</h2>
                <form method="GET" action="{{ route('teams.manage', $team) }}" role="search" class="flex flex-wrap gap-3">
                    <label for="q" class="sr-only">اسم اللاعب</label>
                    <input id="q" type="search" name="q" value="{{ $term }}" maxlength="40" placeholder="ابحث باسم اللاعب (حرفان على الأقل)" autocomplete="off" class="flex-1 min-w-[12rem] {{ $input }}">
                    <button type="submit" class="btn-gem !py-2 !px-5 text-sm">بحث</button>
                </form>
                @if ($results)
                    <ul class="divide-y divide-white/5">
                        @forelse ($results as $u)
                            <li class="flex items-center justify-between gap-3 py-2 text-sm">
                                <span class="font-bold text-white truncate">{{ $u->name }}</span>
                                @if (in_array($u->id, $inTeams, true))
                                    <span class="text-xs text-slate-500">عضو بفريق</span>
                                @else
                                    <form method="POST" action="{{ route('teams.invitations.store', [$team, $u]) }}">@csrf <button type="submit" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">دعوة</button></form>
                                @endif
                            </li>
                        @empty
                            <li class="py-3 text-sm text-slate-500">لا نتائج.</li>
                        @endforelse
                    </ul>
                @endif
            </section>
        @endif

        {{-- طلبات الانضمام --}}
        <section class="glass rounded-3xl p-5 anim-fade-up" aria-labelledby="requests-title">
            <h2 id="requests-title" class="font-display font-black text-lg text-white mb-3">طلبات الانضمام ({{ $requests->count() }})</h2>
            <ul class="divide-y divide-white/5">
                @forelse ($requests as $r)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                        <span class="font-bold text-white truncate">{{ $r->user->name }}</span>
                        @if ($canManage)
                            <div class="flex gap-2">
                                <form method="POST" action="{{ route('teams.requests.accept', [$team, $r]) }}">@csrf <button type="submit" class="btn-gem !py-1.5 !px-4 text-xs">قبول</button></form>
                                <form method="POST" action="{{ route('teams.requests.decline', [$team, $r]) }}">@csrf <button type="submit" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">رفض</button></form>
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="py-3 text-sm text-slate-500">لا طلبات معلّقة.</li>
                @endforelse
            </ul>
        </section>

        {{-- الدعوات المعلّقة --}}
        <section class="glass rounded-3xl p-5 anim-fade-up" aria-labelledby="pending-title">
            <h2 id="pending-title" class="font-display font-black text-lg text-white mb-3">دعوات معلّقة ({{ $invitations->count() }})</h2>
            <ul class="divide-y divide-white/5">
                @forelse ($invitations as $i)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                        <span class="text-white truncate">{{ $i->invitedUser->name }} <span class="text-xs text-slate-500">· تنتهي {{ $i->expires_at->format('Y-m-d') }}</span></span>
                        @if ($canManage)
                            <form method="POST" action="{{ route('teams.invitations.cancel', [$team, $i]) }}">@csrf @method('DELETE') <button type="submit" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إلغاء</button></form>
                        @endif
                    </li>
                @empty
                    <li class="py-3 text-sm text-slate-500">لا دعوات معلّقة.</li>
                @endforelse
            </ul>
        </section>

        {{-- الأعضاء --}}
        <section class="glass rounded-3xl p-5 anim-fade-up" aria-labelledby="members-title">
            <h2 id="members-title" class="font-display font-black text-lg text-white mb-3">الأعضاء ({{ $members->count() }})</h2>
            <ul class="divide-y divide-white/5">
                @foreach ($members as $m)
                    @php
                        $self = $m->user_id === auth()->id();
                        $canRemove = $canManage && ! $self && ($m->role === 'member' || ($isOwner && $m->role === 'admin'));
                        $canRole = $canSettings && $isOwner && ! $self && $m->role !== 'owner';
                    @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-player-avatar :avatar="$m->user->identityAvatar ?? null" :frame="$m->user->identityFrame ?? null" :name="$m->user->name" size="sm" />
                            <span class="font-bold text-white truncate">{{ $m->user->name }}</span>
                            <span class="text-xs {{ $m->role === 'owner' ? 'text-gold' : 'text-slate-400' }}">{{ $roleLabels[$m->role] }}</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($canRole)
                                <form method="POST" action="{{ route('teams.members.role', [$team, $m->user]) }}">@csrf @method('PATCH')
                                    <input type="hidden" name="role" value="{{ $m->role === 'admin' ? 'member' : 'admin' }}">
                                    <button type="submit" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $m->role === 'admin' ? 'تخفيض إلى عضو' : 'ترقية إلى مشرف' }}</button>
                                </form>
                                <form method="POST" action="{{ route('teams.transfer', [$team, $m->user]) }}" onsubmit="return confirm('نقل ملكية الفريق إلى {{ e($m->user->name) }}؟ ستصبح مشرفًا.')">@csrf
                                    <button type="submit" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">نقل الملكية</button>
                                </form>
                            @endif
                            @if ($canRemove)
                                <form method="POST" action="{{ route('teams.members.remove', [$team, $m->user]) }}" onsubmit="return confirm('إزالة هذا العضو؟')">@csrf @method('DELETE')
                                    <button type="submit" class="chip !text-rose focus:outline-none focus-visible:ring-2 focus-visible:ring-rose">إزالة</button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- الإعدادات (المالك) --}}
        @if ($canSettings)
            <section class="glass rounded-3xl p-5 space-y-4 anim-fade-up" aria-labelledby="settings-title">
                <h2 id="settings-title" class="font-display font-black text-lg text-white">إعدادات الفريق</h2>
                <form method="POST" action="{{ route('teams.update', $team) }}" class="space-y-4">
                    @csrf @method('PATCH')
                    <div>
                        <label for="name" class="block text-sm font-bold text-white mb-1">اسم الفريق</label>
                        <input id="name" name="name" type="text" value="{{ old('name', $team->name) }}" required minlength="3" maxlength="40" class="{{ $input }}">
                    </div>
                    <div>
                        <label for="description" class="block text-sm font-bold text-white mb-1">الوصف</label>
                        <textarea id="description" name="description" rows="3" maxlength="500" class="{{ $input }}">{{ old('description', $team->description) }}</textarea>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label for="visibility" class="block text-sm font-bold text-white mb-1">الظهور</label>
                            <select id="visibility" name="visibility" class="{{ $input }}">
                                <option value="public" @selected($team->visibility === 'public')>عام</option>
                                <option value="private" @selected($team->visibility === 'private')>خاص</option>
                            </select>
                        </div>
                        <div>
                            <label for="join_policy" class="block text-sm font-bold text-white mb-1">الانضمام</label>
                            <select id="join_policy" name="join_policy" class="{{ $input }}">
                                <option value="open" @selected($team->join_policy === 'open')>مفتوح</option>
                                <option value="request" @selected($team->join_policy === 'request')>بطلب</option>
                                <option value="invite_only" @selected($team->join_policy === 'invite_only')>بدعوة فقط</option>
                            </select>
                        </div>
                        <div>
                            <label for="max_members" class="block text-sm font-bold text-white mb-1">أقصى أعضاء</label>
                            <input id="max_members" name="max_members" type="number" min="2" max="{{ config('teams.max_members_cap') }}" value="{{ old('max_members', $team->max_members) }}" class="{{ $input }}">
                        </div>
                    </div>
                    <button type="submit" class="btn-gem !py-2 !px-6 text-sm">حفظ</button>
                </form>

                <form method="POST" action="{{ route('teams.deactivate', $team) }}" onsubmit="return confirm('تعطيل الفريق؟ لن يقبل أعضاء جددًا، ويبقى تاريخه التنافسي. إعادة التفعيل من إدارة المنصة.')" class="pt-4 border-t border-white/5">
                    @csrf
                    <button type="submit" class="chip !text-rose focus:outline-none focus-visible:ring-2 focus-visible:ring-rose">تعطيل الفريق</button>
                </form>
            </section>
        @endif
    </div>
@endsection
