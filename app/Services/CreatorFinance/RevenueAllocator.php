<?php

namespace App\Services\CreatorFinance;

/**
 * E39 - توزيع المجمّع على المبدعين المؤهَّلين بأعداد صحيحة وطريقة «أكبر باقٍ» (Hamilton) الحتمية.
 *
 *  - حصة كل مبدع = ⌊المجمّع × وحداته ÷ مجموع الوحدات⌋، ثم تُوزَّع الوحدات الصغرى المتبقية (أقل من عدد المبدعين) على أكبر الباقيات تنازليًا،
 *    وعند التعادل بأصغر creator_profile_id أولًا. المجموع النهائي = المجمّع تمامًا بلا فقد ولا زيادة. لا مبدعين مؤهَّلون ⇒ المجمّع كله «غير موزَّع».
 *  - دالة صرفة: لا قاعدة بيانات ولا ساعة ولا عشوائية؛ نفس المدخلات تعطي نفس المخرجات دائمًا.
 */
final class RevenueAllocator
{
    /**
     * @param  list<array{creator_profile_id:int, qualified_units:int}>  $eligible  مبدعون مؤهَّلون بوحدات > 0
     * @return array{allocations: array<int, array{allocated_minor:int, bonus:int}>, allocated_minor:int, unallocated_minor:int, total_units:int}
     */
    public static function allocate(int $poolMinor, array $eligible, int $maxTotalUnits): array
    {
        if ($poolMinor < 0) {
            throw new CreatorFinanceException('المجمّع لا يكون سالبًا.');
        }

        $rows = [];
        $total = 0;

        foreach ($eligible as $row) {
            $units = (int) $row['qualified_units'];

            if ($units <= 0) {
                continue;
            }

            $rows[(int) $row['creator_profile_id']] = $units;
            $total += $units;
        }

        if ($total > $maxTotalUnits) {
            throw new CreatorFinanceException('مجموع الوحدات يتجاوز السقف الحسابي الآمن.');
        }

        if ($total === 0) {
            return ['allocations' => [], 'allocated_minor' => 0, 'unallocated_minor' => $poolMinor, 'total_units' => 0];
        }

        $out = [];
        $remainders = [];
        $sum = 0;

        foreach ($rows as $creatorId => $units) {
            [$share, $remainder] = CreatorMoney::mulDiv($poolMinor, $units, $total);
            $out[$creatorId] = ['allocated_minor' => $share, 'bonus' => 0];
            $remainders[$creatorId] = $remainder;
            $sum += $share;
        }

        $left = $poolMinor - $sum;

        // أكبر باقٍ أولًا، ثم أصغر معرّف مبدع. $left < عدد المبدعين دائمًا (مجموع الأجزاء الكسرية < عددهم).
        $order = array_keys($rows);
        usort($order, fn (int $a, int $b) => [$remainders[$b], $a] <=> [$remainders[$a], $b]);

        foreach ($order as $creatorId) {
            if ($left <= 0) {
                break;
            }

            $out[$creatorId]['allocated_minor']++;
            $out[$creatorId]['bonus'] = 1;
            $left--;
        }

        ksort($out);

        return ['allocations' => $out, 'allocated_minor' => $poolMinor, 'unallocated_minor' => 0, 'total_units' => $total];
    }
}
