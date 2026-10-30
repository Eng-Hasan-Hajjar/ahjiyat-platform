<?php

namespace App\Services\Database;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E31 (U2/U3): حارس "التاريخ المالي لا يُمحى عرَضًا".
 *
 * المشكلة (متطابقة على SQLite وMySQL): مفاتيح user_id على جداول السجلات المالية/المكافآت مُعرَّفة ON DELETE CASCADE، فحذف مستخدم من لوحة الإدارة
 * (مدير أعلى فقط) كان يمسح دفتر عملاته ومكافآته وطلبات استبداله بصمت. الترحيلات التاريخية لا تُعدَّل، فالحماية هنا على مستوى التطبيق:
 * لا يُعرض/لا يُسمح بحذف مستخدم له أي سجلّ مالي أو رصيد؛ البديل المقصود هو التجميد (users.freeze) الذي يحفظ التاريخ للتدقيق.
 *
 * يقرأ فقط (exists) بلا أي كتابة. الجداول ثابتة هنا عمدًا (قائمة بيضاء) ويثبّتها اختبار E31 مقابل DDL الفعلي، فإضافة جدول مالي جديد بـCASCADE على users تُظهر فشل الاختبار.
 */
final class FinancialHistoryGuard
{
    /**
     * جدول => [عمود المستخدم، وصف بالعربية]. كلها تُحذف بالتتالي عند حذف المستخدم (أو تمنعه بـRESTRICT كـstore_purchases).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const HISTORY_TABLES = [
        'currency_transactions' => ['user_id', 'دفتر العملات'],
        'redemption_requests' => ['user_id', 'طلبات الاستبدال'],
        'store_purchases' => ['user_id', 'مشتريات المتجر'],
        'inventory_transactions' => ['user_id', 'حركات المخزون'],
        'xp_transactions' => ['user_id', 'حركات الخبرة'],
        'competitive_reward_grants' => ['user_id', 'جوائز الأحداث التنافسية'],
        'sponsorship_reward_bonuses' => ['user_id', 'مكافآت الرعاية'],
        'sponsorship_payments' => ['user_id', 'طلبات دعم ودفع الرعاية'],
        // E39: حساب أرباح المبدع (FK بـRESTRICT): مستخدم له حساب أرباح لا يُحذف، والبديل المقصود هو التجميد الذي يحفظ الدفتر للتدقيق.
        'creator_earning_accounts' => ['user_id', 'حساب أرباح المبدع ودفتره ودفعاته'],
        // E40: مشتريات رقمية استهلاكية (المسار المميز) بـRESTRICT: مستخدم له مشترى لا يُحذف، والبديل المقصود هو التجميد الذي يحفظ السجل للتدقيق.
        'digital_purchases' => ['user_id', 'مشتريات المسار المميز'],
        // E43: وصول مدفوع لسلاسل مبدعين (المشتري) ومبيعات مباشرة (المبدع؛ user_id فيها = مستخدم المبدع البائع): كلاهما بـRESTRICT ويحفظان التاريخ التجاري/المحاسبي للتدقيق؛ البديل المقصود هو التجميد.
        'creator_series_accesses' => ['user_id', 'وصول مدفوع لسلاسل المبدعين'],
        'creator_direct_sales' => ['user_id', 'مبيعات مباشرة للمبدع'],
        // E44: عضوية الكتّاب في المواسم وقرارات جوائزهم (قد تحمل التزامًا ماليًا قابلًا للتدقيق) وبطاقات الداعمين الموسمية (مرتبطة بدفعات الرعاية المؤكَّدة): كلها بـRESTRICT؛ البديل المقصود هو التجميد.
        // نقاط الدعم وإحصاءاتها مشتقّة من مشتريات المتجر/دفعات الرعاية المحروسة أعلاه فلا تُكرَّر هنا.
        'season_writer_entries' => ['user_id', 'عضوية دوري كتّاب المواسم'],
        'season_writer_awards' => ['user_id', 'جوائز دوري الكتّاب'],
        'season_supporter_cards' => ['user_id', 'بطاقات داعمي المواسم'],
    ];

    /** @return list<string> أوصاف الأقسام التي فيها سجلّ لهذا المستخدم (فارغة = لا تاريخ مالي). */
    public function historyFor(User $user): array
    {
        $found = [];

        foreach (self::HISTORY_TABLES as $table => [$column, $label]) {
            if (DB::table($table)->where($column, $user->getKey())->exists()) {
                $found[] = $label;
            }
        }

        $hasBalance = DB::table('wallets')->where('user_id', $user->getKey())
            ->where(fn ($q) => $q->where('available_balance', '>', 0)->orWhere('pending_balance', '>', 0))->exists();

        if ($hasBalance) {
            $found[] = 'رصيد المحفظة';
        }

        return $found;
    }

    public function hasHistory(User $user): bool
    {
        return $this->historyFor($user) !== [];
    }
}
