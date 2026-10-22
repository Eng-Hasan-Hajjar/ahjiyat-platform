<?php

namespace App\Services\Reputation;

use App\Services\Engagement\EngagementException;

/** E37 - خطأ مجال متوقَّع بالسمعة/الإبراز (رسالته عربية آمنة للعرض). يمتد من EngagementException فتلتقطه أسطح التفاعل القائمة وFilament كما هو. */
class ReputationException extends EngagementException {}
