<?php

namespace App\Listeners;

use App\Events\CompetitiveRewardGranted;
use App\Models\CompetitiveRewardGrant;
use App\Models\CompetitiveRewardRule;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;

/**
 * "حصلت على جائزة المركز الأول في {المنافسة}" مرة واحدة (competitive-reward-granted:{grant}) وفقط إن كانت الجائزة **ممنوحة فعلًا** (لا نجاح كاذب لفاشلة).
 * يحترم تفضيل competitive_enabled والمفتاح الشامل؛ المنح نفسه لا يتأثر بإيقاف الإشعارات. فشله لا يتراجع بالجائزة.
 */
class SendCompetitiveRewardGrantedNotification
{
    public function handle(CompetitiveRewardGranted $event): void
    {
        try {
            $grant = CompetitiveRewardGrant::query()->with(['event:id,title,slug', 'user', 'rule:id,kind'])->find($event->grantId);

            if ($grant === null || $grant->status !== CompetitiveRewardGrant::STATUS_GRANTED) {
                return;
            }

            $placement = $grant->rule?->kind === CompetitiveRewardRule::KIND_PARTICIPATION ? 'المشاركة' : 'المركز '.(['1' => 'الأول', '2' => 'الثاني', '3' => 'الثالث'][(string) $grant->final_rank] ?? $grant->final_rank);

            app(NotificationDispatcher::class)->dispatch(
                $grant->user,
                NotificationType::CompetitiveRewardGranted,
                ['title' => $grant->event->title, 'placement' => $placement, 'reward' => $grant->reward_label],
                "competitive-reward-granted:{$grant->id}",
                ['competitive_event_id' => $grant->competitive_event_id],
                ['event' => $grant->event->slug],
                'competitions.show',
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
