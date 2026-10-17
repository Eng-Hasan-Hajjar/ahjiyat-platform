<?php

namespace App\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * E31: MySQL وMariaDB يرفضان أي اسم فهرس أو مفتاح أجنبي أطول من 64 محرفًا (الخطأ 1059)، بينما SQLite لا يحدّ الطول.
 * بعض الأسماء التي يولّدها Laravel تلقائيًا من اسم الجدول والأعمدة تتجاوز 64 (مثل competitive_event_results_competitive_event_id_score_duration_ms_index = 70)،
 * فكان الترحيل من الصفر على MySQL سيفشل في منتصف الطريق.
 *
 * الحل هنا بلا لمس أي ترحيل قديم (الترحيلات القديمة غير قابلة للتعديل): هذا الصنف يُربط بدل Blueprint في الحاوية (انظر AppServiceProvider)،
 * ولا يغيّر شيئًا إلا حين يكون السائق mysql/mariadb والاسم المولَّد أطول من 64: عندها يُقتطع إلى 55 محرفًا ويُلحق به "_" وبصمة sha1 من 8 خانات
 * (الناتج 64 تمامًا). الاقتطاع حتمي، فيعطي dropIndex(['a','b']) نفس الاسم الذي أنشأه الترحيل.
 * على SQLite وبقية السائقين لا تغيير إطلاقًا (الأسماء كما كانت، وقواعد البيانات الحالية لا تتأثر).
 */
class MysqlSafeBlueprint extends Blueprint
{
    public const MAX_IDENTIFIER_LENGTH = 64;

    /** @var list<string> */
    private const LIMITED_DRIVERS = ['mysql', 'mariadb'];

    protected function createIndexName($type, array $columns)
    {
        return self::fit(parent::createIndexName($type, $columns), (string) $this->connection->getDriverName());
    }

    /** يعيد الاسم كما هو إن كان صالحًا للسائق، وإلا نسخته المقتطعة الحتمية. */
    public static function fit(string $name, string $driver): string
    {
        if (! in_array($driver, self::LIMITED_DRIVERS, true) || strlen($name) <= self::MAX_IDENTIFIER_LENGTH) {
            return $name;
        }

        return substr($name, 0, 55).'_'.substr(sha1($name), 0, 8);
    }
}
