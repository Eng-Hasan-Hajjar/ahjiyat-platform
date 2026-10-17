<?php

namespace App\Services\CreatorSeries;

use App\Services\Creators\CreatorException;

/** E30 - خطأ مجال متوقَّع لسلاسل المبدعين (رسالته عربية آمنة للعرض). يرث CreatorException فيلتقطه أساس متحكمات المبدعين نفسه. */
class CreatorSeriesException extends CreatorException {}
