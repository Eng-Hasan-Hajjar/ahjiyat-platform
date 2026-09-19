<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * الواجهة الوحيدة المسموحة للتعامل مع الإعدادات - لا View/Controller يستعلم
 * PlatformSetting مباشرة. get() يدمج قيمة الـDB (إن وُجدت) فوق Default من
 * config/platform.php، مع Casting صحيح حسب النوع المُعلَن. كل شيء مُخزَّن
 * Cache واحدة شاملة (Array كامل)، تُبطَل فورًا عند أي set()/setMany().
 */
class PlatformSettingsService
{
    protected const CACHE_KEY = 'platform_settings.all';

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
    }

    public function defaults(): array
    {
        return config('platform', []);
    }

    protected function allRaw(): array
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