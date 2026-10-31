<?php

namespace App\Console\Commands\Ai;

use App\Services\AiAssistant\Conversation\ConversationService;
use Illuminate\Console\Command;

/** E45 - الاحتفاظ بالبيانات: يحذف محادثات المساعد المنتهية (افتراضيًا 30 يومًا من آخر رسالة)، والمقاييس والتقييمات الأقدم من مدة احتفاظها. يعمل حتى لو أُوقف المساعد (حق الحذف لا يتوقف على تفعيل الميزة). */
class PruneConversations extends Command
{
    protected $signature = 'ai:conversations:prune';

    protected $description = 'E45: يحذف محادثات «تطوّر AI» المنتهية والمقاييس والتقييمات القديمة وفق مدد الاحتفاظ.';

    public function handle(ConversationService $conversations): int
    {
        $result = $conversations->prune();

        $this->info("حُذف {$result['conversations']} محادثة و{$result['metrics']} سجل مقاييس و{$result['feedback']} تقييمًا منتهي الاحتفاظ.");

        return self::SUCCESS;
    }
}
