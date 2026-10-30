@extends('layouts.app')

@section('title', 'داعمو الموسم — '.$title)
@section('meta_description', 'بطاقات الداعم ونقاط الدعم وترتيب داعمي موسم «'.$title.'».')
@section('content_width', 'max-w-4xl')

{{--
    E44 - داعمو الموسم. نقاط الدعم **تقدير فقط**: ليست مالًا ولا جواهر ولا خبرة ولا نقاط تمريرة ولا درجة منافسة ولا أرباح مبدعين، ولا تُصرف ولا تُحوَّل، ولا تمنح أي أفضلية لعب.
    الدفع الحقيقي عبر مسار E30/E32 القائم؛ الخادم وحده يحدّد السعر والعملة والنقاط. رفع الإثبات لا يمنح شيئًا؛ يتحقق الفريق من الدفع فتُفعَّل البطاقة وتُحتسب نقاطها.
--}}
@php
    $money = fn (int $minor, string $cur) => intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT).' '.$cur;
    $tierNames = collect(config('seasonal_recognition.supporters.card_tiers'))->map(fn ($t) => $t['name']);
    $statusLabels = [
        'pending' => ['بانتظار تحقق الدفع', 'warning'],
        'active' => ['مفعّلة', 'success'],
        'void' => ['ملغاة', 'neutral'],
        'refunded' => ['مُستردّة', 'neutral'],
    ];
@endphp

