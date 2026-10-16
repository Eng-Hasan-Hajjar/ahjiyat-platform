<?php

namespace App\Services\Creators;

use RuntimeException;

/** E28 - خطأ مجال متوقَّع (رسالته عربية آمنة للعرض). الأخطاء غير المتوقعة لا تستعمل هذا الصنف. */
class CreatorException extends RuntimeException {}
