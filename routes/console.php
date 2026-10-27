<?php

use App\Services\PendingGemReleaseService;
use App\Support\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

/*
 * E24: كل المهام المجدولة تمرّ من هنا بنفس القاعدتين:
 *  - withoutOverlapping($minutes): كان الافتراضي 24 ساعة، فانهيار عملية أثناء التشغيل (OOM/نشر/قتل) كان يجمّد مهمة ساعية يومًا كاملًا.
 *    القيم أدناه أطول بكثير من زمن التشغيل الطبيعي (أمان ضد التداخل) وأقصر من اليوم (تعافٍ تلقائي).
 *  - onOneServer(): عند تشغيل المجدول على أكثر من خادم (نشر أفقي) ينفّذ خادم واحد فقط كل تشغيل. يحتاج كاشًا مشتركًا (Redis/قاعدة بيانات)؛ على خادم واحد بلا أي أثر.
 *    يُعطَّل بـ PERF_SCHEDULER_ONE_SERVER=false إن لم يتوفّر كاش مشترك بعد.
 */
$guard = function (Event $event, int $lockMinutes): Event {
    $event->withoutOverlapping($lockMinutes);

    return config('performance.scheduler.one_server', true) ? $event->onOneServer() : $event;
};

// تحويل الجواهر المعلقة إلى متاحة يومياً بعد انتهاء فترة التعليق (config('gems.pending_hold_days'))
// المنطق في PendingGemReleaseService: استعلامات مجمّعة لكل دفعة، والتحرير الفعلي حصرًا عبر GemWalletService.
$guard(Schedule::call(fn () => app(PendingGemReleaseService::class)->run())->daily()->name('release-pending-gems'), 360);

// تذكيرات "ينتهي قريبًا" للمواسم والحملات المستقلة (قراءة فقط) - ساعيًا، وقبل تذكيرات العودة بنفس الساعة (ترتيب التسجيل)
// كي يسبق ينتهي-قريبًا تذكير المهام الأدنى أولوية. غيابه لا يكسر شيئًا: لا تصل التذكيرات فقط.
$guard(Schedule::command('notifications:dispatch-lifecycle-reminders')->hourly()->name('notifications-lifecycle-reminders'), 120);

// E15: تذكيرات العودة (تحذير السلسلة + مهام اليوم) - ساعيًا. غيابه لا يكسر شيئًا: لا تصل التذكيرات فقط.
$guard(Schedule::command('competitive:process-lifecycle')->hourly()->name('competitive-process-lifecycle'), 120);

// E19: دعوات الفرق، عدّادات الأعضاء، وترتيب الفرق المتأخر (بعد اعتماد المنافسات).
$guard(Schedule::command('teams:process-lifecycle')->hourly()->name('teams-process-lifecycle'), 120);

$guard(Schedule::command('notifications:dispatch-reengagement')->hourly()->name('notifications-reengagement'), 120);

// E34: تذكيرات LiveOps المحدودة (مهمة جارية تنتهي مع اليوم، أهداف الأسبوع، بدء منافسة للمسجَّلين، عودة بعد غياب) - ساعيًا بعد تذكيرات E15 كي تُحتسب ميزانيتها أولًا.
// قراءة فقط + إشعارات، Idempotent بمفتاح دلالي. غيابه لا يكسر شيئًا: لا تصل التذكيرات فقط.
$guard(Schedule::command('liveops:remind')->hourly()->name('liveops-remind'), 120);

// E15: تنظيف المقروء القديم يوميًا - صحة النظام لا تعتمد عليه.
$guard(Schedule::command('notifications:prune')->daily()->name('notifications-prune'), 360);

// التقاط بدء المواسم الناتج عن مرور الوقت. غيابه لا يكسر شيئًا: البدء بالحفظ الإداري يُلتقَط فورًا بخطافات النماذج.
$guard(Schedule::command('seasons:sync-live-state')->everyFiveMinutes()->name('seasons-sync-live-state'), 30);

// E42: بدء المراحل (إشعار مرة واحدة)، الإعلانات المجدولة المطلوب إشعارها، وتجميد نافذة الموسم المؤرشف بالزمن. غيابه لا يكسر شيئًا: يتأخر الإشعار/التجميد فقط.
$guard(Schedule::command('seasons:process')->everyFiveMinutes()->name('seasons-process'), 30);

