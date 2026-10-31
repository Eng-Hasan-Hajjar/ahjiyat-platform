<?php

namespace App\Console\Commands\Ai;

use App\Services\AiAssistant\Knowledge\KnowledgePublisher;
use Illuminate\Console\Command;

/** E45 - يعيد بناء مقاطع المقالات **المنشورة** فقط ويبطل كاش الفهرس. مقال تغيّر محتواه عن آخر اعتماد أو لم يعد يجتاز فحص الأمان يُسحب مسودة ولا يُفهرَس. محلي بلا شبكة ولا مزوّد. */
class RebuildKnowledge extends Command
{
    protected $signature = 'ai:knowledge:rebuild';

    protected $description = 'E45: يعيد فهرسة مقالات المعرفة المنشورة (محلي، بلا مزوّد ذكاء خارجي).';

    public function handle(KnowledgePublisher $publisher): int
    {
        $result = $publisher->rebuild();

        $this->info("أُعيد بناء {$result['articles']} مقالًا و{$result['chunks']} مقطعًا.");

        foreach ($result['reverted'] as $slug) {
            $this->warn("عاد مسودة (تغيّر محتواه بعد آخر اعتماد): {$slug}");
        }

        foreach ($result['blocked'] as $slug) {
            $this->error("سُحب من الخدمة (لم يعد يجتاز فحص الأمان): {$slug}");
        }

        return $result['blocked'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
