<x-filament-panels::page>
    @php
        $d = $this->data();
        $o = $d['opened'];
        $inputClass = 'rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm w-full';
        $statusLabels = ['draft' => 'مسودة', 'scheduled' => 'مجدول', 'active' => 'فعّال', 'ended' => 'منتهٍ', 'archived' => 'مؤرشف'];
    @endphp

    <p class="text-xs text-gray-500">المسار منتج تقدّم دوري مستقل عن الموسم. بعد الجدولة تُقفل الشرائح والمكافآت والمهام والسعر والنافذة. لا مسار يمنح لاعبًا نقاطًا أو مميزًا يدويًا؛ النقاط من إكمال مهام فقط، والمميز من التحقق المالي فقط.</p>

    <div class="flex flex-wrap items-center gap-2" data-pass-tabs>
        @foreach ($d['tabs'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" class="rounded-lg px-3 py-1.5 text-sm {{ $tab === $key ? 'bg-primary-600 text-white font-bold' : 'border border-gray-300 dark:border-white/10' }}">{{ $label }}</button>
        @endforeach
        @if ($d['canManage'])
            <button type="button" wire:click="$toggle('showCreate')" class="ms-auto rounded-lg bg-primary-600 text-white px-3 py-1.5 text-sm font-bold" data-pass-new>مسار جديد</button>
        @endif
    </div>

    @if ($showCreate && $d['canManage'])
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-pass-create>
            <div class="grid gap-3 md:grid-cols-2">
                <label class="text-sm">الاسم<input type="text" wire:model="form.name" maxlength="120" class="{{ $inputClass }}"></label>
                <label class="text-sm">العنوان الفرعي<input type="text" wire:model="form.subtitle" maxlength="160" class="{{ $inputClass }}"></label>
                <label class="text-sm">المفتاح الداخلي (اختياري)<input type="text" wire:model="form.internal_key" dir="ltr" maxlength="60" class="{{ $inputClass }}"></label>
                <label class="text-sm">أيام مهلة الاستلام<input type="number" wire:model="form.claim_grace_days" min="0" max="{{ (int) config('ahjiyat_pass.lifecycle.claim_grace_max_days', 14) }}" class="{{ $inputClass }}"></label>
                <label class="text-sm">البداية<input type="datetime-local" wire:model="form.starts_at" class="{{ $inputClass }}"></label>
                <label class="text-sm">النهاية<input type="datetime-local" wire:model="form.ends_at" class="{{ $inputClass }}"></label>
                <label class="text-sm flex items-center gap-2 mt-6"><input type="checkbox" wire:model="form.premium_enabled">المسار المميز مفعّل</label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="text-sm">السعر (وحدة رئيسية)<input type="text" wire:model="form.premium_price" dir="ltr" placeholder="9.99" class="{{ $inputClass }}"></label>
                    <label class="text-sm">العملة
                        <select wire:model="form.currency" class="{{ $inputClass }}">@foreach ($d['currencies'] as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
                    </label>
                </div>
                <label class="text-sm">اللون المميز
                    <select wire:model="form.accent" class="{{ $inputClass }}">@foreach ($d['accents'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                </label>
                <label class="text-sm">الخلفية
                    <select wire:model="form.background" class="{{ $inputClass }}">@foreach ($d['backgrounds'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                </label>
                <label class="text-sm md:col-span-2">شعار نصي قصير<input type="text" wire:model="form.tagline" maxlength="{{ (int) config('ahjiyat_pass.theme.tagline_max', 90) }}" class="{{ $inputClass }}"></label>
            </div>
            <button type="button" wire:click="create" class="rounded-lg bg-primary-600 text-white px-4 py-2 text-sm font-bold">إنشاء مسودة</button>
        </div>
    @endif

    <table class="w-full text-sm" data-pass-list>
        <thead><tr class="text-gray-500"><th class="text-start">المسار</th><th>الحالة</th><th>النافذة</th><th>الشرائح</th><th>لاعبون</th><th>مميز</th><th></th></tr></thead>
        <tbody>
        @forelse ($d['page'] as $p)
            @php($stat = $d['players']->get($p->id))
            <tr class="border-t border-gray-100 dark:border-white/10">
                <td class="text-start font-bold">{{ $p->name }}<div class="text-xs text-gray-500" dir="ltr">{{ $p->internal_key }}</div></td>
                <td class="text-center">{{ $statusLabels[$p->effectiveStatus()] ?? $p->status }}</td>
                <td class="text-center text-xs">{{ $p->starts_at->format('Y-m-d') }} ← {{ $p->ends_at->format('Y-m-d') }}</td>
                <td class="text-center">{{ $p->total_tiers }}</td>
                <td class="text-center">{{ (int) ($stat->n ?? 0) }}</td>
                <td class="text-center">{{ (int) ($stat->premium ?? 0) }}</td>
                <td><button type="button" wire:click="show('{{ $p->public_id }}')" class="text-primary-600 text-xs font-bold">فتح</button></td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-gray-500 py-4">لا مسارات في هذا العرض.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div>{{ $d['page']->links() }}</div>

    @if ($o)
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-pass-detail>
            <div class="font-bold">{{ $o->name }} — {{ $statusLabels[$o->effectiveStatus()] ?? $o->status }}</div>
            <div class="text-xs text-gray-500">
                {{ $o->starts_at->format('Y-m-d H:i') }} ← {{ $o->ends_at->format('Y-m-d H:i') }} · مهلة الاستلام حتى {{ $o->claim_ends_at->format('Y-m-d') }} · {{ $o->total_tiers }} شريحة
                @if ($o->premium_enabled && $o->premium_price_minor) · المميز {{ \App\Services\Sponsorship\SponsorshipMoney::format((int) $o->premium_price_minor, (string) $o->currency) }}@elseif (! $o->premium_enabled) · بلا مميز@endif
                @if ($d['pending'] > 0) · <strong class="text-amber-600">{{ $d['pending'] }} طلب شراء مفتوح</strong>@endif
            </div>

            @if (count($d['problems']) > 0)
                <div class="rounded-lg border border-amber-300 bg-amber-50 dark:bg-amber-500/10 p-3 text-xs space-y-1" data-pass-problems>
                    <div class="font-bold">غير جاهز للنشر:</div>
                    <ul class="list-disc ps-5">@foreach ($d['problems'] as $problem)<li>{{ $problem }}</li>@endforeach</ul>
                </div>
            @elseif ($o->locked_at === null)
                <div class="rounded-lg border border-emerald-300 bg-emerald-50 dark:bg-emerald-500/10 p-3 text-xs font-bold">جاهز للنشر.</div>
            @endif

            <div class="flex flex-wrap gap-2">
                <a href="{{ \App\Filament\Pages\AhjiyatPassBuilderPage::getUrl(['pass' => $o->public_id]) }}" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm">{{ $o->locked_at === null ? 'منشئ المسار' : 'عرض المحتوى' }}</a>
                <a href="{{ \App\Filament\Pages\AhjiyatPassAnalyticsPage::getUrl(['pass' => $o->public_id]) }}" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm">التحليلات</a>
                @if ($d['canPublish'])
                    @if ($o->locked_at === null)
                        <button type="button" wire:click="schedule" wire:confirm="نشر المسار يقفل الشرائح والمكافآت والمهام والسعر والنافذة. متابعة؟" class="rounded-lg bg-emerald-600 text-white px-3 py-1.5 text-sm font-bold" data-pass-schedule>نشر (جدولة)</button>
                        <button type="button" wire:click="deleteDraft" wire:confirm="حذف المسودة نهائيًا؟" class="rounded-lg bg-red-600 text-white px-3 py-1.5 text-sm font-bold">حذف المسودة</button>
                    @elseif (in_array($o->status, ['scheduled', 'active'], true))
                        @if ($o->starts_at->isFuture())
                            <button type="button" wire:click="unschedule" wire:confirm="إلغاء الجدولة؟ يعود المسار مسودة (ممكن فقط قبل البدء وبلا تقدّم لاعبين)." class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm">إلغاء الجدولة</button>
                        @endif
                        <input type="text" wire:model="endReason" maxlength="200" placeholder="سبب الإنهاء المبكر (اختياري)" class="{{ $inputClass }} !w-64">
                        <button type="button" wire:click="end" wire:confirm="إنهاء المسار الآن؟ تتوقف النقاط الجديدة وتبدأ مهلة الاستلام." class="rounded-lg bg-amber-600 text-white px-3 py-1.5 text-sm font-bold">إنهاء الآن</button>
                    @elseif ($o->status === 'ended')
                        <button type="button" wire:click="archive" wire:confirm="أرشفة المسار؟ لا استلام بعدها." class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm">أرشفة</button>
                    @endif
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>
