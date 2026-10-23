<x-filament-panels::page>
    @php
        $d = $this->data();
        $o = $d['opened'];
        $fmt = fn (int $minor, string $c) => \App\Services\CreatorFinance\CreatorMoney::format($minor, $c);
        $inputClass = 'rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm w-full';
        $tabs = ['open' => 'مفتوحة', 'approved' => 'معتمدة بانتظار الصرف', 'paid' => 'مصروفة', 'closed' => 'مرفوضة/ملغاة', 'all' => 'الكل'];
    @endphp

    <p class="text-xs text-gray-500">الدفع يدوي خارج المنصة؛ يُسجَّل هنا بمرجع خارجي فريد فقط. لا تُخزَّن وجهة تحويل ولا بيانات بنكية. الاعتماد والصرف يتوقفان عند تعليق الحساب أو تجميد المبدع/إيقاف تسييله، أما الرفض فمتاح دائمًا ويعيد المبلغ للرصيد المتاح. لا تعالج دفعتك الخاصة.</p>

    <div class="flex flex-wrap gap-2" data-payout-tabs>
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" class="rounded-lg px-3 py-1.5 text-sm {{ $tab === $key ? 'bg-primary-600 text-white font-bold' : 'border border-gray-300 dark:border-white/10' }}">{{ $label }}</button>
        @endforeach
    </div>

    <table class="w-full text-sm" data-payout-list>
        <thead><tr class="text-gray-500"><th class="text-start">المبدع</th><th>المبلغ</th><th>الوسيلة</th><th>الحالة</th><th>طُلب</th><th></th></tr></thead>
        <tbody>
        @forelse ($d['page'] as $p)
            <tr class="border-t border-gray-100 dark:border-white/10">
                <td dir="ltr" class="text-start">{{ $p->creatorProfile?->slug }}</td>
                <td class="text-center">{{ $fmt((int) $p->amount_minor, $p->currency) }}</td>
                <td class="text-center">{{ $d['methods'][$p->method_code] ?? $p->method_code }}</td>
                <td class="text-center">{{ $p->statusLabel() }}</td>
                <td class="text-center text-xs">{{ $p->requested_at?->format('Y-m-d H:i') }}</td>
                <td><button type="button" wire:click="show('{{ $p->public_id }}')" class="text-primary-600 text-xs font-bold">عرض</button></td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-gray-500 py-4">لا طلبات في هذا العرض.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div>{{ $d['page']->links() }}</div>

    @if ($o)
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-payout-detail>
            <div class="font-bold">{{ $fmt((int) $o->amount_minor, $o->currency) }} — {{ $o->statusLabel() }} — <span dir="ltr">{{ $o->creatorProfile?->slug }}</span></div>
            <div class="text-xs text-gray-500">متاح {{ $fmt((int) $o->account->available_minor, $o->currency) }} · محجوز {{ $fmt((int) $o->account->reserved_minor, $o->currency) }} · مصروف عمره {{ $fmt((int) $o->account->paid_lifetime_minor, $o->currency) }}@if ($o->account->isOnHold()) · <strong class="text-amber-600">حساب معلَّق للدفعات</strong>@endif @unless ($o->account->isEnabled()) · <strong class="text-amber-600">التسييل موقوف</strong>@endunless</div>
            @if ($o->external_reference)<div class="text-xs">مرجع الصرف: <span dir="ltr">{{ $o->external_reference }}</span></div>@endif
            @if ($o->decision_message)<div class="text-xs text-gray-500">رسالة الرفض للمبدع: {{ $o->decision_message }}</div>@endif
            @if ($o->review_note)<div class="text-xs text-gray-500">ملاحظة داخلية: {{ $o->review_note }}</div>@endif

            @if ($d['canReview'] && $o->isOpen() && $o->status !== 'approved')
                <div class="space-y-2">
                    <input type="text" wire:model="reviewNote" maxlength="500" placeholder="ملاحظة داخلية اختيارية" class="{{ $inputClass }}">
                    <textarea wire:model="rejectMessage" rows="2" maxlength="300" placeholder="رسالة الرفض الظاهرة للمبدع (8 أحرف فأكثر؛ بلا ملاحظات داخلية)" class="{{ $inputClass }}"></textarea>
                    <div class="flex flex-wrap gap-2">
                        @if ($o->status === 'requested')<button type="button" wire:click="startReview" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm">بدء المراجعة</button>@endif
                        <button type="button" wire:click="approve" class="rounded-lg bg-emerald-600 text-white px-3 py-1.5 text-sm font-bold">اعتماد</button>
                        <button type="button" wire:click="reject" class="rounded-lg bg-red-600 text-white px-3 py-1.5 text-sm font-bold">رفض</button>
                    </div>
                </div>
            @endif
            @if ($d['canReview'] && $o->status === 'approved')
                <div class="space-y-2">
                    <textarea wire:model="rejectMessage" rows="2" maxlength="300" placeholder="رسالة الرفض الظاهرة للمبدع (إن أردت الرفض بعد الاعتماد)" class="{{ $inputClass }}"></textarea>
                    <button type="button" wire:click="reject" class="rounded-lg bg-red-600 text-white px-3 py-1.5 text-sm font-bold">رفض وإعادة المبلغ</button>
                </div>
            @endif
            @if ($d['canSettle'] && $o->status === 'approved')
                <div class="space-y-2 rounded-lg border border-gray-200 dark:border-white/10 p-3" data-settle-box>
                    <input type="text" wire:model="reference" dir="ltr" maxlength="120" placeholder="مرجع الصرف الخارجي (رقم حوالة/إيصال)" class="{{ $inputClass }}">
                    <button type="button" wire:click="settle" class="rounded-lg bg-primary-600 text-white px-4 py-2 text-sm font-bold">تسجيل الصرف</button>
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
