<?php

namespace App\Services\Database;

/**
 * E31: خطأ نقل بيانات برسالة آمنة بالتصميم (اسم جدول/عمود/معرّف صف/رمز خطأ فقط). لا تحمل أبدًا قيمًا من الصفوف ولا نص استثناء الخادم
 * (رسائل MySQL قد تتضمن قيمًا مثل «Duplicate entry '...'» أو «Incorrect string value»).
 */
final class TransferException extends \RuntimeException {}
