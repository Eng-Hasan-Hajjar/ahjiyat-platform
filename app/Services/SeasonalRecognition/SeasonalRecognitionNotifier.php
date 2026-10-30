<?php

namespace App\Services\SeasonalRecognition;

use App\Models\Season;
use App\Models\SeasonSupporterCard;
use App\Models\SeasonSupporterStat;
use App\Models\SeasonWriterEntry;
use App\Models\User;
use App\Services\Notifications\DispatchResult;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Throwable;

/**
 * E44 - إشعارات التقدير الموسمي. تُستدعى **بعد** نجاح الـcommit ويُعزل فشلها (try/catch + report): لا تُرجع حالة ولا تُفشل الإنهاء أو التفعيل.
 * محافظة عمدًا: لا إشعار على نجمة/نقطة/حركة ترتيب (لا بثّ جماعي)؛ فقط: ترتيب الكاتب النهائي، تفعيل بطاقة الداعم، وترتيب الداعم النهائي.
 * مفاتيح idempotency دلالية فلا إشعار مكرَّر عند إعادة الإنهاء/التفعيل. لا مبالغ ولا مراجع دفع ولا هوية ناخبين في النص. الحساب المجمَّد لا يُشعَر.
 */
class SeasonalRecognitionNotifier
{
    public function __construct(protected NotificationDispatcher $dispatcher) {}

    public function writerFinalStanding(SeasonWriterEntry $entry, Season $season): bool
    {
        if (! $this->enabled() || $entry->final_rank === null) {
            return false;
        }

        return $this->send(
            (int) $entry->user_id,
            NotificationType::WriterFinalStanding,
            'writer_final:'.$entry->id,
            ['outcome' => (string) $entry->outcome, 'rank' => (int) $entry->final_rank],
            ['season_writer_entry_id' => $entry->id],
            $season,
            'seasonal.writers.show',
        );
    }

    public function supporterCardVerified(SeasonSupporterCard $card, Season $season): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $name = (string) config('seasonal_recognition.supporters.card_tiers.'.$card->tier_key.'.name', '');

        return $this->send(
            (int) $card->user_id,
            NotificationType::SupporterCardVerified,
            'supporter_card:'.$card->id,
            ['tier' => $name],
            ['season_supporter_card_id' => $card->id],
            $season,
            'seasonal.supporters.show',
        );
    }

    public function supporterFinalRank(SeasonSupporterStat $stat, Season $season): bool
    {
        if (! $this->enabled() || $stat->final_rank === null) {
            return false;
        }

        return $this->send(
            (int) $stat->user_id,
            NotificationType::SupporterFinalRank,
            'supporter_final:'.$season->id.':'.$stat->user_id,
            ['rank' => (int) $stat->final_rank],
            ['season_id' => $season->id],
            $season,
            'seasonal.supporters.show',
        );
    }

    protected function enabled(): bool
    {
        return (bool) config('seasonal_recognition.notifications.enabled', true);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, scalar>  $refs
     */
    protected function send(int $userId, NotificationType $type, string $key, array $params, array $refs, Season $season, string $route): bool
    {
        try {
            $user = User::query()->find($userId);

            if ($user === null || $user->is_frozen) {
                return false;
            }

            return $this->dispatcher->dispatch($user, $type, $params, $key, $refs, ['season' => $season->slug], $route) === DispatchResult::Created;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
