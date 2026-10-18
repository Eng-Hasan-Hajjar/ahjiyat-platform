<?php

use App\Http\Controllers\Support\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
| E32 - Webhook عام لمزوّدين إلكترونيين مستقبليين. يُسجَّل في bootstrap/app.php بمجموعة «api» (بلا جلسة ولا كوكيز ولا CSRF بطبيعتها، لا استثناء من وسيط) وبحدّ معدّل payment-webhook.
| المسار مغلق افتراضيًا (PAYMENTS_WEBHOOKS_ENABLED=false) ويرفض كل مزوّد غير معروف/معطَّل/لا يدعم webhook، ولا يعتمد حدثًا دون تحقق توقيع المزوّد نفسه. انظر PaymentWebhookController.
*/
Route::post('/api/payments/webhooks/{provider}', PaymentWebhookController::class)->where('provider', '[a-z0-9_]{1,32}')->middleware('throttle:payment-webhook')->name('payments.webhook');
