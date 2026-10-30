<?php

namespace App\Services\SeasonalRecognition;

use App\Models\Season;
use App\Models\SeasonWriterAward;
use App\Models\StoreItem;
use App\Models\User;
use App\Services\OperationalAuditService;
use App\Services\Store\InventoryService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E44 - تنفيذ قرارات جوائز الكتّاب (تُنشأ في الإنهاء من لقطة خطة الجوائز).
 *
 *  - تجميلية: تُمنح بـInventoryService::grant (الاستحقاقات القائمة) داخل معاملة تقفل صف الجائزة وتقلب حالتها planned/failed → granted في المعاملة نفسها،
 *    فالمنح والحالة ذريان معًا ولا يتضاعف المنح مهما تكرّر الاستدعاء/الاسترجاع. فشل منح عنصر لا يمسّ الترتيب المعتمد ولا بقية الجوائز (حالة failed + أثر تدقيق + قابل للإعادة).
 *  - مالية: **التزام قابل للتدقيق فقط**. لا دفع آلي، لا تحويل عملات، لا مساس برصيد المبدع/المحفظة/الأرباح، ولا قيد دفتر تلقائي.
 *    التسوية تسجيل يدوي (طريقة + مرجع) من مستخدم بصلاحية seasonal-recognition.award بعد صرف المبلغ خارج النظام؛ الإلغاء يحتاج ملاحظة.
 *  Writer Stars لا تنتج أرباحًا أبدًا؛ الجائزة المالية قرار منتج مكتوب في الخطة وحدها.
 */
class SeasonWriterAwardService
{
    public const PERMISSION = 'seasonal-recognition.award';

    public function __construct(
        protected InventoryService $inventory,
        protected OperationalAuditService $audit,
    ) {}

    /**
     * يمنح كل الجوائز التجميلية المعلّقة (planned|failed) لموسم. يُستدعى بعد commit الإنهاء، ومن إجراء «إعادة المحاولة» الإداري. معزول الفشل لكل جائزة.
     *
     * @return array{granted: int, failed: int}
     */
    public function grantPendingCosmetics(Season $season, ?User $actor = null): array
    {
        if ($actor !== null && ! $actor->can(self::PERMISSION)) {
            throw new SeasonalRecognitionException('forbidden', 'لا تملك صلاحية إدارة جوائز الكتّاب.');
        }

        $granted = 0;
        $failed = 0;

        SeasonWriterAward::query()
            ->where('season_id', $season->id)
            ->where('kind', SeasonWriterAward::KIND_COSMETIC)
            ->whereIn('status', [SeasonWriterAward::STATUS_PLANNED, SeasonWriterAward::STATUS_FAILED])
            ->orderBy('id')
            ->pluck('id')
            ->each(function ($id) use (&$granted, &$failed, $actor): void {
                $this->grantCosmetic((int) $id, $actor) ? $granted++ : $failed++;
            });

        return ['granted' => $granted, 'failed' => $failed];
    }

    /** يمنح جائزة تجميلية واحدة (متكرر الأمان). true عند نجاحها أو سبق منحها، false عند فشلها. */
    public function grantCosmetic(int $awardId, ?User $actor = null): bool
    {
        try {
            return DB::transaction(function () use ($awardId, $actor): bool {
                /** @var SeasonWriterAward|null $award */
                $award = SeasonWriterAward::query()->whereKey($awardId)->lockForUpdate()->first();

                if ($award === null || $award->kind !== SeasonWriterAward::KIND_COSMETIC) {
                    return false;
                }

                if ($award->status === SeasonWriterAward::STATUS_GRANTED) {
                    return true;
                }

                if (! in_array($award->status, [SeasonWriterAward::STATUS_PLANNED, SeasonWriterAward::STATUS_FAILED], true)) {
                    return false; // ملغاة: لا تُمنح.
                }

                $item = $award->store_item_id !== null ? StoreItem::query()->find($award->store_item_id) : null;
                $user = User::query()->find($award->user_id);

                if ($item === null || $item->item_type !== StoreItem::TYPE_COSMETIC || $item->fulfillment_type !== StoreItem::FULFILLMENT_INVENTORY || $user === null) {
                    throw new SeasonalRecognitionException('award_target_invalid', 'عنصر الجائزة أو حساب الكاتب لم يعد صالحًا.');
                }

                $this->inventory->grant($user, $item, 1, 'seasonal_writer_award:'.$award->public_id, $award);

                $award->forceFill(['status' => SeasonWriterAward::STATUS_GRANTED, 'granted_at' => now()])->save();
                $this->audit->log('seasonal_writer_award_granted', $award, ['season_id' => $award->season_id, 'placement' => $award->placement, 'store_item_id' => $award->store_item_id], $actor);

                return true;
            });
        } catch (Throwable $e) {
            report($e);
            $this->markFailed($awardId, $actor);

            return false;
        }
    }

