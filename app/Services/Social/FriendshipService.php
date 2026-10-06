<?php

namespace App\Services\Social;

use App\Events\FriendRequestCreated;
use App\Events\FriendshipAccepted;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * نطاق الصداقة. كل إجراء يأخذ (المستخدم الحالي، الطرف الآخر) - لا معرّف علاقة قادم من الطلب، فلا IDOR ممكن بنيويًا: المستخدم
 * لا يستطيع التأثير إلا على علاقته هو بالطرف الآخر.
 *
 * - علاقة واحدة لكل زوج (pair_key UNIQUE بقاعدة البيانات). سباق A→B وB→A: يفوز أحدهما بالإدخال، والآخر يلتقط الاستثناء
 *   ويُحلّ بالسياسة نفسها: إرسال طلب لمن أرسل لك طلبًا = قبول طلبه (علاقة واحدة accepted).
 * - القبول للمرسَل إليه فقط، ذري (UPDATE ... WHERE status='pending')، وIdempotent: لا حدث ولا سجل ثانٍ عند الإعادة.
 * - الأحداث (FriendRequestCreated / FriendshipAccepted) بعد commit فقط، وفشلها لا يمسّ العلاقة.
 * - الرفض/الإلغاء/الإزالة تحذف الصف بلا إشعار؛ الرفض والإلغاء يضعان cooldown بسيطًا لإعادة الإرسال (config/friends.php).
 * - الصداقة ليست مصدر مكافأة: لا XP ولا عملة ولا مهام ولا سلسلة ولا إنجاز - لا اعتماد على أي منها هنا.
 */
class FriendshipService
{
    public function __construct(protected BlockService $blocks) {}

    // ---------------------------------------------------------------- القراءة

    /** هل يجوز لـ$from إرسال طلب جديد إلى $to؟ (لا يكشف السبب للمستدعي) */
    public function canSendTo(User $from, User $to): bool
    {
        return ! $from->is($to)
            && $to->is_frozen !== true
            && $to->friend_requests_enabled !== false
            && $to->profileViewableBy($from)   // لا نستهدف من لا يحق لنا رؤية ملفه (الاكتشاف يتبع سياسة الخصوصية الحالية)
            && ! $this->blocks->blockedEitherWay($from, $to);
    }

    public function relationBetween(User $viewer, User $other): FriendRelation
    {
        if ($viewer->is($other)) {
            return FriendRelation::Self;
        }

        if ($this->blocks->hasBlocked($viewer, $other)) {
            return FriendRelation::BlockedByMe;
        }

        if ($this->blocks->hasBlocked($other, $viewer)) {
            return FriendRelation::Unavailable; // لا نكشف أنه حظرنا
        }

        $row = Friendship::query()->forPair($viewer->getKey(), $other->getKey())->first();

        if ($row !== null) {
            return $this->relationFromRow($row, $viewer);
        }

        return $this->canSendTo($viewer, $other) ? FriendRelation::None : FriendRelation::Unavailable;
    }

    /**
     * حالة العلاقة لمجموعة لاعبين بدفعة استعلامات (للبحث).
     *
     * @param  Collection<int, User>  $others
     * @return array<int, FriendRelation> user_id => relation
     */
    public function relationsFor(User $viewer, Collection $others): array
    {
        $ids = $others->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $rows = Friendship::query()->where(function ($q) use ($viewer, $ids) {
            $q->where(fn ($w) => $w->where('requester_id', $viewer->getKey())->whereIn('addressee_id', $ids))
                ->orWhere(fn ($w) => $w->where('addressee_id', $viewer->getKey())->whereIn('requester_id', $ids));
        })->get()->keyBy(fn (Friendship $f) => $f->requester_id === $viewer->getKey() ? $f->addressee_id : $f->requester_id);

        $blocked = $this->blocks->blockedAmong($viewer, $ids);
        $out = [];

        foreach ($others as $other) {
            $out[$other->id] = match (true) {
                $viewer->is($other) => FriendRelation::Self,
                isset($blocked['by_me'][$other->id]) => FriendRelation::BlockedByMe,
                isset($blocked['me'][$other->id]) => FriendRelation::Unavailable,
                $rows->has($other->id) => $this->relationFromRow($rows->get($other->id), $viewer),
                $other->is_frozen !== true && $other->friend_requests_enabled !== false && $other->profileViewableBy($viewer) => FriendRelation::None,
                default => FriendRelation::Unavailable,
            };
        }

        return $out;
    }

