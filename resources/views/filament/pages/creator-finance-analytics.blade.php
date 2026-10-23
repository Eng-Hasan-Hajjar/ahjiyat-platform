<x-filament-panels::page>
    @php
        $d = $this->data();
        $c = $d['currency'];
        $fmt = fn (int $minor) => \App\Services\CreatorFinance\CreatorMoney::format($minor, $c);
    @endphp

    <p class="text-xs text-gray-500">أرقام مجمَّعة فقط بعملة المحاسبة ({{ $c }}). لا ترتيب مبدعين ولا لوحة متصدرين؛ الاطلاع على حساب فردي من «حسابات الأرباح».</p>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-finance-liability>
        @foreach (['حسابات الأرباح' => number_format($d['accounts']), 'مستحق للمبدعين (متاح + محجوز)' => $fmt($d['liability']['owed_minor']), 'متاح' => $fmt($d['liability']['available_minor']), 'محجوز لدفعات' => $fmt($d['liability']['reserved_minor']), 'إجمالي ما صُرف' => $fmt($d['liability']['paid_lifetime_minor']), 'تسييل مُفعَّل' => number_format($d['account_states']['enabled']), 'تسييل موقوف' => number_format($d['account_states']['paused']), 'معلَّق للدفعات' => number_format($d['account_states']['on_hold'])] as $label => $value)
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <div class="text-xs text-gray-500">{{ $label }}</div>
                <div class="text-xl font-bold mt-1">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid md:grid-cols-3 gap-6">
        <div>
            <h3 class="font-bold mb-2">الدفعات</h3>
            <table class="w-full text-sm"><tbody>
            @foreach ($d['payouts'] as $row)
                <tr class="border-t border-gray-100 dark:border-white/10"><td>{{ $row['label'] }}</td><td class="text-center">{{ number_format($row['count']) }}</td><td class="text-center">{{ $fmt($row['total_minor']) }}</td></tr>
            @endforeach
            </tbody></table>
            <p class="text-xs text-gray-500 mt-2">أقدم طلب مفتوح: {{ $d['oldest_open_payout_hours'] === null ? '—' : $d['oldest_open_payout_hours'].' ساعة' }}</p>
        </div>
        <div>
            <h3 class="font-bold mb-2">الفترات</h3>
            <table class="w-full text-sm"><tbody>
            @foreach ($d['periods'] as $row)
                <tr class="border-t border-gray-100 dark:border-white/10"><td>{{ $row['label'] }}</td><td class="text-center">{{ number_format($row['count']) }}</td></tr>
            @endforeach
            </tbody></table>
        </div>
        <div>
            <h3 class="font-bold mb-2">قيود الدفتر (آخر 30 يومًا)</h3>
            <table class="w-full text-sm"><tbody>
            @foreach ($d['entries_30d'] as $row)
                <tr class="border-t border-gray-100 dark:border-white/10"><td>{{ $row['label'] }}</td><td class="text-center">{{ number_format($row['count']) }}</td></tr>
            @endforeach
            </tbody></table>
        </div>
    </div>

    <div>
        <h3 class="font-bold mb-2">آخر الفترات المُقفلة</h3>
        <table class="w-full text-sm" data-finance-recent>
            <thead><tr class="text-gray-500"><th class="text-start">الفترة</th><th>المجمّع</th><th>موزَّع</th><th>غير موزَّع</th><th>مبدعون</th><th>وحدات</th></tr></thead>
            <tbody>
            @forelse ($d['recent_periods'] as $p)
                <tr class="border-t border-gray-100 dark:border-white/10 text-center"><td class="text-start">{{ $p['label'] }}</td><td>{{ $fmt($p['pool_minor']) }}</td><td>{{ $fmt($p['allocated_minor']) }}</td><td>{{ $fmt($p['unallocated_minor']) }}</td><td>{{ $p['eligible_creators'] }}</td><td>{{ number_format($p['total_units']) }}</td></tr>
            @empty
                <tr><td colspan="6" class="text-gray-500 py-3">لا فترات مُقفلة بعد.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
