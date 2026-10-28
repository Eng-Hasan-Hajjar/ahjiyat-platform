<x-filament-panels::page>
    @php
        $d = $this->data();
        $inputClass = 'rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm w-full';
        $money = fn ($minor, $currency) => \App\Services\CreatorFinance\CreatorMoney::format((int) $minor, (string) $currency);
        $btn = 'rounded-lg px-3 py-1.5 text-sm font-bold';
    @endphp

    <p class="text-xs text-gray-500">
        شراء مرة واحدة ووصول دائم لسلسلة مبدع مميزة؛ الدفع عبر مزوّد E41 المستضاف، وحصة المبدع تُسجَّل في دفتر E39 (قسمة بنقاط الأساس، الباقي للمنصة). لا تحويل عملات: عملة العرض = عملة حساب أرباح المبدع.
        الاسترداد عند المزوّد هو المرجع؛ إن تعذّر عكس حصة المبدع (دُفعت أو لا يكفي المتاح) يُوسَم البيع «يحتاج مراجعة مالية» بلا رصيد سالب ولا مصادرة آلية.
        @unless ($d['enabled'])
            <strong class="text-amber-600" data-premium-disabled-note>المفتاح CREATOR_PREMIUM_ENABLED معطَّل: لا عروض جديدة ولا checkout جديد، وتبقى الأوامر الإدارية والاسترداد والمطابقة متاحة.</strong>
        @endunless
    </p>

    <div class="flex flex-wrap gap-2" data-premium-tabs>
        @foreach ($d['tabs'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" class="rounded-lg px-3 py-1.5 text-sm {{ $tab === $key ? 'bg-primary-600 text-white font-bold' : 'border border-gray-300 dark:border-white/10' }}">{{ $label }}</button>
        @endforeach
    </div>

    {{-- ───────── نظرة عامة ───────── --}}
    @if ($tab === 'overview')
        <div class="space-y-3" data-premium-overview>
            @forelse ($d['summary'] as $s)
                <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4" data-premium-currency="{{ $s['currency'] }}">
                    <div class="font-bold mb-2" dir="ltr">{{ $s['currency'] }}</div>
                    <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                        <div><dt class="text-gray-500 text-xs">عدد المبيعات</dt><dd class="font-bold">{{ $s['sales'] }}</dd></div>
                        <div><dt class="text-gray-500 text-xs">الإجمالي</dt><dd class="font-bold" dir="ltr">{{ $money($s['gross_minor'], $s['currency']) }}</dd></div>
                        <div><dt class="text-gray-500 text-xs">حصص المبدعين</dt><dd class="font-bold" dir="ltr">{{ $money($s['creator_minor'], $s['currency']) }}</dd></div>
                        <div><dt class="text-gray-500 text-xs">حصة المنصة</dt><dd class="font-bold" dir="ltr">{{ $money($s['platform_minor'], $s['currency']) }}</dd></div>
                        <div><dt class="text-gray-500 text-xs">مستردة</dt><dd class="font-bold">{{ $s['refunded'] }}</dd></div>
                        <div><dt class="text-gray-500 text-xs">حصص معكوسة</dt><dd class="font-bold" dir="ltr">{{ $money($s['reversed_minor'], $s['currency']) }}</dd></div>
                        <div><dt class="text-gray-500 text-xs">تحتاج مراجعة مالية</dt><dd class="font-bold {{ $s['needs_review'] > 0 ? 'text-amber-600' : '' }}">{{ $s['needs_review'] }}</dd></div>
                        <div><dt class="text-gray-500 text-xs">تتحمّلها المنصة</dt><dd class="font-bold">{{ $s['written_off'] }}</dd></div>
                    </dl>
                </div>
            @empty
                <p class="text-sm text-gray-500">لا مبيعات مميزة بعد.</p>
            @endforelse
        </div>
    @endif

    {{-- ───────── العروض ───────── --}}
    @if ($tab === 'offers')
        <div data-premium-offers>
            @if ($d['canManage'])
                <input type="text" wire:model="note" maxlength="300" placeholder="سبب الإيقاف/الاستئناف الإداري (إلزامي، 5 أحرف فأكثر)" class="{{ $inputClass }} mb-2">
            @endif
            <table class="w-full text-sm">
                <thead><tr class="text-gray-500"><th class="text-start">السلسلة</th><th class="text-start">المبدع</th><th>السعر</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                @forelse ($d['page'] as $o)
                    <tr class="border-t border-gray-100 dark:border-white/10" data-premium-offer="{{ $o->public_id }}">
                        <td class="text-start">{{ $o->published_title ?? '—' }}<div class="text-xs text-gray-500 font-mono" dir="ltr">{{ $o->series_public_id }}</div></td>
                        <td class="text-start">{{ $o->creator_name }}</td>
                        <td class="text-center" dir="ltr">{{ $money($o->price_minor, $o->currency) }}</td>
                        <td class="text-center">{{ $this->offerStatusLabel($o->status) }}@if ($o->status === 'paused' && $o->pause_reason)<div class="text-xs text-gray-500">{{ \App\Models\CreatorSeriesSaleOffer::PAUSE_REASONS[$o->pause_reason] ?? $o->pause_reason }}</div>@endif</td>
                        <td class="text-center">
                            @if ($d['canManage'] && $o->status === 'active')
                                <button type="button" wire:click="pauseOffer('{{ $o->public_id }}')" wire:confirm="إيقاف بيع هذه السلسلة؟ يبقى محتواها مقفولًا ولا تتأثر وصولات المالكين." class="{{ $btn }} bg-amber-600 text-white" data-premium-pause>إيقاف</button>
                            @elseif ($d['canManage'] && $o->status === 'paused')
                                <button type="button" wire:click="resumeOffer('{{ $o->public_id }}')" class="{{ $btn }} bg-emerald-600 text-white" data-premium-resume>استئناف</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-gray-500 py-4">لا عروض بيع بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div>{{ $d['page']->links() }}</div>
        </div>
    @endif

    {{-- ───────── المشتريات ───────── --}}
    @if ($tab === 'purchases')
        @php $o = $d['opened']; @endphp
        <div class="space-y-3" data-premium-purchases>
            <div class="flex flex-wrap gap-2">
                @foreach ($d['purchaseFilters'] as $key => $label)
                    <button type="button" wire:click="$set('purchaseFilter', '{{ $key }}')" class="rounded-lg px-3 py-1 text-xs {{ $purchaseFilter === $key ? 'bg-primary-600 text-white font-bold' : 'border border-gray-300 dark:border-white/10' }}">{{ $label }}</button>
                @endforeach
            </div>

            <table class="w-full text-sm">
                <thead><tr class="text-gray-500"><th class="text-start">المرجع</th><th class="text-start">المشتري</th><th>المبلغ</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                @forelse ($d['page'] as $p)
                    <tr class="border-t border-gray-100 dark:border-white/10">
                        <td class="text-start font-mono" dir="ltr">{{ $p->reference_code }}</td>
                        <td class="text-start">{{ $p->user?->name }}<div class="text-xs text-gray-500" dir="ltr">{{ $p->user?->email }}</div></td>
                        <td class="text-center" dir="ltr">{{ $money($p->amount_minor, $p->currency) }}</td>
                        <td class="text-center">{{ $p->statusLabel() }}@if ($p->needsReview())<div class="text-xs font-bold text-amber-600" data-review-flag>⚠ {{ $p->reviewLabel() }}</div>@endif</td>
                        <td><button type="button" wire:click="showPurchase('{{ $p->public_id }}')" class="text-primary-600 text-xs font-bold">عرض</button></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-gray-500 py-4">لا طلبات في هذا العرض.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div>{{ $d['page']->links() }}</div>

            @if ($o)
                <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-premium-purchase-detail>
                    <div class="font-bold" dir="ltr">{{ $money($o->amount_minor, $o->currency) }} — {{ $o->statusLabel() }} — <span class="font-mono">{{ $o->reference_code }}</span></div>
                    <div class="text-xs text-gray-500">{{ $o->product_name }} · المشتري {{ $o->user?->name }} (<span dir="ltr">{{ $o->user?->email }}</span>)@if ($o->user?->is_frozen) · <strong class="text-red-600">حساب مجمَّد</strong>@endif</div>
                    @if ($o->review_flag)
                        <div class="rounded-lg border border-amber-300 bg-amber-50 dark:bg-amber-500/10 p-3 text-xs" data-premium-review-flag>
                            <strong>{{ $o->review_resolved_at ? 'مراجعة مغلقة' : 'مراجعة مطلوبة' }}:</strong> {{ $o->reviewLabel() }}
                            @if ($o->review_resolved_at) — {{ $o->review_resolution }} ({{ $o->review_resolved_at->format('Y-m-d H:i') }})@endif
                        </div>
                    @endif
                    @if ($d['openedSale'])
                        <div class="text-xs" data-premium-sale-snapshot>بيع مسجَّل: الإجمالي <span dir="ltr">{{ $money($d['openedSale']->gross_minor, $d['openedSale']->currency) }}</span> · المبدع <span dir="ltr">{{ $money($d['openedSale']->creator_share_minor, $d['openedSale']->currency) }}</span> · المنصة <span dir="ltr">{{ $money($d['openedSale']->platform_share_minor, $d['openedSale']->currency) }}</span> — {{ $d['openedSale']->statusLabel() }}</div>
                    @endif
                    @if ($o->status === \App\Models\DigitalPurchase::STATUS_REFUNDED)
                        <div class="text-xs text-gray-500">استرداد: {{ $d['reasons'][$o->refund_reason] ?? $o->refund_reason }}</div>
                    @endif
                    <ol class="text-xs text-gray-500 space-y-0.5" data-premium-events>
                        @foreach ($o->events as $e)
                            <li>{{ $e->created_at->format('Y-m-d H:i') }} — {{ $e->event }}@if ($e->actor) ({{ $e->actor->name }})@endif @if ($e->note) — {{ $e->note }}@endif</li>
                        @endforeach
                    </ol>

                    @if (($d['canManage'] || $d['canRefund']) && ($d['reviewReady'] || $o->needsReview() || $o->status === \App\Models\DigitalPurchase::STATUS_VERIFIED))
                        <div class="space-y-2">
                            <textarea wire:model="note" rows="2" maxlength="1000" placeholder="سبب القرار (إلزامي، 5 أحرف فأكثر)" class="{{ $inputClass }}"></textarea>
                            <div class="flex flex-wrap gap-2">
                                @if ($d['canManage'] && $d['reviewReady'])
                                    <button type="button" wire:click="verifyReviewed" wire:confirm="قبول هذا الدفع بعد مراجعتك؟ سيُمنح الوصول وتُسجَّل حصة المبدع." class="{{ $btn }} bg-emerald-600 text-white" data-premium-verify-reviewed>قبول بعد المراجعة</button>
                                @endif
                                @if ($d['canManage'] && $o->needsReview() && ! $o->review_resolved_at)
                                    <button type="button" wire:click="dismissReview" wire:confirm="إغلاق المراجعة بلا تغيير حالة الدفع؟" class="{{ $btn }} border border-gray-300 dark:border-white/10" data-premium-dismiss>إغلاق المراجعة</button>
                                @endif
                                @if ($d['canRefund'] && $d['unmatchedRefundable'])
                                    <button type="button" wire:click="refundUnmatched" wire:confirm="استرداد المبلغ كاملًا عند المزوّد؟" class="{{ $btn }} bg-amber-600 text-white" data-premium-refund-unmatched>استرداد عبر المزوّد</button>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if ($d['canRefund'] && $o->status === \App\Models\DigitalPurchase::STATUS_VERIFIED)
                        <div class="space-y-2 rounded-lg border border-gray-200 dark:border-white/10 p-3" data-premium-refund>
                            <div class="text-sm font-bold">{{ $o->isHosted() ? 'استرداد كامل' : 'تسجيل استرداد' }}</div>
                            <select wire:model="refundReason" class="{{ $inputClass }}">@foreach ($d['reasons'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                            <label class="text-xs flex items-center gap-2"><input type="checkbox" wire:model="confirmClaimed">أؤكد المراجعة المالية إن كان المشتري قد بدأ لعب السلسلة بعد الشراء (الاسترداد يسحب الوصول ويعكس حصة المبدع)</label>
                            <button type="button" wire:click="refund" wire:confirm="{{ $o->isHosted() ? 'تنفيذ الاسترداد عند المزوّد؟ لن يُسجَّل «مسترد» قبل تأكيده.' : 'تسجيل الاسترداد وسحب الوصول؟' }}" class="{{ $btn }} bg-amber-600 text-white" data-premium-refund-submit>{{ $o->isHosted() ? 'استرداد' : 'تسجيل الاسترداد' }}</button>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif

    {{-- ───────── المبيعات ───────── --}}
    @if ($tab === 'sales')
        @php $s = $d['openedSale']; @endphp
        <div class="space-y-3" data-premium-sales>
            <div class="flex flex-wrap gap-2">
                @foreach ($d['saleFilters'] as $key => $label)
                    <button type="button" wire:click="$set('saleFilter', '{{ $key }}')" class="rounded-lg px-3 py-1 text-xs {{ $saleFilter === $key ? 'bg-primary-600 text-white font-bold' : 'border border-gray-300 dark:border-white/10' }}">{{ $label }}</button>
                @endforeach
            </div>

            <table class="w-full text-sm">
                <thead><tr class="text-gray-500"><th class="text-start">السلسلة</th><th class="text-start">المبدع</th><th>الإجمالي</th><th>حصة المبدع</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                @forelse ($d['page'] as $r)
                    <tr class="border-t border-gray-100 dark:border-white/10">
                        <td class="text-start">{{ $r->series_title }}<div class="text-xs text-gray-500">{{ $r->sold_at?->format('Y-m-d H:i') }}</div></td>
                        <td class="text-start">{{ $r->creator_display_name }}</td>
                        <td class="text-center" dir="ltr">{{ $money($r->gross_minor, $r->currency) }}</td>
                        <td class="text-center" dir="ltr">{{ $money($r->creator_share_minor, $r->currency) }}</td>
                        <td class="text-center">{{ $r->statusLabel() }}</td>
                        <td><button type="button" wire:click="showSale('{{ $r->public_id }}')" class="text-primary-600 text-xs font-bold">عرض</button></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-gray-500 py-4">لا مبيعات في هذا العرض.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div>{{ $d['page']->links() }}</div>

            @if ($s)
                <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-premium-sale-detail>
                    <div class="font-bold">{{ $s->series_title }} — {{ $s->statusLabel() }}</div>
                    <div class="text-xs text-gray-500" dir="ltr">{{ $money($s->gross_minor, $s->currency) }} = {{ $money($s->creator_share_minor, $s->currency) }} ({{ $s->creator_bps }} bps) + {{ $money($s->platform_share_minor, $s->currency) }} ({{ $s->platform_bps }} bps) · {{ $s->rounding_rule }}</div>
                    @if ($s->needsReview())
                        <div class="rounded-lg border border-amber-300 bg-amber-50 dark:bg-amber-500/10 p-3 text-xs" data-premium-sale-review>
                            استُرد المشتري عند المزوّد، لكن حصة المبدع لم تُعكس لأن رصيده المتاح لا يكفي (غالبًا دُفعت). لا رصيد سالب ولا مصادرة آلية: أعد المحاولة بعد توفّر الرصيد، أو حمّل المنصة الخسارة بقرار موثَّق.
                        </div>
                        @if ($d['canRefund'])
                            <textarea wire:model="note" rows="2" maxlength="300" placeholder="سبب التحميل على المنصة (إلزامي لتحميل المنصة، 5 أحرف فأكثر)" class="{{ $inputClass }}"></textarea>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="retryReversal" wire:confirm="إعادة محاولة عكس حصة المبدع من رصيده المتاح؟" class="{{ $btn }} bg-amber-600 text-white" data-premium-retry-reversal>إعادة محاولة العكس</button>
                                <button type="button" wire:click="writeOff" wire:confirm="تحميل المنصة هذا الاسترداد؟ لن تُسحب حصة المبدع." class="{{ $btn }} border border-gray-300 dark:border-white/10" data-premium-write-off>تحميل المنصة</button>
                            </div>
                        @endif
                    @endif
                    @if ($s->review_resolved_at)
                        <div class="text-xs text-gray-500">قرار المراجعة: {{ $s->review_resolution }} ({{ $s->review_resolved_at->format('Y-m-d H:i') }})@if ($s->review_note) — {{ $s->review_note }}@endif</div>
                    @endif
                </div>
            @endif
        </div>
    @endif

    {{-- ───────── المطابقة ───────── --}}
    @if ($tab === 'reconcile')
        <div class="space-y-3" data-premium-reconcile>
            <p class="text-xs text-gray-500">قراءة فقط: تقارن الطلب المؤكَّد ← الوصول ← لقطة البيع ← قيد الدفتر. لا تصلح شيئًا آليًا؛ كل إصلاح بقرار بشري عبر الإجراءات الموثَّقة.</p>
            <button type="button" wire:click="reconcile" class="{{ $btn }} bg-primary-600 text-white" data-premium-run-reconcile>تشغيل المطابقة</button>

            @if ($reconciliation !== null)
                <div class="text-sm" data-premium-reconcile-result>
                    طلبات مفحوصة: {{ $reconciliation['checked_purchases'] }} — مبيعات مفحوصة: {{ $reconciliation['checked_sales'] }} — انحرافات: <strong class="{{ $reconciliation['remaining'] > 0 ? 'text-amber-600' : 'text-emerald-600' }}">{{ $reconciliation['remaining'] }}</strong>
                    @if ($reconciliation['truncated']) (المعروض أول {{ count($reconciliation['findings']) }}) @endif
                </div>
                @if ($reconciliation['remaining'] === 0)
                    <p class="text-sm text-emerald-600">الطلبات والوصول والمبيعات والدفتر متسقة.</p>
                @else
                    <ul class="text-xs space-y-1">
                        @foreach ($reconciliation['findings'] as $f)
                            <li><span class="font-mono" dir="ltr">[{{ $f['code'] }}]</span> <span class="font-mono" dir="ltr">{{ $f['subject'] }}</span> — {{ $f['detail'] }}</li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </div>
    @endif
</x-filament-panels::page>
