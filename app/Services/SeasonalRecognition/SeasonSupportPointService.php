<?php

namespace App\Services\SeasonalRecognition;

use App\Models\SeasonSupportPointEntry;
use App\Models\SeasonSupporterStat;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * E44 - دفتر نقاط الدعم: **نقطة الكتابة الوحيدة** لـseason_support_point_entries ومجاميع season_supporter_stats.
 *
 * نقاط الدعم للتقدير فقط: ليست مالًا ولا جواهر ولا XP ولا نقاط تمريرة ولا درجة منافسة ولا أرباح مبدع، ولا تُصرف ولا تُحوَّل، ولا تمنح أي أفضلية لعب.
 *
 * - المنح: مصدر واحد ⇒ قيد واحد (source_key فريد + فحص تحت القفل). نقاط ≥ 1 وضمن السقف.
 * - العكس: قيد سالب واحد بالضبط (reverses_entry_id فريد) بقيمة **لقطة المنح الأصلي** لا الإعداد الحالي، ويحمل is_public الأصل.
 * - القفل: صف إحصاء (موسم، لاعب) يُنشأ كسولًا ثم يُقفل أولًا؛ أي مسار يقفل بطاقة/شراءً يقفله قبل الإحصاء (ترتيب واحد ⇒ لا جمود).
 * - بعد لقطة الترتيب النهائي للداعمين: لا منح جديد (يرجع null)؛ العكس المتأخر يعدّل المجاميع الحيّة فقط ولا يمسّ اللقطة.
 * الكاش والبثّ والإشعارات تُستدعى من المستدعي بعد commit.
 */
class SeasonSupportPointService
{
    /** إعادة محاولة المعاملة الخارجية عند جمود نادر (1213): المعاملة كلها تُلغى وتُعاد بلا أثر جانبي. تُتجاهل داخل معاملة أب. */
    public const DEADLOCK_RETRIES = 3;

    /**
     * @return SeasonSupportPointEntry|null القيد الجديد، أو null إن سبق المنح لهذا المصدر أو أُنهيت اللقطة أو النقاط غير موجبة.
     */
    public function grant(int $seasonId, int $userId, string $sourceType, int $sourceId, int $points, bool $isPublic): ?SeasonSupportPointEntry
    {
        if ($points <= 0 || $this->supportersFinalized($seasonId)) {
            return null;
        }

        // علامة داخلية (تُقارَن بالهوية لا بالنوع): الإنهاء اكتُشف بعد إنشاء/قفل صف الإحصاء ⇒ تُلغى المعاملة كلها.
        // الإرجاع الصامت كان سيُبقي صف إحصاء جديدًا بلا ختم إنهاء (مستخدم لم يكن في اللقطة) فيظهر لاحقًا كمخالفة «لقطة ناقصة».
        $finalizedMarker = new \RuntimeException('supporters_finalized');

        try {
            return DB::transaction(function () use ($seasonId, $userId, $sourceType, $sourceId, $points, $isPublic, $finalizedMarker): ?SeasonSupportPointEntry {
                $stat = $this->lockStat($seasonId, $userId);

                // يُقرأ علم الإنهاء **بعد** قفل الإحصاء وبقراءة عادية (بلا قفل على الإعدادات): المُنهي يقفل الإعدادات ثم صفوف الإحصاء، فلا دورة انتظار،
                // ومنحٌ بدأ قبل الإنهاء يُنهي كتابته أولًا (المُنهي ينتظر قفل صفّه) ومنحٌ بعده يرى العلم ويُرفض (مع التراجع عن أي صف إحصاء أنشأه للتوّ).
                if ($stat->finalized_at !== null || $this->supportersFinalized($seasonId)) {
                    throw $finalizedMarker;
                }

                if (SeasonSupportPointEntry::query()->where('source_key', SeasonSupportPointEntry::grantKey($sourceType, $sourceId))->exists()) {
                    return null;
                }

                $entry = new SeasonSupportPointEntry;
                $entry->forceFill([
                    'season_id' => $seasonId,
                    'user_id' => $userId,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'source_key' => SeasonSupportPointEntry::grantKey($sourceType, $sourceId),
                    'reason' => SeasonSupportPointEntry::REASON_GRANT,
                    'points_delta' => $points,
                    'is_public' => $isPublic,
                ])->save();

                $stat->forceFill([
                    'points_total' => (int) $stat->points_total + $points,
                    'public_points_total' => (int) $stat->public_points_total + ($isPublic ? $points : 0),
                    'first_point_at' => $stat->first_point_at ?? now(),
                    'last_point_at' => now(),
                ])->save();

                return $entry;
            }, self::DEADLOCK_RETRIES);
        } catch (UniqueConstraintViolationException) {
            return null; // منح متزامن لنفس المصدر: الفائز كتب القيد.
        } catch (\RuntimeException $e) {
            if ($e === $finalizedMarker) {
                return null; // أُنهي الترتيب أثناء المنح: لا قيد ولا صف إحصاء يتيم.
            }

            throw $e;
        }
    }