    /** @return list<int> */
    public function friendIds(User $user): array
    {
        $id = $user->getKey();

        return Friendship::query()->accepted()->involving($id)->get(['requester_id', 'addressee_id'])
            ->map(fn (Friendship $f) => $f->requester_id === $id ? $f->addressee_id : $f->requester_id)
            ->values()->all();
    }

    public function friendsCount(User $user): int
    {
        return Friendship::query()->accepted()->involving($user->getKey())->count();
    }

    /** استعلام لاعبي الأصدقاء المقبولين فقط (حقول آمنة)، للترقيم. */
    public function friendsQuery(User $user): Builder
    {
        $id = $user->getKey();

        return User::query()
            ->select('users.id', 'users.name', 'users.public_id', 'users.profile_visibility')
            ->where(function ($q) use ($id) {
                $q->whereIn('users.id', Friendship::query()->accepted()->where('requester_id', $id)->select('addressee_id'))
                    ->orWhereIn('users.id', Friendship::query()->accepted()->where('addressee_id', $id)->select('requester_id'));
            })
            ->orderBy('users.name')->orderBy('users.id');
    }

    /** @return Collection<int, Friendship> الطلبات الواردة (الطرف الآخر = requester) */
    public function incoming(User $user): Collection
    {
        return Friendship::query()->pending()->where('addressee_id', $user->getKey())
            ->with('requester:id,name,public_id,profile_visibility')->latest('id')->limit((int) config('friends.requests_limit', 50))->get();
    }

    /** @return Collection<int, Friendship> الطلبات الصادرة (الطرف الآخر = addressee) */
    public function outgoing(User $user): Collection
    {
        return Friendship::query()->pending()->where('requester_id', $user->getKey())
            ->with('addressee:id,name,public_id,profile_visibility')->latest('id')->limit((int) config('friends.requests_limit', 50))->get();
    }

    // ---------------------------------------------------------------- الإجراءات

    public function sendRequest(User $from, User $to): FriendActionResult
    {
        if ($from->is($to)) {
            return FriendActionResult::Self;
        }

        // قد توجد علاقة قائمة (صديق/طلب): نحلّها قبل أي رفض بسبب الإعدادات، فلا نمنع تأكيد ما هو موجود.
        if (! $this->canSendTo($from, $to) && Friendship::query()->forPair($from->getKey(), $to->getKey())->doesntExist()) {
            return FriendActionResult::Unavailable;
        }

        if ($this->blocks->blockedEitherWay($from, $to)) {
            return FriendActionResult::Unavailable;
        }

        if (Cache::has($this->cooldownKey($from->getKey(), $to->getKey()))) {
            return FriendActionResult::Cooldown;
        }

        try {
            return DB::transaction(function () use ($from, $to) {
                $existing = $this->lockedPair($from, $to);

                if ($existing !== null) {
                    return $this->resolveExisting($existing, $from);
                }

                $friendship = Friendship::create([
                    'requester_id' => $from->getKey(),
                    'addressee_id' => $to->getKey(),
                    'status' => Friendship::STATUS_PENDING,
                ]);

                $this->afterCommit(fn () => event(new FriendRequestCreated($friendship->getKey())));

                return FriendActionResult::Created;
            });
        } catch (UniqueConstraintViolationException) {
            // سباق: سبقتنا عملية بنفس الزوج (ربما الاتجاه المعاكس). نقرأ الناتج ونحلّ بالسياسة نفسها.
            $existing = Friendship::query()->forPair($from->getKey(), $to->getKey())->first();

            return $existing === null
                ? FriendActionResult::Unavailable
                : DB::transaction(fn () => $this->resolveExisting($existing, $from));
        }
    }

    public function accept(User $actor, User $requester): FriendActionResult
    {
        if ($actor->is($requester)) {
            return FriendActionResult::Self;
        }

        $row = Friendship::query()->forPair($actor->getKey(), $requester->getKey())->first();

        if ($row === null) {
            return FriendActionResult::NotFound;
        }

        if ($row->status === Friendship::STATUS_ACCEPTED) {
            return FriendActionResult::AlreadyFriends; // إعادة الطلب: Idempotent
        }

        if ($row->addressee_id !== $actor->getKey()) {
            return FriendActionResult::NotAllowed; // أنا المرسِل: لا أقبل طلبي
        }

        if ($this->blocks->blockedEitherWay($actor, $requester)) {
            return FriendActionResult::Unavailable;
        }

        if (DB::transaction(fn () => $this->acceptRow($row, $actor))) {
            return FriendActionResult::Accepted;
        }

        // خسرنا سباقًا: قُبل بالفعل (نجاح Idempotent) أو حُذف.
        $now = Friendship::query()->forPair($actor->getKey(), $requester->getKey())->first();

        return $now?->status === Friendship::STATUS_ACCEPTED ? FriendActionResult::AlreadyFriends : FriendActionResult::NotFound;
    }

