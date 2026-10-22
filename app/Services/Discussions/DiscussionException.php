<?php

namespace App\Services\Discussions;

use App\Services\Engagement\EngagementException;

/** E36 - خطأ مجال متوقَّع بالنقاشات (رسالته عربية آمنة للعرض). يمتد من EngagementException فتلتقطه كل أسطح التفاعل القائمة (وFilament) كما هو. */
class DiscussionException extends EngagementException {}
