<?php

namespace App\Support\Search;

use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * مطابقة نصية جزئية موحّدة للقوائم المنسدلة القابلة للبحث (محدّد الوسائط، وقوائم Filament الطويلة).
 *
 * القواعد:
 *  - بحث على الخادم بشرط في الاستعلام قبل أي LIMIT (لا فلترة لقائمة مقطوعة سلفًا).
 *  - كلمات متعددة = AND بأي ترتيب («PLM comparison»).
 *  - INSTR بدل LIKE: الشرطة السفلية و% في أسماء مثل PLM_S01_V01 تُعامل حرفيًا، فلا نحتاج هروبًا يختلف بين MySQL وSQLite.
 *  - بلا حساسية لحالة الأحرف: المطابقة على lower(العمود) مع النص المصغّر، وكذلك على العمود كما هو مع النص كما كُتب
 *    (SQLite يصغّر ASCII فقط، وMySQL يصغّر يونيكود حسب collation العمود).
 *  - اسم العمود يأتي من الكود لا من المستخدم، ويُتحقق منه بنمط صارم.
 */
final class TextSearch
{
    public const MAX_TERM = 80;

    public const MAX_TOKENS = 5;

    /** @return list<string> */
    public static function tokens(?string $term, int $maxTokens = self::MAX_TOKENS): array
    {
        $term = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $term) ?? ''), 0, self::MAX_TERM);

        if ($term === '') {
            return [];
        }

        $parts = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($parts)), 0, $maxTokens);
    }

    /**
     * يضيف شرط «العمود يحوي كل الكلمات». يقبل أي عمود (أو أكثر: تكفي مطابقة أيٍّ منها لكل كلمة).
     *
     * @param  list<string>  $tokens
     * @param  string|list<string>  $columns
     */
    public static function contains(Builder|QueryBuilder|EloquentBuilderContract $query, string|array $columns, array $tokens): Builder|QueryBuilder|EloquentBuilderContract
    {
        $columns = (array) $columns;

        foreach ($columns as $column) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column)) {
                throw new InvalidArgumentException('اسم عمود بحث غير صالح.');
            }
        }

        foreach ($tokens as $token) {
            $query->where(function ($q) use ($columns, $token) {
                foreach ($columns as $column) {
                    $q->orWhereRaw("(instr(lower({$column}), ?) > 0 or instr({$column}, ?) > 0)", [mb_strtolower($token), $token]);
                }
            });
        }

        return $query;
    }
}