// التقاط إتاحة الحملات الناتجة عن مرور الوقت. غيابه لا يكسر شيئًا: الإتاحة بالحفظ الإداري تُلتقَط فورًا بخطاف النموذج.
$guard(Schedule::command('campaigns:sync-availability')->everyFiveMinutes()->name('campaigns-sync-availability'), 30);

// E28: تنظيف صور أحجيات المبدعين اليتيمة أسبوعيًا (ملف بلا نسخة ولا أحجية يرتبط بها، وأقدم من مهلة الأمان). غيابه لا يكسر شيئًا: يبقى الملف اليتيم دون أثر على المنصة.
$guard(Schedule::command('creators:prune-media')->weekly()->name('creators-prune-media'), 120);

// E30: قفل خطط تعزيز مكافآت المنافسات القريبة من البدء (لقطة التمويل قبل البدء؛ المساهمات المتأخرة لا تغيّر جوائز المنافسة الجارية). غيابه = لا تعزيز لتلك المنافسات (فشل مغلق) لا أكثر.
$guard(Schedule::command('sponsorship:lock-event-plans')->everyFiveMinutes()->name('sponsorship-lock-event-plans'), 30);

// E37: تحديث لقطات السمعة المجتمعية (دفعة محدودة: الأقدم حسابًا + المستخدمون الجدد). مشتقّة من سجلات السلطة ولا خطّافات في خدمات E28–E36:
// غيابه لا يكسر أي ميزة، فقط يتأخر تحديث الترتيبات والاعتراف (والترتيبات العلنية تعيد فحص الظهور حيًّا). يتحقق الأمر نفسه من الإعداد ويتوقف إن عُطّلت الميزة.
$guard(Schedule::command('reputation:recalculate --stale')->everyFifteenMinutes()->name('reputation-recalculate'), 30);

// E25: نبض المجدول - يكتب الطابع الزمني بالكاش الافتراضي كل دقيقة؛ عمره = صحة المجدول (مركز العمليات، production:check، وعتبة /ready الاختيارية).
// يمرّ من $guard مثل بقية المهام (قاعدة E24: لا مهمة بلا قفل تداخل محدود ولا onOneServer). قفل 5 دقائق يكفي لعملية بطولها ميلي ثوانٍ وينتهي وحده بعد أي انهيار.
// يُسجَّل أخيرًا كي لا يغيّر ترتيب المهام القائمة.
$guard(Schedule::call(fn () => app(SchedulerHeartbeat::class)->beat())->everyMinute()->name('scheduler-heartbeat'), 5);

// E32: إنهاء طلبات الدعم «بانتظار الدفع» المتأخرة (حالة فقط، لا حذف؛ المرسَل/المحقَّق لا ينتهي). غيابه لا يكسر شيئًا: تبقى الطلبات القديمة مفتوحة حتى يُنفَّذ.
$guard(Schedule::command('payments:expire-stale')->everyFifteenMinutes()->name('payments-expire-stale'), 30);

// E33: استكمال الإحالات المنتظرة لعمر الحساب + إعادة تسليم جوانب مكافآت فاشلة (idempotent). مطابقة referrals:reconcile يدوية للقراءة فقط (لا جدولة تكتب).
$guard(Schedule::command('referrals:process')->everyThirtyMinutes()->name('referrals-process'), 60);

// E40: صيانة مسار الأحجيات (تفعيل/إنهاء بالتاريخ، إنهاء طلبات المميز المتأخرة، تذكير مهلة الاستلام). idempotent؛ غيابه لا يكسر شيئًا: تُحسب النافذة بالتاريخ حيًّا في كل قراءة. مطابقة ahjiyat-pass:reconcile يدوية للقراءة فقط.
$guard(Schedule::command('ahjiyat-pass:process')->everyFifteenMinutes()->name('ahjiyat-pass-process'), 30);

// E41: صيانة التجارة (مزامنة طلبات مستضافة لم يصلها webhook عبر استعلام المزوّد إن دعمه + إعادة محاولة الإشعارات المعزولة). محدودة بدفعة وعمر؛ idempotent؛ لا استطلاع لا نهائي.
// المطابقة commerce:reconcile يدوية للقراءة فقط؛ انتهاء الطلبات المتأخرة يبقى ضمن ahjiyat-pass:process.
$guard(Schedule::command('commerce:process')->everyTenMinutes()->name('commerce-process'), 30);
