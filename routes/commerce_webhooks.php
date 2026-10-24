<?php

use App\Http\Controllers\Commerce\CommerceWebhookController;
use Illuminate\Support\Facades\Route;

/*
| E41 - Webhook التجارة الاستهلاكية (المشتريات الرقمية). يُسجَّل في bootstrap/app.php بمجموعة «api» (بلا جلسة ولا كوكيز ولا CSRF بطبيعتها، لا استثناء من وسيط) وبحدّ معدّل commerce-webhook.
| منفصل عن webhook الرعاية (E32). المصادقة بتوقيع المزوّد حصرًا؛ مزوّد مجهول/لا يستقبل ⇒ 404. انظر CommerceWebhookController و CommerceWebhookService.
*/
Route::post('/api/commerce/webhooks/{provider}', CommerceWebhookController::class)->where('provider', '[a-z0-9_]{1,32}')->middleware('throttle:commerce-webhook')->name('commerce.webhook');
