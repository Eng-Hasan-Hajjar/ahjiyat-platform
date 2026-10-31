<?php

namespace App\Services\AiAssistant\Knowledge;

use App\Models\AiKnowledgeArticle;
use App\Models\AiKnowledgeChunk;
use App\Services\Search\TextFold;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * E45 - فهرس الاسترجاع المحلي: مقاطع **المقالات المنشورة** (نسختها الحالية) فقط، مطوية بـTextFold ومخزَّنة مؤقتًا بحسب الجمهور.
 * الإبطال: كل نشر/سحب/إعادة بناء يغيّر «حقبة» الكاش (bust) فلا يُخدَم أي مقطع متقادم. فشل الكاش لا يعطّل الاسترجاع (يُبنى مباشرة من القاعدة).
 * لا بيانات شخصية هنا ولا مخرجات أدوات: الفهرس عام للجمهور (public أو public+member) وآمن للمشاركة بين المستخدمين.
 */
final class KnowledgeIndex
{
    private const EPOCH_KEY = 'tatawwur:kb:epoch';

    /**
     * @return list<array{chunk_id: int, article_id: int, slug: string, title: string, area: string, section: ?string, text: string, url: ?string, label: string, f_title: string, f_tags: string, f_section: string, f_body: string}>
     */
    public function entries(bool $member): array
    {
        $key = 'tatawwur:kb:'.$this->epoch().':'.($member ? 'member' : 'public');
        $ttl = max(30, (int) config('tatawwur_ai.retrieval.cache_ttl_seconds', 600));

        try {
            return Cache::remember($key, $ttl, fn (): array => $this->build($member));
        } catch (Throwable) {
            return $this->build($member);
        }
    }

    /** يُبطل كل نسخ الفهرس المخزَّنة (ينفَّذ عند نشر/سحب/إعادة بناء). */
    public function bust(): void
    {
        try {
            Cache::forever(self::EPOCH_KEY, (string) Str::ulid());
        } catch (Throwable) {
            // بلا كاش لا شيء يُبطَل.
        }
    }

    private function epoch(): string
    {
        try {
            return (string) Cache::get(self::EPOCH_KEY, 'init');
        } catch (Throwable) {
            return 'init';
        }
    }

    /** @return list<array<string, mixed>> */
    private function build(bool $member): array
    {
        $articles = AiKnowledgeArticle::query()->published()->forAudience($member)->where('locale', 'ar')
            ->get(['id', 'slug', 'title', 'area', 'tags', 'version', 'source_label', 'canonical_path'])->keyBy('id');

        if ($articles->isEmpty()) {
            return [];
        }

        $entries = [];

        AiKnowledgeChunk::query()->whereIn('article_id', $articles->keys()->all())->orderBy('article_id')->orderBy('position')
            ->get(['id', 'article_id', 'article_version', 'section', 'body', 'url', 'source_label'])
            ->each(function (AiKnowledgeChunk $chunk) use ($articles, &$entries): void {
                $article = $articles[$chunk->article_id] ?? null;

                // مقطع نسخته غير نسخة المقال الحالية = متقادم: لا يُخدَم أبدًا.
                if ($article === null || (int) $chunk->article_version !== (int) $article->version) {
                    return;
                }

                $entries[] = [
                    'chunk_id' => (int) $chunk->id,
                    'article_id' => (int) $article->id,
                    'slug' => (string) $article->slug,
                    'title' => (string) $article->title,
                    'area' => (string) $article->area,
                    'section' => $chunk->section,
                    'text' => (string) $chunk->body,
                    'url' => $chunk->url,
                    'label' => (string) $chunk->source_label,
                    'f_title' => TextFold::fold((string) $article->title),
                    'f_tags' => TextFold::fold((string) $article->tags),
                    'f_section' => TextFold::fold((string) $chunk->section),
                    'f_body' => TextFold::fold((string) $chunk->body),
                ];
            });

        return $entries;
    }
}
