<?php

namespace App\Services\Creators;

use App\Models\CreatorProfile;
use App\Models\User;
use App\Services\Database\TransactionPolicy;
use App\Services\OperationalAuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E28 - دورة حياة ملف المبدع: تقديم → اعتماد/رفض، ثم تعليق/استعادة. كل انتقال: قفل الصف + فحص الحالة المتوقَّعة (لا انتقال مزدوج بتزامن) + تدقيق + مصالحة ظهور المنشورات.
 * الصلاحيات من RBAC الحالي (creators.review: اعتماد/رفض، creators.manage: تعليق/استعادة) وتُفحص هنا لا بالواجهة وحدها.
 * إشعار الاعتماد/الرفض بعد الـcommit ولا يؤثر فشله على الحالة.
 */
class CreatorApplicationService
{
    public function __construct(
        protected CreatorEligibility $eligibility,
        protected OperationalAuditService $audit,
        protected CreatorVisibilityService $visibility,
        protected CreatorNotifier $notifier,
    ) {}

    public function apply(User $user, string $bio): CreatorProfile
    {
        if (($denial = $this->eligibility->applyDenial($user)) !== null) {
            throw new CreatorException($denial);
        }

        $bio = $this->cleanBio($bio);

        try {
            $profile = DB::transaction(function () use ($user, $bio) {
                $existing = CreatorProfile::query()->where('user_id', $user->id)->lockForUpdate()->first();

                if ($existing !== null && ! $existing->isRejected()) {
                    throw new CreatorException('لديك طلب سابق لم يُغلق.');
                }

                $profile = $existing ?? new CreatorProfile;
                $profile->forceFill([
                    'user_id' => $user->id,
                    'slug' => $existing?->slug ?? $this->uniqueSlug($user),
                    'bio' => $bio,
                    'status' => CreatorProfile::STATUS_PENDING,
                    'submitted_at' => now(),
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                    'review_note' => null,
                ])->save();

                return $profile;
            }, TransactionPolicy::DEADLOCK_ATTEMPTS); // E31 (P): طلبان متزامنان لنفس المستخدم: deadlock على MySQL؛ الإعادة ترى الطلب القائم فتُرفض بالرسالة الصحيحة.
        } catch (UniqueConstraintViolationException) {
            // طلبان متزامنان لنفس المستخدم: قيد user_id الفريد يحسمها؛ الخاسر يرى رسالة الطلب القائم.
            throw new CreatorException('لديك طلب قيد المراجعة بالفعل.');
        }

        $user->unsetRelation('creatorProfile');

        return $profile;
    }

    public function approve(CreatorProfile $profile, User $actor, ?string $note = null): CreatorProfile
    {
        $this->assertCan($actor, 'creators.review');

        $profile = $this->transition($profile, [CreatorProfile::STATUS_PENDING], CreatorProfile::STATUS_APPROVED, $actor, $note, 'creator_approved');
        $this->notifier->creatorApproved($profile);

        return $profile;
    }

    public function reject(CreatorProfile $profile, User $actor, string $note): CreatorProfile
    {
        $this->assertCan($actor, 'creators.review');
        $note = $this->requireNote($note);

        $profile = $this->transition($profile, [CreatorProfile::STATUS_PENDING], CreatorProfile::STATUS_REJECTED, $actor, $note, 'creator_rejected');
        $this->notifier->creatorRejected($profile);

        return $profile;
    }

    public function suspend(CreatorProfile $profile, User $actor, string $note): CreatorProfile
    {
        $this->assertCan($actor, 'creators.manage');
        $note = $this->requireNote($note);

        return $this->transition($profile, [CreatorProfile::STATUS_APPROVED], CreatorProfile::STATUS_SUSPENDED, $actor, $note, 'creator_suspended');
    }

    public function restore(CreatorProfile $profile, User $actor, ?string $note = null): CreatorProfile
    {
        $this->assertCan($actor, 'creators.manage');

        return $this->transition($profile, [CreatorProfile::STATUS_SUSPENDED], CreatorProfile::STATUS_APPROVED, $actor, $note, 'creator_restored');
    }

    /** @param list<string> $from */
    protected function transition(CreatorProfile $profile, array $from, string $to, User $actor, ?string $note, string $auditAction): CreatorProfile
    {
        $profile = DB::transaction(function () use ($profile, $from, $to, $actor, $note, $auditAction) {
            $locked = CreatorProfile::query()->lockForUpdate()->findOrFail($profile->id);

            if (! in_array($locked->status, $from, true)) {
                throw new CreatorException('لا يمكن تنفيذ هذا الإجراء على الحالة الحالية للمبدع.');
            }

            $previous = $locked->status;

            $locked->forceFill([
                'status' => $to,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'review_note' => filled($note) ? trim((string) $note) : null,
                'approved_at' => $to === CreatorProfile::STATUS_APPROVED ? ($locked->approved_at ?? now()) : $locked->approved_at,
            ])->save();

            $this->audit->log($auditAction, $locked, ['creator_user_id' => $locked->user_id, 'from' => $previous, 'to' => $to, 'note' => $locked->review_note], $actor);

            return $locked;
        });

        // المصالحة بعد الـcommit: تعليق/استعادة تُخفي/تُظهر منشورات المبدع. فشلها لا يُلغي الحالة (تعيدها أي مصالحة لاحقة).
        $this->visibility->reconcile($profile);

        return $profile->refresh();
    }

    protected function cleanBio(string $bio): string
    {
        $bio = trim(preg_replace('/\s+/u', ' ', $bio) ?? '');
        $min = (int) config('creators.limits.bio_min');
        $max = (int) config('creators.limits.bio_max');

        if (mb_strlen($bio) < $min || mb_strlen($bio) > $max) {
            throw new CreatorException("عرّف بنفسك وبسبب رغبتك بالانضمام ({$min}–{$max} حرفًا).");
        }

        return $bio;
    }

    protected function requireNote(string $note): string
    {
        $note = trim($note);
        $min = (int) config('creators.limits.note_min');
        $max = (int) config('creators.limits.note_max');

        if (mb_strlen($note) < $min || mb_strlen($note) > $max) {
            throw new CreatorException("اكتب سببًا واضحًا ({$min}–{$max} حرفًا).");
        }

        return $note;
    }

    protected function assertCan(User $actor, string $permission): void
    {
        abort_unless($actor->can($permission), 403);
    }

    /** معرّف رابط آمن ASCII فريد (الاسم العربي يُنقل حرفيًا لاتينيًا؛ الفارغ يصير creator). بلا بيانات خاصة: لا بريد ولا رقم داخلي. */
    protected function uniqueSlug(User $user): string
    {
        $base = Str::slug(Str::ascii((string) $user->name)) ?: 'creator';
        $base = Str::limit($base, 60, '');

        do {
            $slug = $base.'-'.Str::lower(Str::random(5));
        } while (CreatorProfile::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
