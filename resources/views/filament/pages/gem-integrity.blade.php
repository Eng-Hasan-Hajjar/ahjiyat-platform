<x-filament-panels::page>
    @php
        $run = $this->getRun();
        $commit = $this->commitments();
        $currencyNames = \App\Models\Currency::query()->pluck('name', 'id');
    @endphp

    <div style="display:flex; flex-direction:column; gap:1.5rem;">

        <p style="color:#94a3b8; font-size:.85rem;">
            صفحة قراءة فقط. تعرض نتيجة آخر فحص محفوظ لمطابقة رصيدَي (المتاح والمعلَّق) كل محفظة مع دفتر المعاملات. لا تشغّل فحصًا عند فتحها،
            ولا تعدّل أي رصيد، ولا يوجد إصلاح تلقائي. «فرق مثبت» يثبت وجود فرق حسابي ولا يحدّد سببه؛ «لا يمكن الجزم» تعني وجود قرينة تمنع نسبته إلى خطأ
            (رصيد افتتاحي/مرحَّل محتمل، حركة جارية، شذوذ في الدفتر...).
        </p>

        @if ($this->getLastAttemptFailed())
            <div style="color:#f59e0b; font-weight:800;" data-gem-integrity-failed>⚠ آخر محاولة فحص لم تكتمل (فشلت) بعد آخر فحص موثوق؛ راجع سجلات النظام وأعد التشغيل.</div>
        @endif

        @if ($run === null)
            <x-filament::section data-gem-integrity-state="never">
                <x-slot name="heading">آخر فحص</x-slot>
                <div style="font-size:1.4rem; font-weight:900;">لم يُفحص بعد</div>
                <div style="color:#94a3b8; font-size:.85rem; margin-top:.25rem;">شغّل: <span dir="ltr">php artisan gems:audit</span></div>
            </x-filament::section>
        @else
            <x-filament::section data-gem-integrity-state="ran">
                <x-slot name="heading">آخر فحص موثوق</x-slot>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:1rem; margin-bottom:.75rem;">
                    <x-filament::section><div style="font-size:1.8rem; font-weight:900;" data-gem-proven>{{ $run->proven_differences }}</div><div style="color:#94a3b8;">فرق مثبت</div></x-filament::section>
                    <x-filament::section><div style="font-size:1.8rem; font-weight:900;" data-gem-undetermined>{{ $run->undetermined }}</div><div style="color:#94a3b8;">لا يمكن الجزم</div></x-filament::section>
                    <x-filament::section><div style="font-size:1.8rem; font-weight:900;" data-gem-matched>{{ $run->matched }}</div><div style="color:#94a3b8;">مطابق</div></x-filament::section>
                    <x-filament::section><div style="font-size:1.8rem; font-weight:900;">{{ $run->scanned }}</div><div style="color:#94a3b8;">المفحوص</div></x-filament::section>
                </div>
                <div style="color:#64748b; font-size:.8rem;">
                    اكتمل {{ $run->finished_at?->format('Y-m-d H:i') }} · النطاق: {{ $run->scope_kind === 'all_currencies' ? 'كل العملات' : 'عملات محددة' }}
                    · دفعة {{ $run->chunk }} محفظة · نافذة الحركة الجارية {{ $run->grace_seconds }} ث
                </div>
                @if (! empty($run->reason_counts))
                    <ul style="margin-top:.5rem; font-size:.85rem; color:#94a3b8; list-style:disc; padding-inline-start:1.25rem;" data-gem-reasons>
                        @foreach ($run->reason_counts as $code => $count)
                            <li>{{ $this->reasonLabel($code) }}: {{ $count }}</li>
                        @endforeach
                    </ul>
                @endif
                @if ($run->findings_truncated)
                    <div style="color:#f59e0b; font-size:.85rem; margin-top:.5rem;">الأعداد أعلاه كاملة، لكن الحالات المفصّلة أدناه مقتطعة بسقف لكل حكم.</div>
                @endif
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">الحالات غير المطابقة</x-slot>

                <div style="margin-bottom:.75rem;">
                    <label for="gem-verdict" style="font-size:.85rem; color:#94a3b8;">الحكم</label>
                    <select id="gem-verdict" wire:model.live="verdict" style="margin-inline-start:.5rem; padding:.25rem .5rem; border-radius:.5rem;">
                        @foreach ($this->verdictOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                @php $rows = $this->findings(); @endphp

                @if ($rows->isEmpty())
                    <div style="color:#94a3b8;" data-gem-findings-empty>لا توجد حالات غير مطابقة في هذا الفحص ضمن هذه التصفية.</div>
                @else
                    <div style="overflow-x:auto;">
                        <table style="width:100%; font-size:.85rem; border-collapse:collapse;" data-gem-findings>
                            <thead>
                                <tr style="text-align:start; color:#94a3b8;">
                                    <th style="text-align:start; padding:.4rem;">المستخدم</th>
                                    <th style="text-align:start; padding:.4rem;">العملة</th>
                                    <th style="text-align:start; padding:.4rem;">الحكم</th>
                                    <th style="text-align:start; padding:.4rem;">المتاح (مخزَّن / دفتر / فرق)</th>
                                    <th style="text-align:start; padding:.4rem;">المعلَّق (مخزَّن / دفتر / فرق)</th>
                                    <th style="text-align:start; padding:.4rem;">الأسباب</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $f)
                                    <tr style="border-top:1px solid rgba(148,163,184,.15);">
                                        <td style="padding:.4rem;">
                                            @if ($this->canOpenUsers() && $f->user)
                                                <a href="{{ \App\Filament\Resources\UserResource::getUrl('view', ['record' => $f->user_id]) }}">{{ $f->user->name }}</a>
                                            @else
                                                {{ $f->user?->name ?? '—' }}
                                            @endif
                                            <span style="color:#64748b;" dir="ltr">#{{ $f->user_id }}</span>
                                        </td>
                                        <td style="padding:.4rem;">{{ $f->currency?->name ?? $currencyNames[$f->currency_id] ?? '—' }}</td>
                                        <td style="padding:.4rem; font-weight:800;" data-gem-verdict>{{ $this->verdictLabel($f->verdict) }}</td>
                                        <td style="padding:.4rem;" dir="ltr">{{ $f->stored_available }} / {{ $f->derived_available }} / {{ $f->diffAvailable() > 0 ? '+' : '' }}{{ $f->diffAvailable() }}</td>
                                        <td style="padding:.4rem;" dir="ltr">{{ $f->stored_pending }} / {{ $f->derived_pending }} / {{ $f->diffPending() > 0 ? '+' : '' }}{{ $f->diffPending() }}</td>
                                        <td style="padding:.4rem; color:#94a3b8;">
                                            @forelse (($f->reasons ?? []) as $code)
                                                <div>{{ $this->reasonLabel($code) }}</div>
                                            @empty
                                                —
                                            @endforelse
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div style="display:flex; gap:.75rem; align-items:center; margin-top:.75rem; font-size:.85rem;">
                        <button type="button" wire:click="previousPage" @disabled($pageNumber <= 1) style="padding:.25rem .75rem;">السابق</button>
                        <span>صفحة {{ min($pageNumber, $this->lastPage()) }} من {{ $this->lastPage() }} ({{ $this->total() }} حالة)</span>
                        <button type="button" wire:click="nextPage" @disabled($pageNumber >= $this->lastPage()) style="padding:.25rem .75rem;">التالي</button>
                    </div>
                @endif
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">الالتزامات المرتبطة بالاستبدال (ما أمكن إثباته)</x-slot>
            <div style="color:#94a3b8; font-size:.85rem; margin-bottom:.75rem;" data-gem-budget>
                ميزانية المكافآت: <strong>غير معرَّفة في النظام</strong> (لا صندوق ولا سقف مكافآت جواهر في الكود أو الإعدادات أو الجداول)، فلا يُعرض أي رقم لها ولا يُخمَّن.
                الجواهر المطلوب استبدالها خُصمت من المحفظة لحظة الطلب، فهي تُعرض هنا منفصلة ولا تُضاف إلى أرصدة المحافظ ولا إلى صفوف الخصم في الدفتر (لا عدّ مزدوج).
            </div>

            @if ($commit['currencies'] === [])
                <div style="color:#94a3b8;" data-gem-commitments-empty>لا طلبات استبدال مفتوحة (قيد المراجعة أو مقبولة بانتظار التنفيذ).</div>
            @else
                <div style="overflow-x:auto;">
                    <table style="width:100%; font-size:.85rem; border-collapse:collapse;" data-gem-commitments>
                        <thead>
                            <tr style="color:#94a3b8;">
                                <th style="text-align:start; padding:.4rem;">العملة</th>
                                <th style="text-align:start; padding:.4rem;">طلبات مفتوحة</th>
                                <th style="text-align:start; padding:.4rem;">جواهر مطلوبة (ملتزم بها)</th>
                                <th style="text-align:start; padding:.4rem;">مدعومة بقيد خصم واحد</th>
                                <th style="text-align:start; padding:.4rem;">بلا قيد خصم مطابق</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($commit['currencies'] as $row)
                                <tr style="border-top:1px solid rgba(148,163,184,.15);">
                                    <td style="padding:.4rem;">{{ $currencyNames[$row['currency_id']] ?? '—' }}</td>
                                    <td style="padding:.4rem;">{{ $row['open_requests'] }}</td>
                                    <td style="padding:.4rem;">{{ number_format($row['open_gems']) }}</td>
                                    <td style="padding:.4rem;">{{ $row['backed_requests'] }}</td>
                                    <td style="padding:.4rem;">{{ $row['unbacked_requests'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <ul style="margin-top:.75rem; font-size:.85rem; color:#94a3b8; list-style:disc; padding-inline-start:1.25rem;" data-gem-footprints>
                <li>طلبات لها أكثر من قيد استرداد: {{ $commit['footprints']['duplicate_refunds'] }}</li>
                <li>طلبات مرفوضة بلا قيد استرداد: {{ $commit['footprints']['rejected_without_refund'] }}</li>
                <li>طلبات لها قيد استرداد وحالتها ليست «مرفوض»: {{ $commit['footprints']['refunded_but_not_rejected'] }}</li>
            </ul>
        </x-filament::section>
    </div>
</x-filament-panels::page>
