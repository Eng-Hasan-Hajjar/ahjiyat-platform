<?php

namespace App\Http\Middleware;

use App\Services\AiAssistant\TatawwurSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * E45 - بوابة تطوّر AI: المفتاح الشامل مغلق، أو الضيف غير مسموح له (TATAWWUR_AI_GUESTS_ENABLED=false)، أو الحساب مجمَّد ⇒ 404 مبكر (قبل التحقق من المدخلات).
 * تعطيل المساعد لا يمسّ بقية المنصة، ولا يكشف وجود المسارات.
 */
class EnsureTatawwurAiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(TatawwurSettings::widgetVisibleFor($request->user()), 404);

        return $next($request);
    }
}
