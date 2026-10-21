<?php

namespace App\Services\Collections;

use App\Models\User;
use App\Models\UserCollection;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Throwable;

/**
 * E35 - إشعارات المالك بقرار الإشراف على مجموعته (E15). **نوعان فقط**: collection_hidden / collection_restored. لا إشعار على النشر/الحفظ/البلاغ/الترتيب/الرف.
 * تُستدعى بعد نجاح تغيير الحالة (بعد الـcommit) وفشلها لا يُرجع أي حالة (نمط E28/E30). مفتاح idempotency دلالي: حدث واحد = إشعار واحد.
 */
class CollectionNotifier
{
    public function __construct(protected NotificationDispatcher $dispatcher) {}

    public function hidden(UserCollection $collection): void
    {
        $this->send($collection, NotificationType::CollectionHidden, 'collection_hidden:'.$collection->id.':'.($collection->moderation_hidden_at?->timestamp ?? 0), ['note' => (string) $collection->moderation_reason]);
    }

    public function restored(UserCollection $collection): void
    {
        $this->send($collection, NotificationType::CollectionRestored, 'collection_restored:'.$collection->id.':'.now()->timestamp);
    }

    /** @param array<string, mixed> $extra */
    protected function send(UserCollection $collection, NotificationType $type, string $key, array $extra = []): void
    {
        try {
            $owner = User::query()->find($collection->user_id);

            if ($owner !== null) {
                $this->dispatcher->dispatch($owner, $type, ['title' => (string) $collection->title] + $extra, $key, [], ['collection' => (string) $collection->public_id], 'collections.show');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
