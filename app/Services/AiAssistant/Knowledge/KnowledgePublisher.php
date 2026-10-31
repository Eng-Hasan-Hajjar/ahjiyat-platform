<?php

namespace App\Services\AiAssistant\Knowledge;

use App\Models\AiKnowledgeArticle;
use App\Models\AiKnowledgeChunk;
use App\Models\User;
use App\Services\AiAssistant\Safety\AiLinkPolicy;
use Illuminate\Support\Facades\DB;

/**
 * E45 - سير نشر المعرفة: مسودة ⇒ (تحقق + فحص أمان) ⇒ منشور (تطبيع + تقطيع + فهرسة + إبطال الكاش) ⇒ مسحوب. الكتابة الوحيدة لحالة المقال/نسخته/بصمته ومقاطعه.
 *
 *  - لا يصل للنموذج إلا المنشور. تعديل مقال منشور يعيده **مسودة** تلقائيًا (markEdited) ويحذف مقاطعه فلا يُخدَم محتوى لم يُراجَع ولا مقطع متقادم؛ ثم يُنشر صراحةً من جديد.
 *  - النسخة تزداد حين تتغير بصمة المحتوى عن آخر نشر. كل مقطع يحمل article_version؛ الفهرس يتجاهل أي مقطع لا يطابق نسخة المقال.
 *  - الفحص (KnowledgeNormalizer::scan) يتكرر عند كل نشر وإعادة بناء: وثيقة تسربت إليها سرية لاحقًا تُسحب من الخدمة ولا تُفهرَس.
 *  - لا مزوّد خارجي ولا شبكة هنا: الفهرسة معجمية محلية.
 */
final class KnowledgePublisher
{
    public function __construct(protected KnowledgeChunker $chunker, protected KnowledgeIndex $index) {}

