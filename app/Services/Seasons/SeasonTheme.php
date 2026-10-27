<?php

namespace App\Services\Seasons;

use App\Services\TrustSafety\ModerationException;
use App\Services\TrustSafety\PlainText;

/**
 * E42 - ثيم الموسم/المرحلة: **قائمة بيضاء مغلقة** (preset + accent + tagline نصي عادي). لا CSS ولا JS ولا HTML حرّ أبدًا:
 * الرمز غير المعروف يُرفض عند الحفظ (validated) ويسقط للافتراضي عند العرض، والقيم الملوَّنة تأتي من جدول ثابت هنا فقط (آمنة للإدراج في style).
 */
final class SeasonTheme
{
    private const ACCENTS = [
        'amethyst' => '#8b5cf6', 'gold' => '#f59e0b', 'emerald' => '#10b981',
        'sky' => '#0ea5e9', 'rose' => '#f43f5e', 'teal' => '#14b8a6',
    ];

    private const ACCENT_LABELS = [
        'amethyst' => 'بنفسجي', 'gold' => 'ذهبي', 'emerald' => 'زمردي',
        'sky' => 'سماوي', 'rose' => 'وردي', 'teal' => 'أزرق مخضر',
    ];

    private const PRESET_LABELS = ['generic' => 'عام (هوية المنصة القياسية)', 'aseel' => 'أصيل (غموض وتحقيق رقمي)'];

    /** رمز مسموح فقط (ضمن الإعداد والجدول الثابت) أو null. */
    public static function accentToken(mixed $value): ?string
    {
        $token = is_string($value) ? $value : '';

        return in_array($token, (array) config('seasons.theme.accents', []), true) && isset(self::ACCENTS[$token]) ? $token : null;
    }

    public static function accentColor(?string $token): ?string
    {
        return $token === null ? null : (self::ACCENTS[self::accentToken($token) ?? ''] ?? null);
    }

    /** @return array<string, string> */
    public static function accentLabels(): array
    {
        return array_intersect_key(self::ACCENT_LABELS, array_flip((array) config('seasons.theme.accents', [])));
    }

    /** @return array<string, string> */
    public static function presetLabels(): array
    {
        return array_intersect_key(self::PRESET_LABELS, array_flip((array) config('seasons.theme.presets', ['generic'])));
    }

    /**
     * يتحقق من theme_config الموسم ويعيده نظيفًا. مفاتيح مسموحة فقط: preset (قائمة بيضاء) وhero_tagline (نص عادي). أي مفتاح آخر أو قيمة غير مسموحة = رفض صريح.
     *
     * @param  array<string, mixed>|null  $theme
     * @return array<string, mixed>
     *
     * @throws SeasonException
     */
    public static function validatedSeason(?array $theme): array
    {
        $theme ??= [];
        $unknown = array_diff(array_keys($theme), ['preset', 'hero_tagline']);

        if ($unknown !== []) {
            throw new SeasonException('theme_invalid', 'إعدادات الثيم تقبل النمط والجملة التعريفية فقط.');
        }

        $preset = $theme['preset'] ?? 'generic';

        if (! is_string($preset) || ! array_key_exists($preset, self::presetLabels())) {
            throw new SeasonException('theme_invalid', 'نمط الثيم غير مسموح. اختر من القائمة المعتمدة.');
        }

        $clean = ['preset' => $preset];
        $tagline = self::tagline($theme['hero_tagline'] ?? null);

        if ($tagline !== null) {
            $clean['hero_tagline'] = $tagline;
        }

        return $clean;
    }

    /**
     * ثيم المرحلة: accent مسموح فقط (مجموعة جزئية صغيرة من رموز الموسم). فارغ ← null (يرث الموسم).
     *
     * @param  array<string, mixed>|null  $theme
     * @return array<string, mixed>|null
     *
     * @throws SeasonException
     */
    public static function validatedPhase(?array $theme): ?array
    {
        $theme = array_filter($theme ?? [], fn ($v) => $v !== null && $v !== '');

        if ($theme === []) {
            return null;
        }

        if (array_diff(array_keys($theme), ['accent']) !== [] || self::accentToken($theme['accent'] ?? null) === null) {
            throw new SeasonException('theme_invalid', 'لون المرحلة يجب أن يكون من القائمة المعتمدة.');
        }

        return ['accent' => $theme['accent']];
    }

    private static function tagline(mixed $value): ?string
    {
        if ($value !== null && ! is_scalar($value)) {
            throw new SeasonException('theme_invalid', 'الجملة التعريفية نص عادي فقط.');
        }

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return PlainText::clean((string) $value, 1, (int) config('seasons.theme.tagline_max', 120), 'الجملة التعريفية');
        } catch (ModerationException $e) {
            throw new SeasonException('theme_invalid', $e->getMessage());
        }
    }
}
