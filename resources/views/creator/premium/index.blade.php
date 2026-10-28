@extends('layouts.app')

@section('title', 'السلاسل المميزة')

{{--
    E43: استوديو السلاسل المميزة. بيانات المبدع نفسه فقط ومجمَّعة (لا هوية مشترٍ ولا بريد ولا مرجع دفع). كل القيم مهرَّبة ({{ }}).
    السعر نص بعملة حسابك (يحلله الخادم)؛ لا تتحكم بالعملة ولا بنسبة المنصة. الشراء مرة واحدة ووصول دائم؛ لا اشتراك.
--}}
@section('content')
    @php
        $fmt = fn (int $minor, ?string $cur = null) => \App\Services\CreatorFinance\CreatorMoney::format($minor, $cur ?? $currency);
        $labels = ['draft' => 'مسودة', 'active' => 'نشط', 'paused' => 'موقوف مؤقتًا'];
        $tones = ['draft' => 'neutral', 'active' => 'success', 'paused' => 'warning'];
        $txLabels = ['credited' => 'مسجَّل', 'refunded' => 'مسترد', 'needs_finance_review' => 'قيد المراجعة المالية', 'written_off' => 'استرداد تتحمّله المنصة'];
    @endphp
    <div class="space-y-6" data-premium-studio>
        <x-page-header title="السلاسل المميزة" icon="lock" subtitle="بِع الوصول الدائم إلى سلاسلك المنشورة. شراء مرة واحدة، وحصتك تُقيَّد في أرباحك." :back="route('creator.series.index')" backLabel="سلاسلي" class="anim-fade-up !mb-0" />

        @if ($account === null)
            <x-empty-state icon="chart" title="التسييل غير مُفعَّل لحسابك بعد" message="يلزم تفعيل التسييل (من فريق المنصة) قبل بيع السلاسل. تواصل مع الدعم لمعرفة موعد انضمامك." />
        @else
            @unless ($enabled)
                <div class="rounded-xl border border-gold/30 bg-gold/10 px-4 py-3 text-sm font-semibold text-slate-200" data-premium-disabled>البيع المميز غير متاح حاليًا على المنصة؛ لا يمكن تفعيل عروض جديدة الآن. وصول المالكين الحاليين محفوظ.</div>
            @endunless
            @unless ($account->isEnabled())
                <div class="rounded-xl border border-gold/30 bg-gold/10 px-4 py-3 text-sm font-semibold text-slate-200" data-premium-paused>التسييل موقوف مؤقتًا لحسابك؛ لا بيع جديد حتى استئنافه.</div>
            @endunless

            <section aria-label="ملخص المبيعات" class="grid grid-cols-2 md:grid-cols-4 gap-3 anim-fade-up" data-premium-stats>
                <x-stat-card label="مبيعات" :value="number_format($stats['sales'])" icon="puzzle" />
                <x-stat-card label="إجمالي المدفوع" :value="$fmt($stats['gross_minor'])" icon="chart" />
                <x-stat-card label="صافي حصتك" :value="$fmt($stats['net_minor'])" icon="gem" />
                <x-stat-card :label="'آخر '.$stats['window']['days'].' يومًا'" :value="number_format($stats['window']['sales']).' · '.$fmt($stats['window']['net_minor'])" icon="chart" />
            </section>

            @if ($stats['refunded'] > 0)
                <p class="text-xs text-slate-400" data-premium-refunds>{{ number_format($stats['refunded']) }} عملية مستردة (عُكست حصتك منها: {{ $fmt($stats['reversed_minor']) }}).</p>
            @endif

            @if (count($stats['top_series']) > 0)
                <section aria-label="أفضل السلاسل" class="glass rounded-2xl p-4 anim-fade-up">
                    <h2 class="font-display font-black text-white mb-3">الأكثر مبيعًا</h2>
                    <ol class="space-y-1 text-sm">
                        @foreach ($stats['top_series'] as $top)
                            <li class="flex items-center justify-between gap-3"><span class="text-slate-200 break-words min-w-0">{{ $top['title'] }}</span><span class="text-slate-400 shrink-0" dir="ltr">{{ $top['sales'] }} · {{ $fmt($top['net_minor']) }}</span></li>
                        @endforeach
                    </ol>
                </section>
            @endif

            <section aria-label="سلاسلك" class="anim-fade-up space-y-3" data-premium-series>
                @forelse ($seriesList as $s)
                    @php $status = $s->offer_status; @endphp
                    <div class="glass rounded-2xl p-4" data-premium-series-row="{{ $s->public_id }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('series.show', $s->public_id) }}" class="font-display font-black text-white hover:text-amethyst break-words rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $s->published_title }}</a>
                                <p class="text-xs text-slate-400 mt-1">{{ (int) $s->published_items_count }} أحجية @if ($status) · {{ number_format((int) $s->owners) }} مالك @endif</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($status)
                                    <x-status-badge :tone="$tones[$status] ?? 'neutral'" :small="true">{{ $labels[$status] ?? $status }}</x-status-badge>
                                    <span class="text-sm font-black text-white" dir="ltr">{{ $fmt((int) $s->offer_price_minor, (string) $s->offer_currency) }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap items-end gap-3">
                            @if (! $status)
                                <form method="POST" action="{{ route('creator.premium.offers.store') }}" class="flex flex-wrap items-end gap-2">
                                    @csrf
                                    <input type="hidden" name="series" value="{{ $s->public_id }}">
                                    <div>
                                        <label for="price-new-{{ $s->public_id }}" class="block text-xs font-bold text-slate-300 mb-1">السعر ({{ $currency }})</label>
                                        <input id="price-new-{{ $s->public_id }}" name="price" type="text" inputmode="decimal" dir="ltr" maxlength="20" required class="input-gem !py-2 w-32" placeholder="4.99">
                                    </div>
                                    <button type="submit" class="btn-secondary !py-2 !px-4 text-sm">إنشاء عرض بيع</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('creator.premium.offers.price', $s->offer_public_id) }}" class="flex flex-wrap items-end gap-2">
                                    @csrf
                                    @method('PUT')
                                    <div>
                                        <label for="price-{{ $s->offer_public_id }}" class="block text-xs font-bold text-slate-300 mb-1">السعر الجديد ({{ $currency }})</label>
                                        <input id="price-{{ $s->offer_public_id }}" name="price" type="text" inputmode="decimal" dir="ltr" maxlength="20" required class="input-gem !py-2 w-32" value="{{ \App\Services\CreatorFinance\CreatorMoney::toMajorText((int) $s->offer_price_minor, (string) $s->offer_currency) }}">
                                    </div>
                                    <button type="submit" class="btn-secondary !py-2 !px-4 text-sm">تحديث السعر</button>
                                </form>

                                @if ($status === 'draft' || ($status === 'paused' && $s->offer_pause_reason === 'creator'))
                                    <form method="POST" action="{{ route('creator.premium.offers.activate', $s->offer_public_id) }}">@csrf<button type="submit" class="btn-gem !py-2 !px-4 text-sm" @disabled(! $enabled)>{{ $status === 'draft' ? 'تفعيل البيع' : 'استئناف البيع' }}</button></form>
                                @endif
                                @if ($status === 'active')
                                    <form method="POST" action="{{ route('creator.premium.offers.pause', $s->offer_public_id) }}">@csrf<button type="submit" class="btn-secondary !py-2 !px-4 text-sm">إيقاف مؤقت</button></form>
                                @endif
                                @if ($status === 'paused' && $s->offer_pause_reason !== 'creator')
                                    <span class="text-xs font-bold text-gold">أوقفت البيعَ الإدارةُ/الإشراف؛ لا يمكنك استئنافه بنفسك.</span>
                                @endif
                                <form method="POST" action="{{ route('creator.premium.offers.retire', $s->offer_public_id) }}" onsubmit="return confirm('إغلاق العرض نهائيًا يجعل أحجيات السلسلة مجانية ويحتفظ المالكون بسجلّهم. متابعة؟');">@csrf<button type="submit" class="text-xs font-bold text-rose underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إغلاق العرض</button></form>
                            @endif
                        </div>
                    </div>
                @empty
                    <x-empty-state icon="grid" title="لا سلاسل منشورة بعد" message="انشر سلسلة أولًا ثم حوّلها إلى سلسلة مميزة من هنا." :action="route('creator.series.index')" actionLabel="سلاسلي" />
                @endforelse

                <div class="flex justify-center mt-4">{{ $seriesList->links() }}</div>
            </section>

            <section aria-label="سجل المبيعات" class="glass rounded-2xl p-3 sm:p-5 anim-fade-up" data-premium-transactions>
                <h2 class="font-display font-black text-white mb-3">سجل المبيعات</h2>
                @forelse ($transactions as $t)
                    <article class="flex flex-col gap-1 border-b border-white/5 py-3 last:border-0 sm:flex-row sm:items-center sm:justify-between" data-premium-sale>
                        <div class="min-w-0">
                            <p class="font-bold text-white break-words">{{ $t->series_title }}</p>
                            <p class="text-xs text-slate-400">{{ \Illuminate\Support\Carbon::parse($t->sold_at)->format('Y-m-d H:i') }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3 text-sm">
                            <span class="text-slate-300" dir="ltr">{{ $fmt((int) $t->gross_minor, (string) $t->currency) }}</span>
                            <span class="font-black text-white" dir="ltr">{{ $fmt((int) $t->creator_share_minor, (string) $t->currency) }}</span>
                            <x-status-badge :tone="$t->status === 'refunded' ? 'neutral' : ($t->status === 'credited' ? 'success' : 'warning')" :small="true">{{ $txLabels[$t->status] ?? $t->status }}</x-status-badge>
                        </div>
                    </article>
                @empty
                    <p class="text-sm text-slate-400 py-6 text-center" data-premium-empty>لا مبيعات بعد.</p>
                @endforelse
                @if ($transactions->hasPages())
                    <div class="mt-4 flex justify-between text-sm">
                        @if ($transactions->previousPageUrl())<a href="{{ $transactions->previousPageUrl() }}" class="font-bold text-amethyst">السابق</a>@else<span></span>@endif
                        @if ($transactions->nextPageUrl())<a href="{{ $transactions->nextPageUrl() }}" class="font-bold text-amethyst">التالي</a>@endif
                    </div>
                @endif
            </section>
        @endif
    </div>
@endsection