    public function contentHash(AiKnowledgeArticle $article): string
    {
        return hash('sha256', (string) json_encode([
            $article->title, $article->summary, $article->body, $article->tags, $article->audience, $article->area, $article->canonical_path, $article->source_label, $article->locale,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** @throws KnowledgeSafetyException */
    public function validate(AiKnowledgeArticle $article): void
    {
        $problems = [];
        $max = max(1000, (int) config('tatawwur_ai.knowledge.max_body_chars', 12000));

        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $article->slug) !== 1 || strlen((string) $article->slug) > 120) {
            $problems[] = 'invalid_slug';
        }

        if ($article->locale !== 'ar') {
            $problems[] = 'unsupported_locale';
        }

        if (mb_strlen(trim((string) $article->title)) < 3 || mb_strlen((string) $article->title) > 160) {
            $problems[] = 'invalid_title';
        }

        if (mb_strlen(trim((string) $article->summary)) < 10 || mb_strlen((string) $article->summary) > 400) {
            $problems[] = 'invalid_summary';
        }

        $body = KnowledgeNormalizer::normalize((string) $article->body);

        if (mb_strlen($body) < 40 || mb_strlen($body) > $max) {
            $problems[] = 'invalid_body_length';
        }

        if (! in_array($article->audience, AiKnowledgeArticle::AUDIENCES, true)) {
            $problems[] = 'invalid_audience';
        }

        if (! array_key_exists((string) $article->area, AiKnowledgeArticle::AREAS)) {
            $problems[] = 'invalid_area';
        }

        if ($article->canonical_path !== null && $article->canonical_path !== '' && ! AiLinkPolicy::isInternalPath($article->canonical_path)) {
            $problems[] = 'invalid_canonical_path';
        }

        if (trim((string) $article->source_label) === '' || mb_strlen((string) $article->source_label) > 60) {
            $problems[] = 'invalid_source_label';
        }

        foreach (KnowledgeNormalizer::scan((string) $article->title, (string) $article->summary, (string) $article->body, (string) $article->tags, (string) $article->source_label) as $violation) {
            $problems[] = 'unsafe:'.$violation;
        }

        if ($problems !== []) {
            throw new KnowledgeSafetyException('المقال لا يصلح للنشر: '.implode('، ', $problems), $problems);
        }
    }

    /** @throws KnowledgeSafetyException */
    public function publish(AiKnowledgeArticle $article, ?User $actor = null): AiKnowledgeArticle
    {
        $published = DB::transaction(function () use ($article, $actor): AiKnowledgeArticle {
            /** @var AiKnowledgeArticle $locked */
            $locked = AiKnowledgeArticle::query()->whereKey($article->getKey())->lockForUpdate()->firstOrFail();

            $this->validate($locked);

            $hash = $this->contentHash($locked);
            $changed = $locked->content_hash !== null && $locked->content_hash !== $hash;
            $version = max(1, (int) $locked->version) + ($changed ? 1 : 0);

            $this->writeChunks($locked, $version);

            $locked->forceFill([
                'status' => AiKnowledgeArticle::STATUS_PUBLISHED,
                'version' => $version,
                'content_hash' => $hash,
                'published_at' => $locked->published_at ?? now(),
                'retired_at' => null,
                'reviewed_by' => $actor?->getKey() ?? $locked->reviewed_by,
            ])->save();

            return $locked;
        });

        $this->index->bust();

        return $published;
    }

    public function retire(AiKnowledgeArticle $article, ?User $actor = null): AiKnowledgeArticle
    {
        DB::transaction(function () use ($article, $actor): void {
            AiKnowledgeChunk::query()->where('article_id', $article->getKey())->delete();

            $article->forceFill([
                'status' => AiKnowledgeArticle::STATUS_RETIRED,
                'retired_at' => now(),
                'reviewed_by' => $actor?->getKey() ?? $article->reviewed_by,
            ])->save();
        });

        $this->index->bust();

        return $article->refresh();
    }

    /** يُستدعى بعد حفظ تعديل تحريري: مقال منشور تغيّر محتواه يعود مسودة (لا يُخدَم محتوى غير مراجَع). */
    public function markEdited(AiKnowledgeArticle $article): void
    {
        if ($article->status !== AiKnowledgeArticle::STATUS_PUBLISHED || $article->content_hash === $this->contentHash($article)) {
            return;
        }

        DB::transaction(function () use ($article): void {
            AiKnowledgeChunk::query()->where('article_id', $article->getKey())->delete();
            $article->forceFill(['status' => AiKnowledgeArticle::STATUS_DRAFT])->save();
        });

        $this->index->bust();
    }

    /**
     * إعادة بناء مقاطع **المقالات المنشورة** فقط (أمر ai:knowledge:rebuild). مقال لم يعد يجتاز الفحص أو تغيّر محتواه عن آخر اعتماد يُسحب إلى مسودة ولا يُفهرَس.
     *
     * @return array{articles: int, chunks: int, blocked: list<string>, reverted: list<string>}
     */
    public function rebuild(): array
    {
        $articles = 0;
        $chunks = 0;
        $blocked = [];
        $reverted = [];

        AiKnowledgeArticle::query()->published()->orderBy('id')->get()->each(function (AiKnowledgeArticle $article) use (&$articles, &$chunks, &$blocked, &$reverted): void {
            if ($article->content_hash !== $this->contentHash($article)) {
                $this->markEdited($article);
                $reverted[] = $article->slug;

                return;
            }

            try {
                $this->validate($article);
            } catch (KnowledgeSafetyException) {
                AiKnowledgeChunk::query()->where('article_id', $article->getKey())->delete();
                $article->forceFill(['status' => AiKnowledgeArticle::STATUS_DRAFT])->save();
                $blocked[] = $article->slug;

                return;
            }

            DB::transaction(function () use ($article, &$chunks): void {
                $chunks += $this->writeChunks($article, (int) $article->version);
            });

            $articles++;
        });

        $this->index->bust();

        return ['articles' => $articles, 'chunks' => $chunks, 'blocked' => $blocked, 'reverted' => $reverted];
    }

    /** يستبدل مقاطع المقال بمقاطع النسخة المعطاة. @return int عدد المقاطع المكتوبة */
    protected function writeChunks(AiKnowledgeArticle $article, int $version): int
    {
        AiKnowledgeChunk::query()->where('article_id', $article->getKey())->delete();

        $url = AiLinkPolicy::isInternalPath($article->canonical_path) ? (string) $article->canonical_path : $article->publicPath();
        $rows = [];

        foreach ($this->chunker->chunk((string) $article->title, (string) $article->summary, (string) $article->body) as $i => $chunk) {
            $rows[] = [
                'article_id' => $article->getKey(),
                'article_version' => $version,
                'position' => $i + 1,
                'section' => $chunk['section'],
                'body' => $chunk['body'],
                'url' => $url,
                'source_label' => mb_substr((string) $article->source_label, 0, 60),
                'content_hash' => $chunk['hash'],
                'char_count' => min(65000, mb_strlen($chunk['body'])),
                'created_at' => now(),
            ];
        }

        if ($rows !== []) {
            AiKnowledgeChunk::query()->insert($rows);
        }

        return count($rows);
    }
}
