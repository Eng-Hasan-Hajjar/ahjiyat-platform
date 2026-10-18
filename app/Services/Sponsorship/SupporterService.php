<?php

namespace App\Services\Sponsorship;

use App\Models\SupporterProfile;
use App\Models\User;
use App\Rules\NoMarkup;
use App\Services\OperationalAuditService;
use App\Support\SafeUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * E30 - ملفات الداعمين. الكتابة الإدارية فقط (sponsorships.manage) وبلا تعيين جماعي؛ كل تغيير يُدقَّق. لا تفاصيل دفع هنا ولا بأي مكان.
 * المدخلات نص عادي (NoMarkup)، الرابط يمرّ بـSafeUrl (http/https فقط)، الشعار JPEG/PNG/WEBP فقط. الـslug يُولَّد مرة ولا يتغير (روابط ثابتة).
 */
class SupporterService
{
    public function __construct(protected OperationalAuditService $audit, protected SupporterLogoService $logos) {}

    /**
     * @param  array{display_name?: string, type?: string, description?: ?string, website_url?: ?string, status?: string, is_public?: bool, anonymous_by_default?: bool, user_id?: ?int}  $data
     *
     * @throws SponsorshipException
     */
    public function create(User $actor, array $data, ?UploadedFile $logo = null): SupporterProfile
    {
        $this->authorize($actor);
        $clean = $this->clean($data, true);
        $logoPath = $logo !== null ? $this->logos->store($logo) : null;

        try {
            $profile = DB::transaction(function () use ($actor, $clean, $logoPath) {
                $profile = new SupporterProfile;
                $profile->forceFill($clean + [
                    'slug' => $this->uniqueSlug($clean['display_name']),
                    'logo_path' => $logoPath,
                    'created_by' => $actor->id,
                ])->save();

                $this->audit->log('supporter_created', $profile, ['public_id' => $profile->public_id, 'type' => $profile->type, 'name' => $profile->display_name], $actor);

                return $profile;
            });
        } catch (\Throwable $e) {
            $this->logos->delete($logoPath);

            throw $e;
        }

        SponsorshipCache::bust();

        return $profile;
    }

    /**
     * E32: ملف الداعم لمستخدم دفع حقيقي، يُستدعى **داخل معاملة تحقق الدفع** (لا يتطلب sponsorships.manage؛ المنادي SponsorshipPaymentService يتحقق من صلاحية التأكيد).
     * يقفل صف المستخدم أولًا فيتسلسل أي تحققَين متزامنَين لنفس المستخدم (لا ملف مكرر؛ والقيد UNIQUE(user_id) هو الحدّ الأخير).
     *  - ملف قائم ⇒ يُعاد كما هو (لا تغيير لاسمه أو حالته). استثناء مقصود: ملف أنشأه المستخدم بنفسه عبر الدفع (created_by = user_id) وكان خاصًا فقط لأن أول دعمه كان مجهولًا،
     *    ثم اختار الآن الظهور العلني ⇒ يصير علنيًا (قراره الصريح بهذا الطلب). ملفات الإدارة لا تُمسّ.
     *  - ملف جديد ⇒ علني فقط إن اختار الظهور باسمه؛ دعمٌ مجهول لا ينشئ صفحة علنية بل ملفًا خاصًا (الداعم المجهول لا يُعرَف من أي سطح عام).
     */
    public function ensureForUser(User $owner, ?string $displayName, bool $publicChoice, ?User $actor): SupporterProfile
    {
        User::query()->lockForUpdate()->findOrFail($owner->id);

        $existing = SupporterProfile::query()->where('user_id', $owner->id)->lockForUpdate()->first();

        if ($existing !== null) {
            if ($publicChoice && ! $existing->is_public && $existing->status === SupporterProfile::STATUS_ACTIVE && (int) $existing->created_by === (int) $owner->id) {
                $existing->forceFill(['is_public' => true])->save();
                $this->audit->log('supporter_visibility_changed', $existing, ['public_id' => $existing->public_id, 'public' => true, 'source' => 'checkout'], $actor);
            }

            return $existing;
        }

        $name = trim((string) $displayName);
        $max = (int) config('sponsorship.profile.name_max', 80);

        if (mb_strlen($name) < 2 || mb_strlen($name) > $max || Validator::make(['n' => $name], ['n' => [new NoMarkup]])->fails()) {
            $name = Str::limit(trim(strip_tags((string) $owner->name)), $max, '');
        }

        if (mb_strlen($name) < 2) {
            $name = 'داعم';
        }

        $profile = new SupporterProfile;
        $profile->forceFill([
            'user_id' => $owner->id,
            'type' => SupporterProfile::TYPE_INDIVIDUAL,
            'display_name' => $name,
            'slug' => $this->uniqueSlug($name),
            'status' => SupporterProfile::STATUS_ACTIVE,
            'is_public' => $publicChoice,
            'anonymous_by_default' => ! $publicChoice,
            'created_by' => $owner->id,
        ])->save();

        $this->audit->log('supporter_created', $profile, ['public_id' => $profile->public_id, 'type' => $profile->type, 'source' => 'checkout', 'public' => $publicChoice], $actor);

        return $profile;
    }

