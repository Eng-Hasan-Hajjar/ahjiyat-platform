<?php

namespace App\Services\Commerce;

/**
 * E41 - قرار بيئة واحد للمزوّد التجريبي (sandbox). **يستحيل تفعيله بالخطأ في الإنتاج**: بيئة production ⇒ مرفوض مهما كانت قيم الضبط.
 * مسموح فقط local / testing / ci (أو CI=true خارج الإنتاج). أي بيئة أخرى (staging…) ⇒ مغلق ما لم تكن CI.
 */
final class CommerceEnvironment
{
    public static function sandboxAllowed(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        if (app()->environment(['local', 'testing', 'ci'])) {
            return true;
        }

        return filter_var(env('CI', false), FILTER_VALIDATE_BOOLEAN);
    }

    /** مفتاح webhook العام: الافتراضي مفتوح في البيئات التجريبية ومغلق خارجها (لا مزوّد حيّ منفَّذ الآن). */
    public static function webhooksEnabled(): bool
    {
        $configured = config('commerce.webhooks.enabled');

        if ($configured === null || $configured === '') {
            return self::sandboxAllowed();
        }

        return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    }
}
