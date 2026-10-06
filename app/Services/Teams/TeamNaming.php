<?php

namespace App\Services\Teams;

use App\Models\Team;
use Illuminate\Support\Str;

/**
 * قواعد اسم الفريق ومعرّفه (E19-A8/A9/E16/E18). الاسم: أحرف/أرقام/مسافات/شرطة/شرطة سفلية فقط (فلا HTML ولا رموز خطرة)، 3–40 حرفًا بعد التطبيع، والتفرّد
 * بـname_key (حروف صغيرة ومسافات مطوية). الوصف: يُجرَّد من الوسوم ويُقصّ؛ والعرض دائمًا بـ{{ }} المُهرِّب. المعرّف (slug) من الاسم، فريد، غير محجوز، وثابت بعد الإنشاء.
 */
class TeamNaming
{
    public static function normalize(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    public static function key(string $name): string
    {
        return mb_strtolower(self::normalize($name));
    }

    /** @return string|null رسالة الرفض بالعربية أو null إن كان الاسم صالحًا */
    public static function error(string $name, ?Team $ignore = null): ?string
    {
        $name = self::normalize($name);
        $len = mb_strlen($name);
        $min = (int) config('teams.name_min', 3);
        $max = (int) config('teams.name_max', 40);

        if ($len < $min || $len > $max) {
            return "اسم الفريق بين {$min} و{$max} حرفًا.";
        }

        if (! preg_match('/^[\p{L}\p{N}][\p{L}\p{M}\p{N} _\-]*$/u', $name)) {   // \p{M}: علامات التشكيل بالأسماء العربية
            return 'اسم الفريق: أحرف وأرقام ومسافات وشرطات فقط.';
        }

        $taken = Team::query()->where('name_key', self::key($name))->when($ignore, fn ($q) => $q->whereKeyNot($ignore->getKey()))->exists();

        return $taken ? 'هذا الاسم مستخدم لفريق آخر.' : null;
    }

    public static function cleanDescription(?string $text): ?string
    {
        $text = trim(strip_tags((string) $text));
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);

        return $text === '' ? null : Str::limit($text, (int) config('teams.description_max', 500), '');
    }

    /** معرّف فريد غير محجوز من الاسم (يُفرَّد بلاحقة رقمية). */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 50, '');
        $base = strlen($base) >= 3 ? $base : 'team'.($base !== '' ? '-'.$base : '');
        $reserved = (array) config('teams.reserved_slugs', []);
        $slug = $base;
        $i = 2;

        while (in_array($slug, $reserved, true) || Team::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
