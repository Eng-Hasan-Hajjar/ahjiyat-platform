@extends('layouts.app')

@section('title', 'دعوة الأصدقاء')
@section('meta_description', 'ادعُ أصدقاءك إلى أحجيات عبر رابطك الشخصي. الدعوات لا تغيّر الترتيب ولا نتائج اللعب.')

@section('content')
    <x-page-header title="دعوة الأصدقاء" subtitle="شارك رابطك الشخصي. تُحتسب الدعوة بعد أن يبدأ صديقك اللعب فعليًا." icon="friends" class="anim-fade-up">
        <x-slot:actions>
            <a href="{{ route('puzzles.index') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="puzzle" class="w-4 h-4" />الأحجيات</a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-3 text-sm font-bold mb-4" role="status">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-xl border border-rose/30 bg-rose/10 text-rose px-4 py-3 text-sm font-bold mb-4" role="alert">{{ session('error') }}</div>
    @endif

    <section class="glass rounded-2xl p-5 md:p-6 mb-6 anim-fade-up d-1" data-referral-link>
        <h2 class="font-display font-black text-lg text-white mb-1">رابط دعوتك</h2>
        <p class="text-sm text-slate-400 mb-3">رمزك: <span class="font-mono font-black text-white tracking-widest" dir="ltr" data-referral-code>{{ $code }}</span></p>
        <input type="text" readonly value="{{ $link }}" dir="ltr" class="w-full rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-sm text-slate-200 select-all" aria-label="رابط الدعوة" data-referral-url>
        <div class="mt-3"><x-share-button :payload="$share" kind="platform" title="انضم إلي في أحجيات" /></div>

        <form method="POST" action="{{ route('referrals.rotate') }}" class="mt-4" onsubmit="return confirm('سيتوقف الرمز الحالي عن إسناد دعوات جديدة. متابعة؟')">
            @csrf
            <button type="submit" class="text-xs font-bold text-slate-400 hover:text-white underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">تغيير رمز الدعوة</button>
        </form>
    </section>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 anim-fade-up d-2" data-referral-stats>
        <x-stat-card label="دعوات مُسنَدة" :value="number_format($stats['total'])" icon="friends" tone="amethyst" />
        <x-stat-card label="قيد الانتظار" :value="number_format($stats['pending'])" icon="calendar" tone="gold" hint="بانتظار توثيق البريد وأول لعب" />
        <x-stat-card label="مؤهَّلة" :value="number_format($stats['qualified'])" icon="check-circle" tone="emerald" />
        @if ($rewardsOn || $stats['gems_earned'] > 0)
            <x-stat-card label="جواهر من الدعوات" :value="number_format($stats['gems_earned'])" icon="gift" tone="gold" hint="معلّقة ثم متاحة بعد فترة الحجز" />
        @else
            <x-stat-card label="نقرات تقريبية" :value="number_format($stats['clicks_approx'])" icon="chart" tone="amethyst" hint="زائر فريد في الساعة" />
        @endif
    </div>

    <section class="glass rounded-2xl p-5 md:p-6 mb-6 anim-fade-up d-3">
        <h2 class="font-display font-black text-lg text-white mb-2">كيف تعمل الدعوات؟</h2>
        <ul class="text-sm text-slate-300 space-y-1.5 list-disc ps-5">
            <li>لا تُحتسب الدعوة بالتسجيل وحده: يجب أن يوثّق صديقك بريده ويحلّ أحجية رسمية.</li>
            <li>حساب جديد له داعٍ واحد فقط، ولا يتغيّر بعد التأهيل. لا تُحتسب دعوة نفسك.</li>
            <li>الدعوات لا تغيّر ترتيبك ولا نقاط اللعب، ولا يشتري الرعاة ترتيبًا في الدعوات.</li>
            @if ($rewardsOn)
                <li>قد تمنح بعض الحملات جواهر عند التأهيل، بسقوف يومية وشهرية، وتبقى معلّقة فترة الحجز المعتادة. للمراجعة اليدوية الحق في تعليق مكافأة مشبوهة.</li>
            @else
                <li>لا مكافآت جواهر للدعوات حاليًا.</li>
            @endif
            <li>لا نعرض لك بيانات أصدقائك: أعدادًا مجمّعة فقط.</li>
        </ul>
    </section>

    @if ($campaigns->isNotEmpty())
        <x-section-header title="حملات الدعوة الجارية" class="anim-fade-up d-3" />
        <div class="grid gap-3 md:grid-cols-2 anim-fade-up d-4" data-referral-campaigns>
            @foreach ($campaigns as $campaign)
                <div class="glass rounded-2xl p-4">
                    <h3 class="font-bold text-white break-words">{{ $campaign->name }}</h3>
                    <p class="text-xs text-slate-400 mt-1">
                        @if ($campaign->ends_at) تنتهي {{ $campaign->ends_at->translatedFormat('j F Y') }} @else مفتوحة @endif
                        @if ($rewardsOn && $campaign->rewardsActive()) · مكافأة عند التأهيل @endif
                    </p>
                    <a href="{{ $link }}?c={{ $campaign->slug }}" dir="ltr" class="mt-2 block text-xs text-slate-300 underline break-all">{{ $link }}?c={{ $campaign->slug }}</a>
                </div>
            @endforeach
        </div>
    @endif
@endsection
