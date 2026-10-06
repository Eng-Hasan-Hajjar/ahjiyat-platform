<?php

namespace App\Services\Teams;

/** رفض منطقي بالفرق برسالة عربية آمنة للعرض (لا تكشف حظرًا ولا حالة حساب خاصة). */
class TeamException extends \RuntimeException {}
