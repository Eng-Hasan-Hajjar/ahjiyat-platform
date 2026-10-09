@extends('layouts.app')

@section('title', 'إنشاء فريق')

@section('content')
    <div class="max-w-xl mx-auto space-y-6">
        <x-page-header title="إنشاء فريق" icon="team" subtitle="ستكون مالك الفريق. يمكن لكل مستخدم أن يكون عضوًا في فريق واحد فقط." :back="route('teams.index')" backLabel="الفرق" class="anim-fade-up !mb-0" />

        <form method="POST" action="{{ route('teams.store') }}" class="glass rounded-3xl p-6 space-y-5 anim-fade-up">
            @csrf
            <div>
                <label for="name" class="block text-sm font-bold text-white mb-1">اسم الفريق</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required minlength="3" maxlength="40" autocomplete="off"
                    class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" aria-describedby="name-help">
                <p id="name-help" class="text-xs text-slate-500 mt-1">3 إلى 40 حرفًا: أحرف وأرقام ومسافات وشرطات.</p>
                @error('name') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="description" class="block text-sm font-bold text-white mb-1">وصف (اختياري)</label>
                <textarea id="description" name="description" rows="3" maxlength="500"
                    class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ old('description') }}</textarea>
                @error('description') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="visibility" class="block text-sm font-bold text-white mb-1">الظهور</label>
                    <select id="visibility" name="visibility" class="w-full rounded-xl bg-white/5 border border-white/10 px-3 py-2.5 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                        <option value="public" @selected(old('visibility', 'public') === 'public')>عام (يظهر في الدليل)</option>
                        <option value="private" @selected(old('visibility') === 'private')>خاص (الأعضاء يرون القائمة)</option>
                    </select>
                </div>
                <div>
                    <label for="join_policy" class="block text-sm font-bold text-white mb-1">طريقة الانضمام</label>
                    <select id="join_policy" name="join_policy" class="w-full rounded-xl bg-white/5 border border-white/10 px-3 py-2.5 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                        <option value="request" @selected(old('join_policy', 'request') === 'request')>بطلب انضمام</option>
                        <option value="open" @selected(old('join_policy') === 'open')>مفتوح للجميع</option>
                        <option value="invite_only" @selected(old('join_policy') === 'invite_only')>بدعوة فقط</option>
                    </select>
                </div>
            </div>
            <div>
                <label for="max_members" class="block text-sm font-bold text-white mb-1">أقصى عدد أعضاء (اختياري)</label>
                <input id="max_members" name="max_members" type="number" min="2" max="{{ config('teams.max_members_cap') }}" value="{{ old('max_members') }}"
                    class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                @error('max_members') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn-gem w-full">إنشاء الفريق</button>
        </form>
    </div>
@endsection
