@extends('layouts.app')

@section('title', 'انضم كمبدع')

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">
        <x-page-header title="انضم كمبدع" icon="palette" subtitle="أنشئ أحجيات للمجتمع، وأرسلها للمراجعة، وابنِ صفحتك كمبدع." :back="route('community.puzzles.index')" backLabel="من المجتمع" class="anim-fade-up !mb-0" />

        @if ($profile?->isApproved())
            <div class="glass rounded-3xl p-6 anim-fade-up">
                <x-status-badge tone="success" icon="check-circle">مبدع معتمَد</x-status-badge>
                <p class="text-sm text-slate-300 mt-3">أنت مبدع معتمَد. ابدأ بإنشاء أحجياتك من الاستوديو.</p>
                <a href="{{ route('creator.dashboard') }}" class="btn-gem !py-2.5 !px-6 text-sm mt-4 inline-flex">استوديو المبدع</a>
            </div>
        @elseif ($profile?->isPending())
            <div class="glass rounded-3xl p-6 anim-fade-up">
                <x-status-badge tone="warning" icon="info">طلبك قيد المراجعة</x-status-badge>
                <p class="text-sm text-slate-300 mt-3">أرسلت طلبك في {{ $profile->submitted_at?->translatedFormat('j F Y') }}. سنُعلمك بالنتيجة عبر الإشعارات.</p>
            </div>
        @elseif ($profile?->isSuspended())
            <div class="glass rounded-3xl p-6 anim-fade-up">
                <x-status-badge tone="danger" icon="alert">صلاحيات المبدع معلَّقة</x-status-badge>
                <p class="text-sm text-slate-300 mt-3">تم تعليق صلاحيات المبدع لحسابك، وأُخفيت أحجياتك المنشورة مؤقتًا. تواصل مع الدعم لمزيد من التفاصيل.</p>
            </div>
        @else
            @if ($profile?->isRejected())
                <div class="rounded-2xl border border-rose/30 bg-rose/10 text-rose px-5 py-4 text-sm font-bold anim-fade-up" role="status">
                    لم يُقبل طلبك السابق.
                    @if ($profile->review_note)
                        <span class="block font-normal text-slate-300 mt-1">ملاحظة المراجع: {{ $profile->review_note }}</span>
                    @endif
                    يمكنك تعديل نبذتك وإعادة التقديم.
                </div>
            @endif

            @if ($denial)
                <div class="rounded-2xl border border-gold/30 bg-gold/10 text-gold px-5 py-4 text-sm font-bold" role="status">{{ $denial }}</div>
            @else
                <form method="POST" action="{{ route('creator.apply.store') }}" class="glass rounded-3xl p-6 space-y-5 anim-fade-up">
                    @csrf
                    <div>
                        <label for="bio" class="block text-sm font-bold text-white mb-1">عرّف بنفسك ولماذا تريد الانضمام</label>
                        <textarea id="bio" name="bio" rows="5" required minlength="{{ config('creators.limits.bio_min') }}" maxlength="{{ config('creators.limits.bio_max') }}" aria-describedby="bio-help"
                            class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ old('bio') }}</textarea>
                        <p id="bio-help" class="text-xs text-slate-500 mt-1">من {{ config('creators.limits.bio_min') }} إلى {{ config('creators.limits.bio_max') }} حرفًا، نصًا عاديًا. نعرض اسم حسابك ونبذتك علنًا في صفحتك كمبدع إن قُبلت؛ لا نعرض بريدك ولا أي بيانات خاصة.</p>
                        @error('bio') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-slate-400 space-y-1">
                        <p>أحجيات المجتمع تمرّ بمراجعة قبل النشر، وتظهر بوسم «من المجتمع» وباسمك.</p>
                        <p>أحجيات المجتمع لا تمنح جواهر ولا نقاط خبرة، ولا يُحتسب لعبها في المهام أو الإنجازات أو الحملات أو المنافسات.</p>
                    </div>
                    <button type="submit" class="btn-gem w-full">إرسال الطلب</button>
                </form>
            @endif
        @endif
    </div>
@endsection
