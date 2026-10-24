<?php

namespace App\Services\AhjiyatPass;

use App\Models\AhjiyatPass;

/**
 * E40 - ترجمة رموز ثيم المسار (قائمة بيضاء مغلقة من الإعداد) إلى قيم CSS ثابتة من الخادم. لا HTML ولا CSS ولا JS حرّ من الإدارة أبدًا:
 * الرمز غير المعروف يسقط للافتراضي، والقيم الناتجة من هذه الجداول الثابتة فقط (آمنة للإدراج في style).
 */
final class PassTheme
{
    private const ACCENTS = [
        'amber' => '#f59e0b', 'emerald' => '#10b981', 'sky' => '#0ea5e9',
        'violet' => '#8b5cf6', 'rose' => '#f43f5e', 'teal' => '#14b8a6',
    ];

    private const BACKGROUNDS = [
        'aurora' => 'linear-gradient(135deg, rgba(139,92,246,.35), rgba(16,185,129,.20) 55%, rgba(15,23,42,.9))',
        'dusk' => 'linear-gradient(135deg, rgba(244,63,94,.30), rgba(99,102,241,.25) 55%, rgba(15,23,42,.9))',
        'forest' => 'linear-gradient(135deg, rgba(16,185,129,.30), rgba(20,184,166,.18) 55%, rgba(15,23,42,.9))',
        'ocean' => 'linear-gradient(135deg, rgba(14,165,233,.32), rgba(99,102,241,.20) 55%, rgba(15,23,42,.9))',
        'ember' => 'linear-gradient(135deg, rgba(245,158,11,.32), rgba(244,63,94,.20) 55%, rgba(15,23,42,.9))',
    ];

    public static function accent(AhjiyatPass|string|null $source): string
    {
        $token = $source instanceof AhjiyatPass ? $source->themeAccent() : (string) $source;

        return self::ACCENTS[$token] ?? self::ACCENTS['amber'];
    }

    public static function background(AhjiyatPass|string|null $source): string
    {
        $token = $source instanceof AhjiyatPass ? $source->themeBackground() : (string) $source;

        return self::BACKGROUNDS[$token] ?? self::BACKGROUNDS['aurora'];
    }

    /** @return array<string, string> رمز ⇐ تسمية عربية للنماذج الإدارية. */
    public static function accentLabels(): array
    {
        $labels = ['amber' => 'عنبري', 'emerald' => 'زمردي', 'sky' => 'سماوي', 'violet' => 'بنفسجي', 'rose' => 'وردي', 'teal' => 'أزرق مخضر'];

        return array_intersect_key($labels, array_flip((array) config('ahjiyat_pass.theme.accents', array_keys($labels))));
    }

    /** @return array<string, string> */
    public static function backgroundLabels(): array
    {
        $labels = ['aurora' => 'شفق', 'dusk' => 'غسق', 'forest' => 'غابة', 'ocean' => 'محيط', 'ember' => 'جمر'];

        return array_intersect_key($labels, array_flip((array) config('ahjiyat_pass.theme.backgrounds', array_keys($labels))));
    }
}
