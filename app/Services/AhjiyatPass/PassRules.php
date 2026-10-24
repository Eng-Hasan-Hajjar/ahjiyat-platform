<?php

namespace App\Services\AhjiyatPass;

use App\Models\AhjiyatPass;
use App\Models\QuestDefinition;
use App\Models\StoreItem;
use App\Services\Engagement\QuestEvaluatorRegistry;
use App\Services\Sponsorship\SponsorshipMoney;

/**
 * E40 - قواعد صلاحية مشتركة بين البنّاء ومدقّق الجاهزية والاستلام: عنصر مكافأة صالح؟ مهمة صالحة؟ عملة وسعر صالحان؟
 * كل دالة تُرجع null (صالح) أو [رمز, رسالة عربية]. لا أثر جانبي ولا كتابة.
 */
final class PassRules
{
    /** @return array{0: string, 1: string}|null */
    public static function itemProblem(StoreItem $item): ?array
    {
        $allowed = (array) config('ahjiyat_pass.rewards.allowed_item_types', ['cosmetic', 'access', 'digital']);

        if (! in_array($item->item_type, $allowed, true)) {
            return ['item_type', 'نوع العنصر «'.$item->name.'» غير مسموح كمكافأة مسار (المسموح: تجميلي/وصول/رقمي).'];
        }

        if (! in_array($item->fulfillment_type, [StoreItem::FULFILLMENT_INVENTORY, StoreItem::FULFILLMENT_ENTITLEMENT], true)) {
            return ['item_fulfillment', 'العنصر «'.$item->name.'» يدوي التسليم ولا يصلح مكافأة تُمنح آليًا.'];
        }

        if ($item->fulfillment_type === StoreItem::FULFILLMENT_ENTITLEMENT && blank($item->entitlement_key)) {
            return ['item_entitlement', 'العنصر «'.$item->name.'» بلا مفتاح امتياز.'];
        }

        if ($item->item_type === StoreItem::TYPE_COSMETIC && ! $item->isCosmeticEquippable()) {
            return ['item_cosmetic', 'العنصر التجميلي «'.$item->name.'» غير قابل للتجهيز (فتحة/تسليم مخزون).'];
        }

        if (! $item->is_active) {
            return ['item_inactive', 'العنصر «'.$item->name.'» غير نشط.'];
        }

        if ($item->ends_at !== null && $item->ends_at->isPast()) {
            return ['item_expired', 'العنصر «'.$item->name.'» انتهت صلاحيته.'];
        }

        if (! (bool) config('ahjiyat_pass.rewards.allow_sellable_items', false) && $item->activePrices()->exists()) {
            return ['item_sellable', 'العنصر «'.$item->name.'» معروض للبيع في المتجر؛ المكافأة الحصرية يجب ألا تُباع بالعملات (أزل أسعاره أو أنشئ عنصرًا حصريًا).'];
        }

        return null;
    }

    /** @return array{0: string, 1: string}|null */
    public static function questProblem(QuestDefinition $quest, ?AhjiyatPass $pass = null): ?array
    {
        if (! $quest->is_active) {
            return ['quest_inactive', 'المهمة «'.$quest->name.'» غير نشطة.'];
        }

        if (! app(QuestEvaluatorRegistry::class)->isValidConditionType((string) $quest->condition_type)) {
            return ['quest_condition', 'شرط المهمة «'.$quest->name.'» غير مدعوم.'];
        }

        if ($quest->missionPools()->exists()) {
            return ['quest_pooled', 'المهمة «'.$quest->name.'» ضمن مجموعة مهام (E34) تُسند للاعبين انتقائيًا؛ استخدم مهمة عامة لمسار الأحجيات.'];
        }

        if ($pass !== null) {
            if ($quest->ends_at !== null && $quest->ends_at->lte($pass->starts_at)) {
                return ['quest_window', 'نافذة المهمة «'.$quest->name.'» تنتهي قبل بدء المسار.'];
            }

            if ($quest->starts_at !== null && $quest->starts_at->gte($pass->ends_at)) {
                return ['quest_window', 'نافذة المهمة «'.$quest->name.'» تبدأ بعد انتهاء المسار.'];
            }
        }

        return null;
    }

    /** @return list<string> رموز العملات المسموحة للمسار (تقاطع قوائم الرعاية والدفع والمسار). */
    public static function currencies(): array
    {
        $pass = array_map('strtoupper', (array) config('ahjiyat_pass.premium.allowed_currencies', []));
        $payments = array_map('strtoupper', (array) config('payments.allowed_currencies', []));

        return array_values(array_filter(
            SponsorshipMoney::currencies(),
            static fn (string $c): bool => in_array($c, $pass, true) && ($payments === [] || in_array($c, $payments, true)),
        ));
    }

    public static function currencyAllowed(?string $currency): bool
    {
        return is_string($currency) && in_array(strtoupper($currency), self::currencies(), true);
    }

    /** @return array{0: string, 1: string}|null */
    public static function priceProblem(?int $minor, ?string $currency): ?array
    {
        if (! self::currencyAllowed($currency)) {
            return ['currency', 'عملة المسار غير مدعومة.'];
        }

        if ($minor === null || $minor <= 0) {
            return ['price', 'سعر المميز يجب أن يكون أكبر من صفر.'];
        }

        if ($minor < (int) config('ahjiyat_pass.premium.min_price_minor', 99) || $minor > (int) config('ahjiyat_pass.premium.max_price_minor', 50_000)) {
            return ['price_range', 'سعر المميز خارج الحدود المسموحة.'];
        }

        return null;
    }
}
