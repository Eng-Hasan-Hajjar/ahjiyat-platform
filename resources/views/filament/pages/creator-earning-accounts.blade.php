<x-filament-panels::page>
    @php
        $d = $this->data();
        $o = $d['opened'];
        $fmt = fn (int $minor, string $c) => \App\Services\CreatorFinance\CreatorMoney::format($minor, $c);
        $inputClass = 'rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm w-full';
        $filters = ['all' => 'الكل', 'enabled' => 'مُفعَّل', 'paused' => 'موقوف', 'hold' => 'معلَّق للدفعات'];
    @endphp

    <p class="text-xs text-gray-500">التسييل بوابة منفصلة عن اعتماد ملف المبدع. التعليق يمنع طلب/اعتماد/صرف الدفعات فقط ولا يمنع الاستحقاق. بلاغ أو علامة احتيال وحدهما لا يعلّقان شيئًا؛ التعليق قرار بشري بسبب مسجَّل. التعديلات الإدارية تُضاف قيودًا جديدة ولا تعدّل قيدًا قديمًا.</p>

    @if ($d['can']['monetization'])
        <div class="flex flex-wrap gap-2 items-end" data-enable-box>
            <label class="text-xs text-gray-500">رابط المبدع المختصر (slug)<input type="text" wire:model="enableSlug" dir="ltr" class="{{ $inputClass }}"></label>
            <button type="button" wire:click="enable" class="rounded-lg bg-primary-600 text-white px-4 py-2 text-sm font-bold">تفعيل التسييل</button>
        </div>
    @endif

    <div class="flex flex-wrap gap-2 items-center">
        @foreach ($filters as $key => $label)
            <button type="button" wire:click="$set('filter', '{{ $key }}')" class="rounded-lg px-3 py-1.5 text-sm {{ $filter === $key ? 'bg-primary-600 text-white font-bold' : 'border border-gray-300 dark:border-white/10' }}">{{ $label }}</button>
        @endforeach
        <input type="search" wire:model.live.debounce.400ms="search" dir="ltr" placeholder="بحث بالرابط المختصر" class="{{ $inputClass }} max-w-xs">
    </div>

    <table class="w-full text-sm" data-account-list>
        <thead><tr class="text-gray-500"><th class="text-start">المبدع</th><th>متاح</th><th>محجوز</th><th>مصروف</th><th>الحالة</th><th></th></tr></thead>
        <tbody>
        @forelse ($d['page'] as $a)
            <tr class="border-t border-gray-100 dark:border-white/10">
                <td dir="ltr" class="text-start">{{ $a->creatorProfile?->slug }}</td>
                <td class="text-center">{{ $fmt((int) $a->available_minor, $a->currency) }}</td>
                <td class="text-center">{{ $fmt((int) $a->reserved_minor, $a->currency) }}</td>
                <td class="text-center">{{ $fmt((int) $a->paid_lifetime_minor, $a->currency) }}</td>
                <td class="text-center">{{ \App\Models\CreatorEarningAccount::STATUSES[$a->monetization_status] ?? $a->monetization_status }}@if ($a->isOnHold()) · معلَّق@endif</td>
                <td><button type="button" wire:click="show('{{ $a->public_id }}')" class="text-primary-600 text-xs font-bold">عرض</button></td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-gray-500 py-4">لا حسابات.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div>{{ $d['page']->links() }}</div>

    @if ($o)
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-account-detail>
            <div class="font-bold"><span dir="ltr">{{ $o->creatorProfile?->slug }}</span> — {{ \App\Models\CreatorEarningAccount::STATUSES[$o->monetization_status] ?? $o->monetization_status }}</div>
            <div class="text-sm">متاح {{ $fmt((int) $o->available_minor, $o->currency) }} · محجوز {{ $fmt((int) $o->reserved_minor, $o->currency) }} · مصروف عمره {{ $fmt((int) $o->paid_lifetime_minor, $o->currency) }}</div>
            @if ($d['ledger'] && ((int) $d['ledger']['available'] !== (int) $o->available_minor || (int) $d['ledger']['reserved'] !== (int) $o->reserved_minor || (int) $d['ledger']['paid'] !== (int) $o->paid_lifetime_minor))
                <div class="text-xs text-amber-600" data-drift>اللقطة تخالف الدفتر؛ شغّل creator-finance:reconcile.</div>
            @endif
            @if ($o->isOnHold())
                <div class="text-xs text-amber-600" data-hold-info>معلَّق: {{ $d['holdReasons'][$o->hold_reason_code] ?? $o->hold_reason_code }} — {{ $o->hold_note }}@if ($o->holdCase) — حالة <span dir="ltr">{{ $o->holdCase->case_number }}</span>@endif</div>
            @endif

            <input type="text" wire:model="note" maxlength="500" placeholder="ملاحظة (مطلوبة للإيقاف والتعليق والرفع والتعديل)" class="{{ $inputClass }}">

            @if ($d['can']['monetization'])
                <div class="flex gap-2">
                    @if ($o->isEnabled())<button type="button" wire:click="pause" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm">إيقاف التسييل مؤقتًا</button>
                    @else<button type="button" wire:click="resume" class="rounded-lg bg-primary-600 text-white px-3 py-1.5 text-sm font-bold">استئناف التسييل</button>@endif
                </div>
            @endif

            @if ($d['can']['holds'])
                <div class="flex flex-wrap gap-2 items-end">
                    @if ($o->isOnHold())
                        <button type="button" wire:click="releaseHold" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm">رفع التعليق</button>
                    @else
                        <select wire:model="holdReason" class="{{ $inputClass }} max-w-xs">@foreach ($d['holdReasons'] as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
                        <input type="text" wire:model="holdCase" dir="ltr" placeholder="رقم حالة إشراف (اختياري)" class="{{ $inputClass }} max-w-xs">
                        <button type="button" wire:click="placeHold" class="rounded-lg bg-amber-600 text-white px-3 py-1.5 text-sm font-bold">تعليق الدفعات</button>
                    @endif
                </div>
            @endif

            @if ($d['can']['adjust'])
                <div class="flex flex-wrap gap-2 items-end" data-adjust-box>
                    <select wire:model="adjustDirection" class="{{ $inputClass }} max-w-[8rem]"><option value="credit">إضافة</option><option value="debit">خصم</option></select>
                    <input type="text" wire:model="adjustAmount" dir="ltr" inputmode="decimal" placeholder="المبلغ" class="{{ $inputClass }} max-w-[8rem]">
                    <select wire:model="adjustReason" class="{{ $inputClass }} max-w-xs">@foreach ($d['adjustReasons'] as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
                    <button type="button" wire:click="adjust" class="rounded-lg bg-primary-600 text-white px-3 py-1.5 text-sm font-bold">تسجيل تعديل</button>
                </div>
            @endif

            <table class="w-full text-xs" data-ledger>
                <thead><tr class="text-gray-500"><th class="text-start">النوع</th><th>متاح Δ</th><th>محجوز Δ</th><th>مدفوع Δ</th><th>السبب</th><th>التاريخ</th></tr></thead>
                <tbody>
                @foreach ($d['entries'] as $e)
                    <tr class="border-t border-gray-100 dark:border-white/10 text-center">
                        <td class="text-start">{{ $e->typeLabel() }}</td><td dir="ltr">{{ $e->available_delta_minor }}</td><td dir="ltr">{{ $e->reserved_delta_minor }}</td><td dir="ltr">{{ $e->paid_delta_minor }}</td>
                        <td>{{ $e->reason_code }}@if ($e->note) — {{ $e->note }}@endif</td><td>{{ $e->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
