<?php

namespace App\Services\Cinematic\Media;

use App\Models\Cinematic\CinematicMediaAsset;
use App\Support\Search\TextSearch;
use Illuminate\Database\Eloquent\Builder;

/**
 * بحث وسائط المكتبة لمحدّد الوسائط في منشئ السلاسل السينمائية (والغلاف).
 *
 * القواعد (مثبتة بالاختبار):
 *  - البحث على الخادم ضمن **كل** ملفات النوع المطلوب الجاهزة قبل تحديد العدد (لا فلترة لأحدث 200 ولا تحميل المكتبة كاملة إلى المتصفح).
 *  - النوع (role) شرط ثابت في الاستعلام: بحث الصور لا يُرجع فيديو أو صوتًا أو ترجمة أبدًا.
 *  - الملفات المسحوبة لا تُعرض كخيار جديد؛ المسحوب يظهر فقط كاختيار حالي مثبَّت (pinned) حين يكون مربوطًا فعلًا.
 *  - لا مسار تخزين ولا معرّف رقمي داخلي ولا قرص ولا المستخدم الرافع في المخرجات: الاسم والخصائص وبداية البصمة فقط.
 *  - مطابقة جزئية بلا حساسية لحالة الأحرف الإنجليزية، وبـINSTR لا LIKE (فلا تتحول % و_ في أسماء مثل PLM_S01_V01 إلى أحرف بدل).
 *
 * الصلاحية يفحصها المستدعي (صفحة المنشئ) قبل الاستدعاء؛ هذه الخدمة لا تقرّر من يحق له.
 */
final class CinematicMediaPicker
{
    /** عدد النتائج في كل طلب (الصفحة الأولى عند الفتح بلا نص، وكل «تحميل المزيد»). */
    public const PAGE_SIZE = 10;

    public const MAX_TERM = 80;

    public const MAX_TOKENS = 5;

    /** @var list<string> */
    private const COLUMNS = ['id', 'public_id', 'role', 'status', 'original_name', 'mime', 'size_bytes', 'duration_ms', 'width', 'height', 'sha256', 'created_at'];

    /**
     * @return array{items: list<array<string, mixed>>, total: int, next: int|null}
     */
    public function search(string $role, string $term = '', int $offset = 0, ?CinematicMediaAsset $pinned = null): array
    {
        if (! in_array($role, CinematicMediaAsset::ROLES, true)) {
            return ['items' => [], 'total' => 0, 'next' => null];
        }

        $offset = max(0, min($offset, 100000));
        $tokens = $this->tokens($term);

        // المثبَّت يجب أن يكون من النوع نفسه (حماية إضافية؛ المستدعي يتحقق من الربط الفعلي).
        $pinned = $pinned !== null && $pinned->role === $role ? $pinned : null;

        $base = $this->matching(CinematicMediaAsset::query()->where('role', $role)->where('status', CinematicMediaAsset::STATUS_READY), $tokens);

        if ($pinned !== null) {
            $base->where('id', '!=', $pinned->id);
        }

        $rest = (clone $base)->count();
        $rows = (clone $base)->orderByDesc('id')->offset($offset)->limit(self::PAGE_SIZE)->get(self::COLUMNS)->all();

        $showPinned = $pinned !== null && $offset === 0
            && ($tokens === [] || $this->matching(CinematicMediaAsset::query()->where('id', $pinned->id), $tokens)->exists());

        $display = $showPinned ? [$pinned, ...$rows] : $rows;
        $dups = $this->duplicateNames($role, array_map(fn (CinematicMediaAsset $a) => $a->original_name, $display));
        $items = [];

        foreach ($display as $asset) {
            $items[] = $this->describe($asset, isset($dups[$asset->original_name]), $pinned !== null && $asset->id === $pinned->id);
        }

        $consumed = $offset + count($rows);

        return [
            'items' => $items,
            'total' => $rest + ($showPinned ? 1 : 0),
            'next' => $consumed < $rest ? $consumed : null,
        ];
    }

    /** وصف ملف واحد للعرض (الاختيار الحالي أو بطاقة محدّد). */
    public function describeOne(CinematicMediaAsset $asset, bool $current = true): array
    {
        $dups = $this->duplicateNames($asset->role, [$asset->original_name]);

        return $this->describe($asset, isset($dups[$asset->original_name]), $current);
    }

