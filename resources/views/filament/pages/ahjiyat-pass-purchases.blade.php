<x-filament-panels::page>
    @php
        $d = $this->data();
        $o = $d['opened'];
        $inputClass = 'rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm w-full';
        $fmt = fn ($p) => \App\Services\Sponsorship\SponsorshipMoney::format((int) $p->amount_minor, $p->currency);
    @endphp

    <p class="text-xs text-gray-500">الدفع اليدوي خارج المنصة؛ والدفع المستضاف يؤكده المزوّد عبر webhook/مزامنة موثوقة. المميز لا يُفتح إلا بالتحقق (يدوي مراجَع، أو مزوّد موثوق بتطابق تام للمبلغ والعملة والمرجع) — لا بعودة المتصفح ولا بتقديم الطلب. لا تتحقق من طلبك الشخصي إن وُجد تعارض مصالح. الاسترداد للمزوّد المستضاف يُنفَّذ عند المزوّد أولًا ولا يُسجَّل «مسترد» قبل تأكيده؛ واليدوي يُسجَّل بعد استرداد خارجي. ما استلمه المشتري من مكافآت مميزة لا يُسحب آليًا.</p>

    <div class="flex flex-wrap gap-2" data-purchase-tabs>
        @foreach ($d['tabs'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" class="rounded-lg px-3 py-1.5 text-sm {{ $tab === $key ? 'bg-primary-600 text-white font-bold' : 'border border-gray-300 dark:border-white/10' }}">{{ $label }}</button>
        @endforeach
    </div>

    <div class="grid grid-cols-2 gap-2 sm:grid-cols-5" data-purchase-filters>
        <select wire:model.live="filterProvider" class="{{ $inputClass }}" aria-label="المزوّد"><option value="">كل المزوّدين</option>@foreach ($d['providers'] as $k)<option value="{{ $k }}">{{ $k }}</option>@endforeach</select>
        <select wire:model.live="filterStatus" class="{{ $inputClass }}" aria-label="الحالة"><option value="">كل الحالات</option>@foreach ($d['statuses'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
        <select wire:model.live="filterCurrency" class="{{ $inputClass }}" aria-label="العملة"><option value="">كل العملات</option>@foreach ($d['currencies'] as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
        <input type="date" wire:model.live="filterFrom" class="{{ $inputClass }}" aria-label="من تاريخ">
        <input type="date" wire:model.live="filterTo" class="{{ $inputClass }}" aria-label="إلى تاريخ">
    </div>

    <table class="w-full text-sm" data-purchase-list>
        <thead><tr class="text-gray-500"><th class="text-start">المرجع</th><th class="text-start">المشتري</th><th>المبلغ</th><th>الحالة</th><th>المزوّد</th><th>قُدِّم</th><th>إثبات</th><th></th></tr></thead>
        <tbody>
        @forelse ($d['page'] as $p)
            <tr class="border-t border-gray-100 dark:border-white/10">
                <td class="text-start font-mono" dir="ltr">{{ $p->reference_code }}</td>
                <td class="text-start">{{ $p->user?->name }}<div class="text-xs text-gray-500" dir="ltr">{{ $p->user?->email }}</div></td>
                <td class="text-center" dir="ltr">{{ $fmt($p) }}</td>
                <td class="text-center">{{ $p->statusLabel() }}@if ($p->needsReview())<div class="text-xs font-bold text-amber-600" data-review-flag>⚠ {{ $p->reviewLabel() }}</div>@endif</td>
                <td class="text-center text-xs" dir="ltr">{{ $p->provider }}</td>
                <td class="text-center text-xs">{{ $p->submitted_at?->format('Y-m-d H:i') ?? '—' }}</td>
                <td class="text-center">{{ $p->hasProof() ? 'نعم' : '—' }}</td>
                <td><button type="button" wire:click="show('{{ $p->public_id }}')" class="text-primary-600 text-xs font-bold">عرض</button></td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-gray-500 py-4">لا طلبات في هذا العرض.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div>{{ $d['page']->links() }}</div>

    @if ($o)
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-purchase-detail>
            <div class="font-bold">{{ $fmt($o) }} — {{ $o->statusLabel() }} — <span class="font-mono" dir="ltr">{{ $o->reference_code }}</span></div>
            <div class="text-xs text-gray-500">{{ $o->product_name }} · المشتري {{ $o->user?->name }} (<span dir="ltr">{{ $o->user?->email }}</span>)@if ($o->user?->is_frozen) · <strong class="text-red-600">حساب مجمَّد</strong>@endif</div>
            @if ($o->transfer_reference)<div class="text-xs">مرجع التحويل المُدخل: <span class="font-mono" dir="ltr">{{ $o->transfer_reference }}</span></div>@endif
            @if ($o->payer_note)<div class="text-xs text-gray-500">ملاحظة المشتري: {{ $o->payer_note }}</div>@endif
            @if ($o->hasProof())
                <div class="text-xs">إثبات: {{ $o->proof_mime }} · {{ number_format((int) $o->proof_size / 1024, 0) }} ك.ب
                    <button type="button" wire:click="downloadProof" class="text-primary-600 font-bold ms-2">تنزيل</button>
                    @if ($d['duplicates'] > 0)<strong class="text-red-600 ms-2">⚠ الإثبات نفسه مستخدم في {{ $d['duplicates'] }} طلب آخر</strong>@endif
                </div>
            @endif
            @if ($o->decision_message)<div class="text-xs text-gray-500">رسالة الرفض للمشتري: {{ $o->decision_message }}</div>@endif
            @if ($o->review_note)<div class="text-xs text-gray-500">ملاحظات داخلية: {{ $o->review_note }}</div>@endif
            @if ($o->status === \App\Models\DigitalPurchase::STATUS_REFUNDED)<div class="text-xs text-gray-500">استرداد: {{ $d['reasons'][$o->refund_reason] ?? $o->refund_reason }} · عناصر مميزة مُستلَمة بقيت: {{ (int) $o->refund_kept_items }}</div>@endif

            @if ($o->isHosted())
                <dl class="grid grid-cols-1 gap-1 text-xs sm:grid-cols-2" data-purchase-provider>
                    <div><dt class="inline text-gray-500">المزوّد:</dt> <dd class="inline font-mono" dir="ltr">{{ $o->provider }}</dd></div>
                    <div><dt class="inline text-gray-500">حالة المزوّد:</dt> <dd class="inline">{{ $d['providerState']?->label() ?? '—' }}</dd></div>
                    <div><dt class="inline text-gray-500">جلسة المزوّد:</dt> <dd class="inline font-mono break-all" dir="ltr">{{ $o->provider_session_id ?? '—' }}</dd></div>
                    <div><dt class="inline text-gray-500">مرجع الدفع:</dt> <dd class="inline font-mono break-all" dir="ltr">{{ $o->provider_payment_reference ?? '—' }}</dd></div>
                    @if ($o->provider_refund_reference)<div><dt class="inline text-gray-500">مرجع الاسترداد:</dt> <dd class="inline font-mono break-all" dir="ltr">{{ $o->provider_refund_reference }}</dd></div>@endif
                    <div><dt class="inline text-gray-500">جيل الجلسة:</dt> <dd class="inline">{{ (int) $o->checkout_generation }}</dd></div>
                    <div><dt class="inline text-gray-500">تأكيد الدفع عند المزوّد:</dt> <dd class="inline">{{ $o->provider_paid_at?->format('Y-m-d H:i') ?? '—' }}</dd></div>
                    <div><dt class="inline text-gray-500">آخر مزامنة:</dt> <dd class="inline">{{ $o->provider_last_synced_at?->format('Y-m-d H:i') ?? '—' }}</dd></div>
                    @if ($o->refund_requested_at)<div><dt class="inline text-gray-500">طلب استرداد:</dt> <dd class="inline">{{ $o->refund_requested_at->format('Y-m-d H:i') }}@if ($o->refund_error) — {{ $o->refund_error }}@endif</dd></div>@endif
                </dl>
            @endif

            @if ($o->review_flag)
                <div class="rounded-lg border border-amber-300 bg-amber-50 dark:bg-amber-500/10 p-3 text-xs" data-purchase-review-flag>
                    <strong>{{ $o->review_resolved_at ? 'مراجعة مغلقة' : 'مراجعة مالية مطلوبة' }}:</strong> {{ $o->reviewLabel() }}
                    @if ($o->review_resolved_at) — {{ $o->review_resolution }} ({{ $o->review_resolved_at->format('Y-m-d H:i') }})@endif
                </div>
            @endif

            @if ($d['ledger']->isNotEmpty())
                <div data-purchase-ledger>
                    <div class="text-xs font-bold mb-1">سجل أحداث المزوّد (بلا حمولة خام)</div>
                    <ol class="text-xs text-gray-500 space-y-0.5">
                        @foreach ($d['ledger'] as $e)
                            <li><span dir="ltr">{{ $e->received_at->format('Y-m-d H:i') }}</span> — {{ $e->event_type }} → {{ $e->processing_result }}@if ($e->result_detail) ({{ $e->result_detail }})@endif@if ($e->amount_minor !== null) — {{ \App\Services\Sponsorship\SponsorshipMoney::format((int) $e->amount_minor, (string) $e->currency) }}@endif</li>
                        @endforeach
                    </ol>
                </div>
            @endif

            <ol class="text-xs text-gray-500 space-y-0.5" data-purchase-events>
                @foreach ($o->events as $e)
                    <li>{{ $e->created_at->format('Y-m-d H:i') }} — {{ $e->event }}@if ($e->actor) ({{ $e->actor->name }})@endif @if ($e->note) — {{ $e->note }}@endif</li>
                @endforeach
            </ol>

            @if ($d['canVerify'] && ($o->isOpen() || $o->needsReview()))
                <div class="space-y-2" data-purchase-review>
                    <input type="text" wire:model="reviewNote" maxlength="500" placeholder="{{ $d['reviewReady'] ? 'سبب القرار (إلزامي، 5 أحرف فأكثر)' : 'ملاحظة داخلية اختيارية' }}" class="{{ $inputClass }}">
                    @if ($o->isOpen())
                        <textarea wire:model="rejectMessage" rows="2" maxlength="300" placeholder="سبب الرفض الظاهر للمشتري" class="{{ $inputClass }}"></textarea>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        @if ($o->status === \App\Models\DigitalPurchase::STATUS_SUBMITTED)
                            <button type="button" wire:click="verify" wire:confirm="تأكيد وصول الدفع كاملًا؟ سيُفتح المسار المميز للمشتري." class="rounded-lg bg-emerald-600 text-white px-3 py-1.5 text-sm font-bold" data-purchase-verify>تأكيد الدفع</button>
                        @endif
                        @if ($d['reviewReady'])
                            <button type="button" wire:click="verify" wire:confirm="قبول هذا الدفع بعد مراجعتك رغم العلم؟ سيُفتح المسار المميز." class="rounded-lg bg-emerald-600 text-white px-3 py-1.5 text-sm font-bold" data-purchase-verify-reviewed>قبول بعد المراجعة</button>
                        @endif
                        @if ($o->needsReview())
                            <button type="button" wire:click="dismissReview" wire:confirm="إغلاق المراجعة بلا تغيير حالة الدفع؟" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm font-bold" data-purchase-dismiss>إغلاق المراجعة</button>
                        @endif
                        @if ($d['canSync'] && ! in_array($o->status, [\App\Models\DigitalPurchase::STATUS_REFUNDED], true))
                            <button type="button" wire:click="syncNow" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm font-bold" data-purchase-sync>مزامنة الآن</button>
                        @endif
                        @if ($o->isOpen())
                            <button type="button" wire:click="reject" class="rounded-lg bg-red-600 text-white px-3 py-1.5 text-sm font-bold">رفض</button>
                        @endif
                    </div>
                </div>
            @endif

            @if ($d['canRefund'] && $d['unmatchedRefundable'])
                <div class="space-y-2 rounded-lg border border-gray-200 dark:border-white/10 p-3" data-purchase-refund-unmatched>
                    <div class="text-sm font-bold">استرداد دفعة غير مطابقة عبر المزوّد</div>
                    <textarea wire:model="refundNote" rows="2" maxlength="1000" placeholder="ملاحظة الاسترداد (إلزامية، 5 أحرف فأكثر)" class="{{ $inputClass }}"></textarea>
                    <button type="button" wire:click="refundUnmatched" wire:confirm="استرداد المبلغ كاملًا عند المزوّد؟" class="rounded-lg bg-amber-600 text-white px-3 py-1.5 text-sm font-bold">استرداد عبر المزوّد</button>
                </div>
            @endif

            @if ($d['canRefund'] && $o->status === \App\Models\DigitalPurchase::STATUS_VERIFIED)
                <div class="space-y-2 rounded-lg border border-gray-200 dark:border-white/10 p-3" data-purchase-refund>
                    <div class="text-sm font-bold">{{ $o->isHosted() ? 'استرداد كامل' : 'تسجيل استرداد' }}</div>
                    <select wire:model="refundReason" class="{{ $inputClass }}">@foreach ($d['reasons'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                    <textarea wire:model="refundNote" rows="2" maxlength="1000" placeholder="ملاحظة الاسترداد (إلزامية، 5 أحرف فأكثر)" class="{{ $inputClass }}"></textarea>
                    <label class="text-xs flex items-center gap-2"><input type="checkbox" wire:model="confirmClaimed">أؤكد المراجعة المالية إن كان المشتري قد استلم مكافآت مميزة (لن تُسحب العناصر آليًا)</label>
                    <button type="button" wire:click="refund" wire:confirm="{{ $o->isHosted() ? 'تنفيذ الاسترداد عند المزوّد؟ لن يُسجَّل «مسترد» قبل تأكيده.' : 'تسجيل الاسترداد وسحب المميز؟' }}" class="rounded-lg bg-amber-600 text-white px-3 py-1.5 text-sm font-bold" data-purchase-refund-submit>{{ $o->isHosted() ? 'استرداد' : 'تسجيل الاسترداد' }}</button>
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
