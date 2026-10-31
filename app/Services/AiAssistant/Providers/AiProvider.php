<?php

namespace App\Services\AiAssistant\Providers;

use App\Services\AiAssistant\Dto\AiRequest;
use App\Services\AiAssistant\Dto\AiResponse;

/**
 * E45 - واجهة مزوّد الذكاء الاصطناعي (محايدة للمزوّد). منطق الأعمال كله يعتمد عليها فقط؛ لا مزوّد بعينه مثبَّت في أي مكان آخر.
 * التنفيذ مسؤول عن: مهلات صارمة، إعادة محاولة محدودة، عدم تسريب أسرار في الأخطاء/السجلات، وعدم تخزين الحالة عند المزوّد.
 */
interface AiProvider
{
    public function key(): string;

    /** هل المزوّد مهيّأ فعلًا (مفتاح + نموذج + عنوان مسموح)؟ لا يكشف أي قيمة سرية. */
    public function isConfigured(): bool;

    public function supportsStreaming(): bool;

    /** @throws AiProviderException */
    public function generate(AiRequest $request): AiResponse;

    /**
     * يولّد مع بثّ دلتا النص تباعًا إلى $onText؛ الناتج النهائي هو نفسه ردّ generate.
     *
     * @param  callable(string): void  $onText
     *
     * @throws AiProviderException
     */
    public function stream(AiRequest $request, callable $onText): AiResponse;
}
