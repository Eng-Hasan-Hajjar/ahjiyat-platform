<?php

namespace App\Services\Editorial;

use App\Models\EditorialShelf;
use App\Models\User;
use App\Support\CachedSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * E35 - قراءة الأرفف التحريرية لموضع (discover | home). تُدرَج في الصفحات القائمة دون إعادة كتابتها: DiscoveryService والرئيسية يستدعيان forPlacement فقط.
 *
 *  - الكاش: **تعريفات الرفوف الفعّالة فقط** (عناوين + مراجع type/id، لا بيانات مشاهد) لمدة قصيرة؛ يُفرَّغ فور أي تعديل إداري. نافذة الجدولة (starts_at/ends_at) تُقيَّم **وقت القراءة**.
 *  - الأهلية حيّة بكل طلب: المراجع تُحلّ بدفعة لكل نوع عبر EditorialShelfResolver (المخفي/المؤرشف/المعلَّق/المنتهي/المحظور على المشاهد يسقط فورًا ولو بقي بالتعريف المخزَّن).
 *  - الرف بلا بطاقات قابلة للعرض **يُحذف** (لا قسم فارغ). الترتيب: sort_order ثم id، وداخل الرف بحسب position.
 *  - عزل الفشل: أي استثناء هنا يُسجَّل ويُرجع [] فلا تتعطل الاكتشاف ولا الرئيسية.
 */
class EditorialShelves
{
    public function __construct(protected EditorialShelfResolver $resolver) {}

    /** @return list<array{key: string, title: string, subtitle: ?string, cards: list<\App\Services\Discovery\DiscoverCard>}> */
    public function forPlacement(string $placement, ?User $viewer): array
    {
        if (! array_key_exists($placement, EditorialShelf::PLACEMENTS)) {
            return [];
        }

        try {
            $now = now();
            $live = array_values(array_filter($this->definitions($placement), fn (array $d) => ($d['starts_at'] === null || Carbon::parse($d['starts_at'])->lte($now))
                && ($d['ends_at'] === null || Carbon::parse($d['ends_at'])->gt($now))));

            if ($live === []) {
                return [];
            }

            $refs = [];

            foreach ($live as $shelf) {
                foreach ($shelf['items'] as $item) {
                    $refs[] = [$item[0], $item[1]];
                }
            }

            $cards = $this->resolver->resolve($refs, $viewer);
            $out = [];

            foreach ($live as $shelf) {
                $shelfCards = [];

                foreach ($shelf['items'] as $item) {
                    if (isset($cards[$item[0].':'.$item[1]])) {
                        $shelfCards[] = $cards[$item[0].':'.$item[1]];
                    }
                }

                $shelfCards = array_slice($shelfCards, 0, max(1, (int) $shelf['max_items']));

                if ($shelfCards !== []) {
                    $out[] = ['key' => $shelf['public_id'], 'title' => $shelf['title'], 'subtitle' => $shelf['subtitle'], 'cards' => $shelfCards];
                }
            }

            return $out;
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    public static function key(string $placement): string
    {
        return 'editorial.shelves.'.$placement;
    }

    public static function flush(): void
    {
        try {
            foreach (array_keys(EditorialShelf::PLACEMENTS) as $placement) {
                Cache::forget(self::key($placement));
            }
        } catch (Throwable) {
            // الكاش اختياري.
        }
    }

    /** @return list<array<string, mixed>> */
    protected function definitions(string $placement): array
    {
        return CachedSnapshot::remember(self::key($placement), (int) config('collections.shelves.cache_seconds'), function () use ($placement) {
            return EditorialShelf::query()->where('placement', $placement)->where('status', EditorialShelf::STATUS_ACTIVE)
                ->with('items:id,editorial_shelf_id,content_type,content_id,position')
                ->orderBy('sort_order')->orderBy('id')->limit(max(1, (int) config('collections.shelves.max_per_placement')))->get()
                ->map(fn (EditorialShelf $s) => [
                    'public_id' => (string) $s->public_id,
                    'title' => (string) $s->title,
                    'subtitle' => $s->subtitle,
                    'starts_at' => $s->starts_at?->toIso8601String(),
                    'ends_at' => $s->ends_at?->toIso8601String(),
                    'max_items' => (int) $s->max_items,
                    'items' => $s->items->map(fn ($i) => [(string) $i->content_type, (int) $i->content_id])->all(),
                ])->all();
        });
    }
}
