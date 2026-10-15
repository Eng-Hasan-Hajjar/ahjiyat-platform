<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;

/**
 * E26: يقيّد ترويسة Host بمضيف التطبيق (APP_URL) + مضيفات إضافية صريحة (SECURITY_TRUSTED_HOSTS). بدونه يبني Laravel روابط البريد (إعادة تعيين كلمة المرور، توثيق البريد)
 * من Host الطلب نفسه: مهاجم يرسل POST /forgot-password بـ«Host: evil.test» فيصل الضحية بريد رسمي برابط إعادة تعيين يشير لنطاق المهاجم ويسرّب الرمز (Password-reset poisoning).
 *
 *  - لا يعمل في البيئة المحلية ولا في الاختبارات (قاعدة Laravel نفسها: shouldSpecifyTrustedHosts) فلا يكسر XAMPP/127.0.0.1.
 *  - مسارا الصحة /up و/ready مستثنيان: موازنات الحِمل تفحصهما غالبًا بـHost = عنوان IP داخلي.
 *  - مفتاح الإيقاف الاضطراري: SECURITY_TRUST_HOSTS=false (لا يُنصح به بالإنتاج).
 */
class TrustApplicationHosts extends TrustHosts
{
    public function hosts(): array
    {
        $hosts = [];

        if (is_string($appHost = parse_url((string) $this->app['config']->get('app.url'), PHP_URL_HOST)) && $appHost !== '') {
            $hosts[] = '^'.preg_quote($appHost).'$';
        }

        foreach ((array) $this->app['config']->get('security.hosts.extra', []) as $extra) {
            if (is_string($extra) && trim($extra) !== '') {
                $hosts[] = '^'.preg_quote(trim($extra)).'$';
            }
        }

        return $hosts;
    }

    public function handle(Request $request, $next)
    {
        if ($request->is('up', 'ready')) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    protected function shouldSpecifyTrustedHosts()
    {
        return (bool) $this->app['config']->get('security.hosts.enforce', true) && parent::shouldSpecifyTrustedHosts();
    }
}
