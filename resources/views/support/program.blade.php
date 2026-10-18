@extends('layouts.app')

@section('title', $program->title)
@section('content_width', 'max-w-3xl')

{{-- E32: صفحة برنامج الدعم. أرقام مؤكَّدة فقط، لا بيانات دفع، الراعي المجهول لا يظهر. زر «ادعم» يقود لصفحة الدفع (تسجيل دخول مطلوب). --}}
@section('content')
    <div class="space-y-6">
        <x-page-header :title="$program->title" icon="gift" :back="route('support.index')" backLabel="ادعم المنصة" class="anim-fade-up !mb-0" />

        <article class="puzzle-card !p-6 space-y-4 anim-fade-up" data-program="{{ $program->slug }}">
            <div class="flex flex-wrap items-center gap-2">
                <x-status-badge tone="success">نشط</x-status-badge>
                <span class="text-xs text-slate-400">{{ $targetLabel }}</span>
            </div>

            @if (filled($program->description))
                <p class="text-sm text-slate-300 break-words whitespace-pre-line">{{ $program->description }}</p>
            @endif

            @if ($targetUrl)
                <a href="{{ $targetUrl }}" class="text-xs font-bold text-amethyst hover:underline">صفحة الهدف</a>
            @endif

            @include('support._progress')

            @if ($card['boost_bps'] > 0)
                <p class="text-sm font-bold text-emerald">تعزيز المكافآت الرسمية الحالي: {{ rtrim(rtrim(number_format($card['boost_bps'] / 100, 2), '0'), '.') }}٪</p>
            @endif

            @if ($card['main'])
                <p class="text-sm text-slate-300">الراعي الرئيسي: <a href="{{ route('supporters.show', $card['main']['slug']) }}" class="font-bold text-amethyst hover:underline">{{ $card['main']['name'] }}</a></p>
            @endif

            @if ($enabled)
                <div class="pt-2 flex flex-wrap gap-2">
                    <a href="{{ route('support.checkout', $program->slug) }}" class="btn-gem">ادعم هذا البرنامج</a>
                    <a href="{{ route('supporters.index', ['scope' => 'program', 'program' => $program->slug]) }}" class="btn-secondary">داعمو البرنامج</a>
                </div>
            @endif
        </article>

        <p class="text-xs text-slate-500">الدعم طوعي ولا يمنحك أي أفضلية في اللعب. لا يُحتسب إلا بعد تحقق الفريق من التحويل.</p>
    </div>
@endsection
