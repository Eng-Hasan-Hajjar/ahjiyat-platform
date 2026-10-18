<?php

namespace App\Services\Payments;

use App\Services\Sponsorship\SponsorshipException;

/** E32 - خطأ أعمال دفع مفهوم للمستخدم/المراجع (رسالة عربية آمنة للعرض). يرث SponsorshipException فتلتقطه معالجات الرعاية القائمة. لا يحمل بيانات خاصة. */
class PaymentException extends SponsorshipException {}