    /**
     * يعكس منح مصدر بقيد سالب واحد (متكرر الأمان). null إن لم يوجد منح أو سبق عكسه.
     */
    public function reverse(string $sourceType, int $sourceId): ?SeasonSupportPointEntry
    {
        $original = SeasonSupportPointEntry::query()->where('source_key', SeasonSupportPointEntry::grantKey($sourceType, $sourceId))->first();

        if ($original === null) {
            return null;
        }

        try {
            return DB::transaction(function () use ($original, $sourceType, $sourceId): ?SeasonSupportPointEntry {
                $stat = $this->lockStat((int) $original->season_id, (int) $original->user_id);

                if (SeasonSupportPointEntry::query()->where('reverses_entry_id', $original->id)->exists()) {
                    return null;
                }

                $points = (int) $original->points_delta;

                $entry = new SeasonSupportPointEntry;
                $entry->forceFill([
                    'season_id' => $original->season_id,
                    'user_id' => $original->user_id,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'source_key' => SeasonSupportPointEntry::reversalKey($sourceType, $sourceId),
                    'reason' => SeasonSupportPointEntry::REASON_REVERSAL,
                    'points_delta' => -$points,
                    'is_public' => (bool) $original->is_public,
                    'reverses_entry_id' => $original->id,
                ])->save();

                $stat->forceFill([
                    'points_total' => (int) $stat->points_total - $points,
                    'public_points_total' => (int) $stat->public_points_total - ($original->is_public ? $points : 0),
                    'last_point_at' => now(),
                ])->save();

                return $entry;
            }, self::DEADLOCK_RETRIES);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public function supportersFinalized(int $seasonId): bool
    {
        return DB::table('season_recognition_settings')->where('season_id', $seasonId)->value('supporters_finalized_at') !== null;
    }

    /**
     * يُنشئ صف الإحصاء كسولًا ثم يقفله (أول قفل في أي مسار يكتب نقاطًا).
     *
     * الإنشاء بـ upsert (INSERT … ON DUPLICATE KEY UPDATE) لا INSERT IGNORE: الثاني يأخذ قفلًا **مشتركًا** على الصف الموجود ثم يحاول الأول ترقيته إلى حصري بـlockForUpdate،
     * فتتجمّد عمليتان متزامنتان على المستخدم نفسه (جمود 1213 يظهر في مسبار التزامن). الـupsert يأخذ القفل الحصري مباشرةً فتتسلسل العمليات بلا ترقية.
     */
    public function lockStat(int $seasonId, int $userId): SeasonSupporterStat
    {
        DB::table('season_supporter_stats')->upsert([[
            'season_id' => $seasonId,
            'user_id' => $userId,
            'points_total' => 0,
            'public_points_total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['season_id', 'user_id'], ['updated_at']);

        return SeasonSupporterStat::query()->where('season_id', $seasonId)->where('user_id', $userId)->lockForUpdate()->firstOrFail();
    }
}
