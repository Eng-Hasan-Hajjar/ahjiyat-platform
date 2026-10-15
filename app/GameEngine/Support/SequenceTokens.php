<?php

namespace App\GameEngine\Support;

use App\Models\Puzzle;

/**
 * E26 (Game anti-cheat): معرّفات عناصر لعبة الترتيب المعروضة للعميل.
 *
 * المشكلة: كانت الواجهة تُرسل لكل عنصر `idx` = فهرسه الأصلي في game_config.items، والإجابة الصحيحة هي بالضبط ترتيب الفهارس التصاعدي (solution_data.order = array_keys(items)).
 * فمن يقرأ مصدر الصفحة أو يستدعي API يحلّ اللغز دون تفكير بترتيب الأرقام تصاعديًا، مع أن الخلط السيرفري كان يهدف لإخفاء الترتيب.
 *
 * الحل: رمز معتم لكل عنصر = HMAC-SHA256(مفتاح التطبيق، "seq:<puzzle>:<idx>") مقتطع. حتمي (يُحسب بالطلب نفسه عند العرض والتحقق، بلا حالة ولا جدول)،
 * غير قابل للتنبؤ دون المفتاح، وترتيب الرموز لا يكشف ترتيب الإجابة. التحقق يقارن بالرموز المتوقعة للترتيب الصحيح، ولا يقبل الأعداد الخام أبدًا.
 */
final class SequenceTokens
{
    public static function token(Puzzle $puzzle, int|string $index): string
    {
        return substr(hash_hmac('sha256', 'seq:'.$puzzle->getKey().':'.$index, (string) config('app.key')), 0, 16);
    }

    /** الرموز بالترتيب الصحيح (من solution_data.order). @return list<string> */
    public static function expectedOrder(Puzzle $puzzle): array
    {
        return array_values(array_map(
            fn ($index) => self::token($puzzle, $index),
            (array) ($puzzle->solution_data['order'] ?? []),
        ));
    }

    /** عناصر مخلوطة جاهزة للعميل: [['idx' => <رمز معتم>, 'label' => <نص>], ...] (اسم المفتاح idx محفوظ لواجهة Alpine القائمة). */
    public static function shuffledItems(Puzzle $puzzle): array
    {
        return collect((array) ($puzzle->game_config['items'] ?? []))
            ->map(fn ($label, $index) => ['idx' => self::token($puzzle, $index), 'label' => $label])
            ->shuffle()
            ->values()
            ->all();
    }
}