@section('content')
    <div class="space-y-6">
        <x-page-header :title="'داعمو الموسم'" :subtitle="$title" icon="gift" :back="route('seasons.show', $season)" backLabel="رجوع إلى الموسم" class="anim-fade-up !mb-0" />

        <section class="puzzle-card !p-5 md:!p-6 space-y-2 anim-fade-up" aria-labelledby="supporters-about">
            <h2 id="supporters-about" class="font-display font-black text-xl text-white">عن بطاقات الداعم</h2>
            <p class="text-sm text-slate-300">بطاقات الداعم دعمٌ حقيقي للمنصة يُدفع عبر مسار الرعاية والدعم المعتمد. تمنحك البطاقة نمط اسم موسميًا ونقاط دعم للترتيب العلني. نقاط الدعم للتقدير فقط: ليست مالًا ولا جواهر ولا خبرة، ولا تمنح أي أفضلية في اللعب أو المنافسات.</p>
            @if ($finalized)
                <x-status-badge tone="primary">انتهى ترتيب الداعمين لهذا الموسم</x-status-badge>
            @elseif (! $canBuy)
                <x-status-badge>البطاقات غير متاحة حاليًا</x-status-badge>
            @endif
        </section>

        @if ($canBuy && $packages->isNotEmpty())
            <section class="space-y-3 anim-fade-up" aria-labelledby="supporters-packages">
                <h2 id="supporters-packages" class="font-display font-black text-xl text-white">اختر بطاقتك</h2>

                @auth
                    <form method="POST" action="{{ route('seasonal.supporters.cards.store', $season) }}" class="space-y-4">
                        @csrf
                        <input type="hidden" name="request_key" value="{{ $requestKey }}">
                        <fieldset class="grid gap-3 md:grid-cols-3">
                            <legend class="sr-only">البطاقة</legend>
                            @foreach ($packages as $p)
                                <label class="puzzle-card !p-4 cursor-pointer block has-[:checked]:border-amethyst has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-amethyst">
                                    <input type="radio" name="tier" value="{{ $p->tier_key }}" class="sr-only" required @checked(old('tier') === $p->tier_key)>
                                    <span class="block font-display font-black text-lg" ><x-season-supporter-name :tier="$p->name_style_key">{{ $p->name }}</x-season-supporter-name></span>
                                    <span class="block text-2xl font-black text-white mt-1 tabular-nums" dir="ltr">{{ $money((int) $p->price_minor, (string) $p->currency) }}</span>
                                    <span class="block text-xs text-slate-400 mt-1">{{ number_format($p->support_points) }} نقطة دعم (تقدير فقط)</span>
                                </label>
                            @endforeach
                        </fieldset>
                        @error('tier')<p class="text-sm text-rose">{{ $message }}</p>@enderror

                        <fieldset class="space-y-2">
                            <legend class="text-sm font-black text-slate-300">كيف تريد أن يظهر دعمك؟</legend>
                            <label class="flex items-center gap-2 text-sm text-slate-200"><input type="radio" name="visibility" value="public" @checked(old('visibility', 'public') === 'public')> علنًا باسمي في ترتيب الداعمين</label>
                            <label class="flex items-center gap-2 text-sm text-slate-200"><input type="radio" name="visibility" value="anonymous" @checked(old('visibility') === 'anonymous')> مجهولًا (لا يظهر اسمي ولا تُحتسب نقاطي في الترتيب العلني)</label>
                        </fieldset>

                        <div>
                            <label for="display_name" class="text-sm font-black text-slate-300">الاسم المعروض في إيصال الدعم (اختياري)</label>
                            <input id="display_name" name="display_name" type="text" maxlength="80" value="{{ old('display_name') }}" class="input mt-1">
                            @error('display_name')<p class="text-sm text-rose mt-1">{{ $message }}</p>@enderror
                        </div>

                        <button type="submit" class="btn-gem">متابعة إلى الدفع</button>
                        <p class="text-xs text-slate-500">لن تُفعَّل البطاقة ولن تُحتسب نقاطها قبل تحقق الفريق من الدفع.</p>
                    </form>
                @else
                    <div class="grid gap-3 md:grid-cols-3">
                        @foreach ($packages as $p)
                            <div class="puzzle-card !p-4">
                                <span class="block font-display font-black text-lg"><x-season-supporter-name :tier="$p->name_style_key">{{ $p->name }}</x-season-supporter-name></span>
                                <span class="block text-2xl font-black text-white mt-1 tabular-nums" dir="ltr">{{ $money((int) $p->price_minor, (string) $p->currency) }}</span>
                                <span class="block text-xs text-slate-400 mt-1">{{ number_format($p->support_points) }} نقطة دعم (تقدير فقط)</span>
                            </div>
                        @endforeach
                    </div>
                    <p class="text-sm text-slate-400"><a href="{{ route('login') }}" class="font-bold text-amethyst hover:underline">سجّل الدخول</a> لطلب بطاقة.</p>
                @endauth
            </section>
        @endif

        @if ($mine)
            <section class="puzzle-card !p-5 md:!p-6 space-y-3 anim-fade-up" aria-labelledby="supporters-mine">
                <h2 id="supporters-mine" class="font-display font-black text-xl text-white">دعمك في هذا الموسم</h2>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <span class="font-bold text-slate-200 tabular-nums">{{ number_format($mine['points']) }} نقطة دعم</span>
                    @if ($mine['tier'])<x-status-badge tone="primary">{{ $tierNames[$mine['tier']] ?? '' }}</x-status-badge>@endif
                    @if ($finalized && $mine['final_rank'])
                        <x-status-badge tone="success">مرتبتك النهائية #{{ $mine['final_rank'] }}</x-status-badge>
                    @elseif ($mine['rank'])
                        <x-status-badge tone="success">مرتبتك الحالية #{{ $mine['rank'] }}</x-status-badge>
                    @endif
                </div>

                @if ($mine['cards']->isNotEmpty())
                    <ul class="space-y-2">
                        @foreach ($mine['cards'] as $c)
                            @php [$label, $tone] = $statusLabels[$c->status] ?? ['—', 'neutral']; @endphp
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                                <span class="font-black text-white">{{ $tierNames[$c->tier_key] ?? $c->tier_key }}</span>
                                <x-status-badge :tone="$tone" small>{{ $label }}</x-status-badge>
                                @if ($c->is_anonymous)<x-status-badge small>مجهول</x-status-badge>@endif
                                <span class="text-xs text-slate-400 tabular-nums" dir="ltr">{{ $money((int) $c->price_minor, (string) $c->currency) }}</span>
                                @if ($c->status === 'pending' && $c->payment)
                                    <a href="{{ route('support.payments.show', ['payment' => $c->payment->public_id]) }}" class="ms-auto text-sm font-bold text-amethyst hover:underline">متابعة الدفع / رفع الإثبات</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        <section class="puzzle-card !p-5 md:!p-6 anim-fade-up" aria-labelledby="supporters-standings-title"
                 x-data="seasonalBoard({ url: @js(route('seasonal.supporters.standings', $season)), channel: @js('seasonal-supporters.'.$season->id), pollSeconds: @js($pollSeconds), debounceMs: @js($debounceMs), realtime: @js($realtime) })">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                <h2 id="supporters-standings-title" class="font-display font-black text-xl text-white">{{ $board['finalized'] ? 'الترتيب النهائي للداعمين' : 'ترتيب الداعمين' }}</h2>
                <span class="text-[11px] text-slate-500" x-show="busy" x-cloak>جارٍ التحديث…</span>
            </div>
            <div x-ref="target" aria-live="polite" :aria-busy="busy ? 'true' : 'false'">
                @include('seasonal._supporters_standings', ['board' => $board])
            </div>
            <p class="text-xs text-slate-500 mt-3">الترتيب للاعبين فقط بحسب نقاط الدعم العلنية؛ الداعمون المجهولون لا يظهرون فيه. نقاط الدعم للتقدير ولا تمنح أي أفضلية لعب.</p>
        </section>

        @if ($recent !== [])
            <section class="puzzle-card !p-5 md:!p-6 anim-fade-up" aria-labelledby="supporters-recent">
                <h2 id="supporters-recent" class="font-display font-black text-xl text-white mb-3">أحدث البطاقات</h2>
                <ul class="space-y-2">
                    @foreach ($recent as $r)
                        <li class="flex flex-wrap items-center gap-2 text-sm">
                            <x-season-supporter-name :tier="$r['anonymous'] ? null : $r['tier']" class="font-black text-white">{{ $r['name'] }}</x-season-supporter-name>
                            <x-status-badge small>{{ $tierNames[$r['tier']] ?? '' }}</x-status-badge>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endsection
