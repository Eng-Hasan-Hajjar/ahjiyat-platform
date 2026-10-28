@extends('layouts.app')

@section('title', $work->published_title.' - سلسلة مميزة')
@section('meta_description', 'أحجية ضمن سلسلة مميزة. تفاصيل الحل متاحة لمالكي السلسلة فقط.')
@section('canonical', route('community.puzzles.show', $work->public_id))
@section('content_width', 'max-w-3xl')

{{--
    E43: صفحة عمل مقفول (ضمن سلسلة مميزة). **بيانات وصفية فقط**: العنوان/التصنيف/الصعوبة/المبدع/بطاقة السلسلة والسعر.
    لا نص أحجية ولا صورة ولا نموذج حلّ ولا تلميح ولا نقاش ولا تقييمات (لا يصل أي محتوى إلى HTML). نصوص المبدع مهرَّبة.
--}}
@section('content')
    <div class="space-y-5" data-premium-locked>
        <a href="{{ route('community.puzzles.index') }}" class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-400 hover:text-white transition motion-reduce:transition-none rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst anim-fade-up">
            <x-ui-icon name="chevron-right" class="w-4 h-4" />أحجيات المجتمع
        </a>

        <div class="puzzle-card !p-6 md:!p-9 anim-fade-up d-1">
            <div class="flex flex-wrap items-center gap-2 mb-5">
                <x-status-badge tone="warning" icon="lock">مميز</x-status-badge>
                <x-status-badge tone="primary" icon="friends">من المجتمع</x-status-badge>
                @if ($work->publishedCategory) <span class="chip !py-1 !px-3">{{ $work->publishedCategory->name }}</span> @endif
                @if ($work->published_difficulty) <x-difficulty-badge :difficulty="$work->published_difficulty" /> @endif
            </div>

            <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-2 leading-tight break-words">{{ $work->published_title }}</h1>
            <p class="text-xs text-slate-400 mb-6">
                بقلم <a href="{{ route('creators.show', $work->creator->slug) }}" class="font-bold text-slate-200 hover:text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $work->creator->user->name }}</a>
            </p>

            <div class="rounded-xl border border-gold/30 bg-gold/10 px-5 py-4 text-sm text-slate-100" role="status" data-premium-lock-note>
                @if ($reason === 'login_required')
                    <p class="font-bold text-gold mb-2">هذه الأحجية جزء من سلسلة مميزة.</p>
                    <p class="mb-3">سجّل الدخول لمتابعة الشراء أو لعبها إن كنت تملك الوصول.</p>
                    <a href="{{ route('login') }}" class="btn-gem !py-2 !px-5 text-sm">تسجيل الدخول</a>
                @else
                    <p class="font-bold text-gold mb-2">هذه الأحجية جزء من سلسلة مميزة.</p>
                    <p>الوصول إلى نصها وحلها وتلميحاتها ونقاشاتها متاح لمالكي السلسلة.</p>
                @endif
            </div>

            @if (! empty($card))
                <div class="mt-5 rounded-xl border border-white/10 bg-white/5 px-5 py-4 flex flex-wrap items-center justify-between gap-3" data-premium-series-card>
                    <div class="min-w-0">
                        <p class="text-xs text-slate-400">السلسلة المميزة</p>
                        <p class="font-black text-white break-words">{{ $card['title'] }}</p>
                        <p class="text-sm text-slate-300" dir="ltr">{{ \App\Services\Sponsorship\SponsorshipMoney::format((int) $card['price_minor'], (string) $card['currency']) }}</p>
                    </div>
                    @if ($card['sellable'])
                        <a href="{{ route('series.show', $card['public_id']) }}" class="btn-gem !py-2.5 !px-6 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-premium-get-access>احصل على الوصول</a>
                    @else
                        <span class="text-xs font-bold text-slate-400">البيع موقوف مؤقتًا</span>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endsection