    /**
     * @return array{id: string, name: string, parts: list<string>, dist: list<string>, retired: bool, current: bool, note: string|null}
     */
    public function describe(CinematicMediaAsset $asset, bool $duplicate = false, bool $current = false): array
    {
        $parts = array_values(array_filter([
            $this->size($asset->size_bytes),
            $asset->duration_ms ? (int) round($asset->duration_ms / 1000).' ث' : null,
            $asset->width && $asset->height ? $asset->width.'×'.$asset->height : null,
            $asset->mime,
        ]));

        // الأسماء المكررة: ما يميّز النسخ (تاريخ الرفع + بداية بصمة المحتوى). الأبعاد/المدة موجودة في الخصائص أعلاه.
        $dist = $duplicate
            ? array_values(array_filter([
                $asset->created_at ? 'رُفع '.$asset->created_at->copy()->timezone(config('app.timezone'))->format('Y-m-d H:i') : null,
                $asset->sha256 ? 'بصمة '.substr((string) $asset->sha256, 0, 8) : null,
            ]))
            : [];

        return [
            'id' => (string) $asset->public_id,
            'name' => (string) $asset->original_name,
            'parts' => $parts,
            'dist' => $dist,
            'retired' => $asset->status !== CinematicMediaAsset::STATUS_READY,
            'current' => $current,
            'note' => $asset->status !== CinematicMediaAsset::STATUS_READY ? 'الملف مسحوب من المكتبة: يبقى مربوطًا لكنه لا يُعرض كخيار جديد.' : null,
        ];
    }

    /**
     * يقسّم نصّ الخصائص إلى مقاطع: كل مقطع لاتيني/رقمي (أبعاد، مدة، تاريخ، نوع MIME، بصمة) يُعلَّم ltr ليُعرض داخل <bdi dir="ltr">،
     * فيبقى 1920×1080 مقروءًا داخل جملة عربية. المخرجات بيانات فقط (لا HTML)؛ القالب يطبعها بـ{{ }} المهرَّبة
     * (حارس W3 يمنع {!! !!} خارج أيقونات الألعاب).
     *
     * @return list<array{t: string, ltr: bool}>
     */
    public static function segments(string $text): array
    {
        $out = [];
        $last = 0;
        preg_match_all('/[A-Za-z0-9][A-Za-z0-9_.:×\/+\-]*/u', $text, $m, PREG_OFFSET_CAPTURE);

        foreach ($m[0] as [$token, $offset]) {
            if ($offset > $last) {
                $out[] = ['t' => substr($text, $last, $offset - $last), 'ltr' => false];
            }

            $out[] = ['t' => $token, 'ltr' => true];
            $last = $offset + strlen($token);
        }

        if ($last < strlen($text)) {
            $out[] = ['t' => substr($text, $last), 'ltr' => false];
        }

        return $out;
    }

    /** يقسّم النص إلى كلمات (AND): «PLM comparison» يطابق اسمًا يحوي الكلمتين بأي ترتيب. */
    public function tokens(string $term): array
    {
        return TextSearch::tokens($term, self::MAX_TOKENS);
    }

    /** @param list<string> $tokens */
    private function matching(Builder $query, array $tokens): Builder
    {
        return TextSearch::contains($query, 'original_name', $tokens);
    }

    /**
     * @param  list<string>  $names
     * @return array<string, true> الأسماء التي يتكرر وجودها في دور المكتبة نفسه (بأي حالة).
     */
    private function duplicateNames(string $role, array $names): array
    {
        $names = array_values(array_unique($names));

        if ($names === []) {
            return [];
        }

        return CinematicMediaAsset::query()->where('role', $role)->whereIn('original_name', $names)
            ->groupBy('original_name')->havingRaw('count(*) > 1')->pluck('original_name')
            ->mapWithKeys(fn ($n) => [(string) $n => true])->all();
    }

    private function size(?int $bytes): ?string
    {
        if ($bytes === null) {
            return null;
        }

        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' م.ب' : max(1, (int) round($bytes / 1024)).' ك.ب';
    }
}
