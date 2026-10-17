<?php

namespace App\Services\Engagement;

use RuntimeException;

/** E29 - خطأ مجال متوقَّع بالتفاعل (رسالته عربية آمنة للعرض). الأخطاء غير المتوقعة لا تستعمل هذا الصنف. */
class EngagementException extends RuntimeException {}