    public function decline(User $actor, User $requester): FriendActionResult
    {
        $deleted = Friendship::query()->pending()->forPair($actor->getKey(), $requester->getKey())
            ->where('addressee_id', $actor->getKey())->delete();

        if ($deleted === 0) {
            return FriendActionResult::NotFound;
        }

        $this->startCooldown($requester->getKey(), $actor->getKey()); // المرسِل الأصلي لا يعيد الإرسال فورًا

        return FriendActionResult::Declined; // لا إشعار
    }

    public function cancel(User $actor, User $addressee): FriendActionResult
    {
        $deleted = Friendship::query()->pending()->forPair($actor->getKey(), $addressee->getKey())
            ->where('requester_id', $actor->getKey())->delete();

        if ($deleted === 0) {
            return FriendActionResult::NotFound;
        }

        $this->startCooldown($actor->getKey(), $addressee->getKey());

        return FriendActionResult::Cancelled;
    }

    public function remove(User $actor, User $other): FriendActionResult
    {
        $deleted = Friendship::query()->accepted()->forPair($actor->getKey(), $other->getKey())->delete();

        if ($deleted > 0) {
            \App\Models\FriendChallenge::cancelActiveBetween($actor->getKey(), $other->getKey()); // E17: لم يعودا صديقين: لا تحدٍّ نشط
        }

        return $deleted > 0 ? FriendActionResult::Removed : FriendActionResult::NotFound; // لا إشعار، ولا مساس بأي تقدّم
    }

    // ---------------------------------------------------------------- داخلي

    /** قراءة العلاقة القائمة داخل معاملة الإرسال (مع قفل حيث تدعمه القاعدة). معزولة لتُحاكى بها فجوة السباق في الاختبار. */
    protected function lockedPair(User $a, User $b): ?Friendship
    {
        return Friendship::query()->forPair($a->getKey(), $b->getKey())->lockForUpdate()->first();
    }

    protected function relationFromRow(Friendship $row, User $viewer): FriendRelation
    {
        if ($row->status === Friendship::STATUS_ACCEPTED) {
            return FriendRelation::Friends;
        }

        return $row->requester_id === $viewer->getKey() ? FriendRelation::OutgoingPending : FriendRelation::IncomingPending;
    }

    protected function resolveExisting(Friendship $existing, User $from): FriendActionResult
    {
        if ($existing->status === Friendship::STATUS_ACCEPTED) {
            return FriendActionResult::AlreadyFriends;
        }

        if ($existing->requester_id === $from->getKey()) {
            return FriendActionResult::AlreadyPending; // طلب مكرر
        }

        // الطرف الآخر أرسل لنا طلبًا قبل لحظة: إرسالنا يعني قبوله (علاقة واحدة بدل اثنتين).
        return $this->acceptRow($existing, $from) ? FriendActionResult::AcceptedExisting : FriendActionResult::AlreadyFriends;
    }

    /** تحديث ذري: ينجح مرة واحدة فقط ولـ$actor المرسَل إليه فقط. true = هذا الاستدعاء هو الذي قبل. */
    protected function acceptRow(Friendship $row, User $actor): bool
    {
        $affected = Friendship::query()->whereKey($row->getKey())
            ->where('status', Friendship::STATUS_PENDING)->where('addressee_id', $actor->getKey())
            ->update(['status' => Friendship::STATUS_ACCEPTED, 'accepted_at' => now()]);

        if ($affected !== 1) {
            return false;
        }

        $this->afterCommit(fn () => event(new FriendshipAccepted($row->getKey())));

        return true;
    }

    protected function afterCommit(\Closure $callback): void
    {
        DB::afterCommit(function () use ($callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                report($e); // الإشعارات ثانوية: فشلها لا يمسّ العلاقة.
            }
        });
    }

    protected function cooldownKey(int $from, int $to): string
    {
        return "friends:cooldown:{$from}:{$to}";
    }

    protected function startCooldown(int $from, int $to): void
    {
        Cache::put($this->cooldownKey($from, $to), true, now()->addMinutes((int) config('friends.resend_cooldown_minutes', 60)));
    }
}
