<x-filament-panels::page>
    @php
        $d = $this->data();
        $o = $d['opened'];
        $inputClass = 'rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm w-full';
        $fmt = fn ($p) => \App\Services\Sponsorship\SponsorshipMoney::format((int) $p->amount_minor, $p->currency);
    @endphp

    <p class="text-xs text-gray-500">الدفع يدوي خارج المنصة. التحقق هو الطريق الوحيد لفتح المسار المميز؛ لا يفتحه تقديم الطلب ولا الإثبات. لا تتحقق من طلبك الشخصي إن وُجد تعارض مصالح. الاسترداد الفعلي للمال خارجي ويُسجَّل هنا؛ ما استلمه المشتري من مكافآت مميزة لا يُسحب آليًا.</p>

    <div class="flex flex-wrap gap-2" data-purchase-tabs>
        @foreach ($d['tabs'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" class="rounded-lg px-3 py-1.5 text-sm {{ $tab === $key ? 'bg-primary-600 text-white font-bold' : 'border border-gray-300 dark:border-white/10' }}">{{ $label }}</button>
        @endforeach
    </div>

    <table class="w-full text-sm" data-purchase-list>
        <thead><tr class="text-gray-500"><th class="text-start">المرجع</th><th class="text-start">المشتري</th><th>المبلغ</th><th>الحالة</th><th>قُدِّم</th><th>إثبات</th><th></th></tr></thead>
        <tbody>
        @forelse ($d['page'] as $p)
            <tr class="border-t border-gray-100 dark:border-white/10">
                <td class="text-start font-mono" dir="ltr">{{ $p->reference_code }}</td>
                <td class="text-start">{{ $p->user?->name }}<div class="text-xs text-gray-500" dir="ltr">{{ $p->user?->email }}</div></td>
                <td class="text-center" dir="ltr">{{ $fmt($p) }}</td>
                <td class="text-center">{{ $p->statusLabel() }}</td>
                <td class="text-center text-xs">{{ $p->submitted_at?->format('Y-m-d H:i') ?? '—' }}</td>
                <td class="text-center">{{ $p->hasProof() ? 'نعم' : '—' }}</td>
                <td><button type="button" wire:click="show('{{ $p->public_id }}')" class="text-primary-600 text-xs font-bold">عرض</button></td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-gray-500 py-4">لا طلبات في هذا العرض.</td></tr>
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

            <ol class="text-xs text-gray-500 space-y-0.5" data-purchase-events>
                @foreach ($o->events as $e)
                    <li>{{ $e->created_at->format('Y-m-d H:i') }} — {{ $e->event }}@if ($e->actor) ({{ $e->actor->name }})@endif @if ($e->note) — {{ $e->note }}@endif</li>
                @endforeach
            </ol>

            @if ($d['canVerify'] && $o->isOpen())
                <div class="space-y-2" data-purchase-review>
                    <input type="text" wire:model="reviewNote" maxlength="500" placeholder="ملاحظة داخلية اختيارية" class="{{ $inputClass }}">
                    <textarea wire:model="rejectMessage" rows="2" maxlength="300" placeholder="سبب الرفض الظاهر للمشتري" class="{{ $inputClass }}"></textarea>
                    <div class="flex flex-wrap gap-2">
                        @if ($o->status === \App\Models\DigitalPurchase::STATUS_SUBMITTED)
                            <button type="button" wire:click="verify" wire:confirm="تأكيد وصول الدفع كاملًا؟ سيُفتح المسار المميز للمشتري." class="rounded-lg bg-emerald-600 text-white px-3 py-1.5 text-sm font-bold" data-purchase-verify>تأكيد الدفع</button>
                        @endif
                        <button type="button" wire:click="reject" class="rounded-lg bg-red-600 text-white px-3 py-1.5 text-sm font-bold">رفض</button>
                    </div>
                </div>
            @endif

            @if ($d['canRefund'] && $o->status === \App\Models\DigitalPurchase::STATUS_VERIFIED)
                <div class="space-y-2 rounded-lg border border-gray-200 dark:border-white/10 p-3" data-purchase-refund>
                    <div class="text-sm font-bold">تسجيل استرداد</div>
                    <select wire:model="refundReason" class="{{ $inputClass }}">@foreach ($d['reasons'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                    <textarea wire:model="refundNote" rows="2" maxlength="1000" placeholder="ملاحظة الاسترداد (إلزامية، 5 أحرف فأكثر)" class="{{ $inputClass }}"></textarea>
                    <label class="text-xs flex items-center gap-2"><input type="checkbox" wire:model="confirmClaimed">أؤكد المراجعة المالية إن كان المشتري قد استلم مكافآت مميزة (لن تُسحب العناصر آليًا)</label>
                    <button type="button" wire:click="refund" wire:confirm="تسجيل الاسترداد وسحب المميز؟" class="rounded-lg bg-amber-600 text-white px-3 py-1.5 text-sm font-bold">تسجيل الاسترداد</button>
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
