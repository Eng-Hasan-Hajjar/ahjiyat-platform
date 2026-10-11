<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\RequestMemo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * الواجهة الوحيدة المسموحة للتعامل مع الإعدادات - لا View/Controller يستعلم
 * PlatformSetting مباشرة. get() يدمج قيمة الـDB (إن وُجدت) فوق Default من
 * config/platform.php، مع Casting صحيح حسب النوع المُعلَن. كل شيء مُخزَّن
 * Cache واحدة شاملة (Array كامل)، تُبطَل فورًا عند أي set()/setMany().
 */
class PlatformSettingsService
{
    protected const CACHE_KEY = 'platform_settings.all';

    protected const MEMO_KEY = 'platform_settings.all';

    protected const BRANDING_MEMO_KEY = 'platform_settings.branding_urls';

    public function get(string $group, string $key, mixed $fallback = null): mixed
    {
        $definition = config("platform.{$group}.{$key}");
        $stored = $this->allRaw()[$group][$key] ?? null;

        $raw = $stored !== null ? $stored : ($definition['default'] ?? $fallback);
        $type = $definition['type'] ?? 'string';

        return $this->cast($raw, $type);
    }

    public function getGroup(string $group): array
    {
        $keys = array_keys(config("platform.{$group}", []));

        return collect($keys)->mapWithKeys(fn ($key) => [$key => $this->get($group, $key)])->all();
    }

    public function set(string $group, string $key, mixed $value, ?User $updatedBy = null): void
    {
        $definition = config("platform.{$group}.{$key}");
        $type = $definition['type'] ?? 'string';

        PlatformSetting::updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $this->serialize($value, $type), 'type' => $type, 'updated_by' => $updatedBy?->id],
        );

        $this->forgetCache();
    }

    public function setMany(string $group, array $values, ?User $updatedBy = null): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, config("platform.{$group}", []))) {
                continue;
            }

            $definition = config("platform.{$group}.{$key}");
            $type = $definition['type'] ?? 'string';

            PlatformSetting::updateOrCreate(
                ['group' => $group, 'key' => $key],
                ['value' => $this->serialize($value, $type), 'type' => $type, 'updated_by' => $updatedBy?->id],
            );
        }

        $this->forgetCache();
    }

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        RequestMemo::forget('platform_settings.');
    }

    /**
     * E24: روابط ملفات الهوية البصرية **الموجودة فعلًا** بالقرص العام (الشعار، الأيقونة، صورة المشاركة). كان القالب يفحص `exists()` ثلاث مرات في كل صفحة (طلب شبكة HEAD لكل ملف على S3).
     * المفتاح يتضمّن بصمة المسارات الحالية، فتتجدد النتيجة فور تغيير أي مسار حتى بلا إبطال صريح، والمدة (TTL) تحدّ حالة حذف الملف يدويًا من القرص. الخرج مطابق لما كان يحسبه القالب.
     *
     * @return array{logo_main: ?string, favicon: ?string, social_share_image: ?string}
     */
    public function brandingUrls(): array
    {
        $paths = [
            'logo_main' => $this->get('branding', 'logo_main'),
            'favicon' => $this->get('branding', 'favicon'),
            'social_share_image' => $this->get('branding', 'social_share_image'),
        ];

        $resolve = function () use ($paths): array {
            $disk = Storage::disk('public');

            return array_map(fn ($path) => $path && $disk->exists($path) ? Storage::url($path) : null, $paths);
        };

        $ttl = (int) config('performance.cache.branding_urls_ttl', 600);

        if ($ttl <= 0) {
            return $resolve();
        }

        return RequestMemo::remember(self::BRANDING_MEMO_KEY, function () use ($paths, $resolve, $ttl) {
            try {
                return Cache::remember('platform_settings.branding_urls.'.md5(json_encode($paths)), $ttl, $resolve);
            } catch (\Throwable) {
                return $resolve();
            }
        });
    }

    public function defaults(): array
    {
        return config('platform', []);
    }

    protected function allRaw(): array
    {
        // E24: get() تُستدعى عشرات المرات بالصفحة الواحدة (القالب يطلب كل المجموعات)؛ كانت كل مرة قراءة كاش كاملة (فكّ تسلسل المصفوفة كلها / استعلام مع CACHE_STORE=database).
        // الآن تُقرأ مرة لكل طلب GET (RequestMemo) وتبقى قراءة الكاش المباشرة كما كانت لباقي الطلبات (POST/الأوامر/الطوابير).
        return RequestMemo::remember(self::MEMO_KEY, fn () => $this->loadAllRaw());
    }

    protected function loadAllRaw(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, function () {
                return PlatformSetting::all()
                    ->groupBy('group')
                    ->map(fn ($rows) => $rows->pluck('value', 'key')->all())
                    ->all();
            });
        } catch (\Throwable $e) {
            // الجدول قد لا يكون موجودًا بعد (تثبيت جديد قبل أي migrate، أو
            // بيئة مؤقتة) - نرجع فارغًا بلا تخزينه بالـCache (لا نُحبَط
            // الحالة الفارغة بعد Migrate لاحقًا)، فتُستخدم Defaults بالكامل
            // من config/platform.php بلا أي انهيار بالصفحة.
            return [];
        }
    }

    protected function cast(mixed $raw, string $type): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($type) {
            'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $raw,
            'float' => (float) $raw,
            'json' => json_decode($raw, true) ?? [],
            default => (string) $raw,
        };
    }

    protected function serialize(mixed $value, string $type): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => json_encode($value),
            default => (string) $value,
        };
    }
}