    /**
     * تسجيل صرف التزام مالي يدويًا (بعد الدفع خارج النظام). المبلغ والعملة ثابتان كما في الخطة؛ المرجع إلزامي.
     *
     * @throws SeasonalRecognitionException
     */
    public function settleMonetary(SeasonWriterAward $award, User $actor, string $method, string $reference, ?string $note = null): SeasonWriterAward
    {
        $this->authorize($actor);

        $method = trim(strip_tags($method));
        $reference = trim(strip_tags($reference));
        $limit = max(10, (int) config('seasonal_recognition.limits.note_max', 300));

        if ($method === '' || mb_strlen($method) > 16 || $reference === '' || mb_strlen($reference) > 120) {
            throw new SeasonalRecognitionException('settlement_invalid', 'طريقة الصرف (حتى 16 حرفًا) والمرجع (حتى 120 حرفًا) مطلوبان.');
        }

        return DB::transaction(function () use ($award, $actor, $method, $reference, $note, $limit) {
            /** @var SeasonWriterAward $locked */
            $locked = SeasonWriterAward::query()->whereKey($award->id)->lockForUpdate()->firstOrFail();

            if ($locked->kind !== SeasonWriterAward::KIND_MONETARY) {
                throw new SeasonalRecognitionException('not_monetary', 'هذه جائزة تجميلية لا تُسوّى يدويًا.');
            }

            if ($locked->status === SeasonWriterAward::STATUS_SETTLED) {
                return $locked; // متكرر الأمان.
            }

            if ($locked->status !== SeasonWriterAward::STATUS_PENDING_FINANCE) {
                throw new SeasonalRecognitionException('not_pending', 'لا يمكن تسوية التزام ليس في انتظار الصرف.');
            }

            $locked->forceFill([
                'status' => SeasonWriterAward::STATUS_SETTLED,
                'settled_at' => now(),
                'settled_by' => $actor->id,
                'settlement_method' => $method,
                'settlement_ref' => $reference,
                'note' => $note !== null ? mb_substr(trim(strip_tags($note)), 0, $limit) : $locked->note,
            ])->save();

            $this->audit->log('seasonal_writer_award_settled', $locked, ['season_id' => $locked->season_id, 'amount_minor' => $locked->amount_minor, 'currency' => $locked->currency, 'method' => $method], $actor);

            return $locked;
        });
    }

    /**
     * إلغاء قرار جائزة لم تُنفَّذ (مالية بانتظار الصرف أو تجميلية معلّقة/فاشلة). الملاحظة إلزامية. لا يُلغى المنفَّذ أو المسوّى.
     *
     * @throws SeasonalRecognitionException
     */
    public function cancel(SeasonWriterAward $award, User $actor, string $note): SeasonWriterAward
    {
        $this->authorize($actor);

        $note = trim(strip_tags($note));

        if ($note === '') {
            throw new SeasonalRecognitionException('note_required', 'سبب الإلغاء مطلوب.');
        }

        return DB::transaction(function () use ($award, $actor, $note) {
            /** @var SeasonWriterAward $locked */
            $locked = SeasonWriterAward::query()->whereKey($award->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === SeasonWriterAward::STATUS_CANCELLED) {
                return $locked;
            }

            if (! in_array($locked->status, [SeasonWriterAward::STATUS_PLANNED, SeasonWriterAward::STATUS_FAILED, SeasonWriterAward::STATUS_PENDING_FINANCE], true)) {
                throw new SeasonalRecognitionException('not_cancellable', 'لا يُلغى قرار جائزة نُفِّذ أو سُوّي.');
            }

            $locked->forceFill(['status' => SeasonWriterAward::STATUS_CANCELLED, 'note' => mb_substr($note, 0, max(10, (int) config('seasonal_recognition.limits.note_max', 300)))])->save();
            $this->audit->log('seasonal_writer_award_cancelled', $locked, ['season_id' => $locked->season_id, 'kind' => $locked->kind], $actor);

            return $locked;
        });
    }

    protected function markFailed(int $awardId, ?User $actor): void
    {
        try {
            DB::transaction(function () use ($awardId, $actor): void {
                $award = SeasonWriterAward::query()->whereKey($awardId)->lockForUpdate()->first();

                if ($award !== null && $award->status === SeasonWriterAward::STATUS_PLANNED) {
                    $award->forceFill(['status' => SeasonWriterAward::STATUS_FAILED])->save();
                    $this->audit->log('seasonal_writer_award_failed', $award, ['season_id' => $award->season_id], $actor);
                }
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @throws SeasonalRecognitionException */
    protected function authorize(User $actor): void
    {
        if (! $actor->can(self::PERMISSION)) {
            throw new SeasonalRecognitionException('forbidden', 'لا تملك صلاحية إدارة جوائز الكتّاب.');
        }
    }
}
