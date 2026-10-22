<?php

namespace App\Services\Reputation;

use RuntimeException;

/** E37 - إعداد سمعة غير سليم (أوزان لا تساوي 100، سقف غير موجب...). يوقف الحساب قبل أي كتابة ولا يمسّ أي بيانات. */
class ReputationConfigException extends RuntimeException {}
