<?php

namespace App\Services\AiAssistant;

/** E45 - أسئلة مقترحة ثابتة لحالة الواجهة الفارغة (المادة 45). نصوص حتمية من الكود، لا من نموذج ولا من محتوى مستخدمين. */
final class Suggestions
{
    /** @return list<string> */
    public static function for(string $audience): array
    {
        $base = [
            'كيف أبدأ في المنصة؟',
            'ما هو الموسم الحالي؟',
            'كيف يعمل مسار الأحجيات؟',
            'كيف أشارك في دوري الكتّاب؟',
            'كيف تعمل بطاقات الداعم؟',
            'كيف أدخل المنافسات؟',
        ];

        return $audience === TatawwurSettings::AUDIENCE_MEMBER ? ['ما تقدمي الحالي؟', ...$base] : $base;
    }
}