    /** @throws SponsorshipException */
    public function update(User $actor, SupporterProfile $profile, array $data, ?UploadedFile $logo = null, bool $removeLogo = false): SupporterProfile
    {
        $this->authorize($actor);
        $clean = $this->clean($data, false, $profile);
        $newLogo = $logo !== null ? $this->logos->store($logo) : null;
        $oldLogo = $profile->logo_path;

        try {
            DB::transaction(function () use ($actor, $profile, $clean, $newLogo, $removeLogo) {
                $profile->forceFill($clean);

                if ($newLogo !== null) {
                    $profile->logo_path = $newLogo;
                } elseif ($removeLogo) {
                    $profile->logo_path = null;
                }

                $changed = array_keys($profile->getDirty());
                $profile->save();

                if ($changed !== []) {
                    $this->audit->log('supporter_updated', $profile, ['changed' => $changed], $actor);
                }
            });
        } catch (\Throwable $e) {
            $this->logos->delete($newLogo);

            throw $e;
        }

        if (($newLogo !== null || $removeLogo) && $oldLogo !== $profile->logo_path) {
            $this->logos->delete($oldLogo);
        }

        SponsorshipCache::bust();

        return $profile->refresh();
    }

    /** @throws SponsorshipException */
    public function setStatus(User $actor, SupporterProfile $profile, string $status): SupporterProfile
    {
        $this->authorize($actor);

        if (! in_array($status, SupporterProfile::STATUSES, true)) {
            throw new SponsorshipException('حالة غير صالحة.');
        }

        $from = $profile->status;

        if ($from !== $status) {
            $profile->forceFill(['status' => $status])->save();
            $this->audit->log('supporter_status_changed', $profile, ['from' => $from, 'to' => $status], $actor);
            SponsorshipCache::bust();
        }

        return $profile;
    }

    protected function authorize(User $actor): void
    {
        abort_unless($actor->can('sponsorships.manage'), 403);
    }

    /** @return array<string, mixed> */
    protected function clean(array $data, bool $creating, ?SupporterProfile $existing = null): array
    {
        $max = (array) config('sponsorship.profile');

        $validator = Validator::make($data, [
            'display_name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:'.$max['name_max'], new NoMarkup],
            'type' => [$creating ? 'required' : 'sometimes', 'in:'.implode(',', SupporterProfile::TYPES)],
            'description' => ['nullable', 'string', 'max:'.$max['description_max'], new NoMarkup],
            'website_url' => ['nullable', 'string', 'max:'.$max['url_max']],
            'status' => ['sometimes', 'in:'.implode(',', SupporterProfile::STATUSES)],
            'is_public' => ['sometimes', 'boolean'],
            'anonymous_by_default' => ['sometimes', 'boolean'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ], [], ['display_name' => 'الاسم', 'type' => 'النوع', 'description' => 'الوصف', 'website_url' => 'الموقع', 'user_id' => 'المستخدم']);

        if ($validator->fails()) {
            throw new SponsorshipException((string) $validator->errors()->first());
        }

        $out = [];

        foreach (['display_name', 'type', 'status', 'is_public', 'anonymous_by_default'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = is_string($data[$key]) ? trim($data[$key]) : $data[$key];
            }
        }

        if (array_key_exists('description', $data)) {
            $out['description'] = filled($data['description']) ? trim((string) $data['description']) : null;
        }

        if (array_key_exists('website_url', $data)) {
            $url = filled($data['website_url']) ? SafeUrl::normalize((string) $data['website_url'], (int) $max['url_max']) : null;

            if (filled($data['website_url']) && $url === null) {
                throw new SponsorshipException('رابط الموقع غير آمن أو غير صالح (http/https فقط).');
            }

            $out['website_url'] = $url;
        }

        if (array_key_exists('user_id', $data)) {
            $userId = filled($data['user_id']) ? (int) $data['user_id'] : null;

            if ($userId !== null && SupporterProfile::query()->where('user_id', $userId)->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists()) {
                throw new SponsorshipException('هذا المستخدم مرتبط بملف داعم آخر.');
            }

            $out['user_id'] = $userId;
        }

        if ($creating) {
            $out += ['status' => SupporterProfile::STATUS_ACTIVE, 'is_public' => true, 'anonymous_by_default' => false];
        }

        return $out;
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug(Str::ascii($name)), 60, '') ?: 'supporter';

        do {
            $slug = $base.'-'.Str::lower(Str::random(5));
        } while (SupporterProfile::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
