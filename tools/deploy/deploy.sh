#!/usr/bin/env bash
# =============================================================================
# E25 - تسلسل نشر الإنتاج (نموذج محايد للمزوّد). لا يُشغَّل تلقائيًا من أي مكان: راجعه وكيّفه لبنيتك أولًا.
# docs/first-production-deploy.md  ·  docs/release-engineering.md
#
# يفترض أن الكود الجديد موضوع مسبقًا في مجلد التطبيق (artifact/rsync/آلية مزوّدك) - لا يستعمل git ولا يسحب شيئًا.
# لا يحتوي أسرارًا: كل شيء من البيئة. لا يطبع أي سر. لا يستعمل أوامر هدّامة (لا migrate:fresh/db:wipe/rollback) ولا يحذف ملفات.
#
# الاستعمال:
#   bash tools/deploy/deploy.sh --dry-run                       # يطبع الخطوات فقط ولا ينفّذ شيئًا (ابدأ بهذا دائمًا)
#   bash tools/deploy/deploy.sh --release=b983867 --backup-confirmed
#
# الخيارات:
#   --release=ID         معرّف الإصدار (SHA مختصر/وسم) - يُخبَز في كاش الإعداد عبر optimize ويظهر في /ready والسجلات (أو RELEASE_SHA/APP_RELEASE من البيئة)
#   --backup-confirmed   إقرار صريح بأن نسخة احتياطية حديثة مُتحقَّق منها موجودة (إلزامي لتنفيذ migrate؛ أو DEPLOY_BACKUP_CONFIRMED=yes)
#   --maintenance        php artisan down أثناء الترحيل (فقط لترحيل غير متوافق مع الكود السابق، أو حادثة). سرّ الدخول (إن وُجد) من MAINTENANCE_SECRET بالبيئة ولا يُطبع.
#   --skip-build         لا composer/npm (الأصول والتبعيات مبنية سلفًا بـCI/artifact)
#   --dry-run            لا ينفّذ شيئًا
#
# متغيرات اختيارية بالبيئة:
#   DEPLOY_RELOAD_CMD    أمر إعادة تحميل PHP-FPM/OPcache الخاص ببنيتك (مثل: "sudo systemctl reload php8.3-fpm") - يُنفَّذ بعد optimize
#   DEPLOY_SMOKE_URL     عنوان فحص الدخان (الافتراضي APP_URL من .env الحالي عبر artisan)
# =============================================================================
set -Eeuo pipefail

DRY_RUN=0
SKIP_BUILD=0
MAINTENANCE=0
BACKUP_CONFIRMED=0
RELEASE="${APP_RELEASE:-${RELEASE_SHA:-}}"
MAINT_ACTIVE=0

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --skip-build) SKIP_BUILD=1 ;;
    --maintenance) MAINTENANCE=1 ;;
    --backup-confirmed) BACKUP_CONFIRMED=1 ;;
    --release=*) RELEASE="${arg#--release=}" ;;
    -h|--help) sed -n '2,28p' "$0"; exit 0 ;;
    *) echo "خيار غير معروف: $arg" >&2; exit 2 ;;
  esac
done

[ "${DEPLOY_BACKUP_CONFIRMED:-}" = "yes" ] && BACKUP_CONFIRMED=1

if [ -n "$RELEASE" ] && ! [[ "$RELEASE" =~ ^[A-Za-z0-9._-]{1,64}$ ]]; then
  echo "معرّف الإصدار غير آمن (المسموح [A-Za-z0-9._-] حتى 64 خانة)" >&2
  exit 2
fi
export APP_RELEASE="$RELEASE"

step() { echo; echo "==> $*"; }

# ينفّذ الأمر أو يطبعه (dry-run). لا يستعمل eval، ولا يطبع قيم المتغيرات السرية.
run() {
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "    [dry-run] $*"
  else
    "$@"
  fi
}

on_error() {
  local code=$?
  echo >&2
  echo "فشل النشر عند الخطوة أعلاه (exit $code). لم يُنفَّذ أي تراجع تلقائي عن القاعدة (التراجع عن القاعدة غير آمن بعد الكتابات)." >&2
  if [ "$MAINT_ACTIVE" -eq 1 ]; then
    echo "التطبيق ما زال في وضع الصيانة عمدًا - لا تُخرجه قبل فهم السبب (docs/release-engineering.md: جدول التراجع)." >&2
  fi
  echo "راجع: docs/release-engineering.md (التراجع) و docs/incident-response.md" >&2
  exit "$code"
}
trap on_error ERR

cd "$(dirname "$0")/../.."
echo "مجلد التطبيق: $(pwd)"
echo "الإصدار: ${RELEASE:-<غير مضبوط - يُنصح بـ --release=>}"
[ "$DRY_RUN" -eq 1 ] && echo "(وضع dry-run: لن يُنفَّذ شيء)"

step "0) متطلبات أساسية"
# بالتشغيل الفعلي أي نقص يوقف السكربت؛ بـ dry-run يُبلَّغ عنه فقط (كي يمكن مراجعة التسلسل على أي جهاز).
need() {
  if [ "$DRY_RUN" -eq 1 ]; then echo "    [dry-run] ينقص: $1"; else echo "$1" >&2; exit 1; fi
}
for bin in php composer; do
  command -v "$bin" >/dev/null 2>&1 || need "الأداة غير موجودة: $bin"
done
if [ "$SKIP_BUILD" -eq 0 ]; then
  command -v npm >/dev/null 2>&1 || need "الأداة غير موجودة: npm (أو استعمل --skip-build)"
fi
[ -f artisan ] || need "artisan غير موجود: شغّل السكربت من مستودع التطبيق"
[ -f .env ] || [ -n "${APP_KEY:-}" ] || need "لا .env ولا متغيرات بيئة (APP_KEY) - جهّز البيئة أولًا (docs/environment-variables.md)"

if [ "$SKIP_BUILD" -eq 0 ]; then
  step "1) التبعيات (composer.lock هو مصدر الإصدارات - لا composer update)"
  run composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

  step "2) بناء الواجهة (package-lock.json هو مصدر الإصدارات - لا npm update)"
  run npm ci
  run npm run build
else
  step "1-2) تخطّي بناء التبعيات والواجهة (--skip-build)"
fi

step "3) فحص ما قبل النشر (قراءة فقط) - يوقف النشر عند أي FAIL"
run php artisan release:preflight

step "3b) إقرار النسخة الاحتياطية - قبل أي تغيير بالحالة (بما فيه وضع الصيانة، كي لا يبقى الموقع في صيانة بسبب رفض لاحق)"
if [ "$BACKUP_CONFIRMED" -ne 1 ] && [ "$DRY_RUN" -ne 1 ]; then
  echo "ممنوع تنفيذ migrate بلا إقرار النسخة الاحتياطية. خذ نسخة (tools/backup/db-backup.sh) ثم أعد التشغيل بـ --backup-confirmed" >&2
  exit 3
fi
[ "$BACKUP_CONFIRMED" -eq 1 ] && echo "    نسخة احتياطية مُقرّة." || echo "    [dry-run] سيُطلب --backup-confirmed عند التنفيذ الفعلي."

if [ "$MAINTENANCE" -eq 1 ]; then
  step "4) وضع الصيانة (لترحيل غير متوافق فقط) - أثناءه: HTTP يعطي 503، العمال يبقون عمليات قائمة لكن لا يسحبون مهامًا، والمجدول يتخطى المهام"
  if [ -n "${MAINTENANCE_SECRET:-}" ]; then
    # السرّ لا يُطبع أبدًا (ولا بـ dry-run). استعمل قيمة جديدة لكل مرة ولا تحفظها بالمستودع/الوثائق/السجلات.
    if [ "$DRY_RUN" -eq 1 ]; then
      echo "    [dry-run] php artisan down --retry=60 --refresh=15 --secret=<من MAINTENANCE_SECRET>"
    else
      php artisan down --retry=60 --refresh=15 --secret="$MAINTENANCE_SECRET"
    fi
  else
    run php artisan down --retry=60 --refresh=15
  fi
  MAINT_ACTIVE=1
else
  step "4) بلا وضع الصيانة (الترحيلات المتوافقة additive-first لا تحتاجه)"
fi

step "5) الترحيلات (للأمام فقط؛ الإقرار بالنسخة الاحتياطية تحقّقنا منه قبل الصيانة)"
run php artisan migrate --force

step "6) مزامنة سجلّ الصلاحيات (ينشئ الناقص فقط ولا يمنح أدوارًا؛ منح صلاحيات جديدة للأدوار = RolesAndPermissionsSeeder خطوة يدوية واعية عند الحاجة)"
run php artisan permissions:sync

step "7) رابط التخزين العام (آمن إن وُجد)"
if [ "$DRY_RUN" -eq 1 ]; then echo "    [dry-run] php artisan storage:link"; else php artisan storage:link || true; fi

step "8) optimize: يبني كاش config/routes/views/events ويخبز APP_RELEASE بالكاش"
run php artisan optimize

if [ -n "${DEPLOY_RELOAD_CMD:-}" ]; then
  step "9) إعادة تحميل PHP-FPM/OPcache (الأمر من DEPLOY_RELOAD_CMD)"
  if [ "$DRY_RUN" -eq 1 ]; then echo "    [dry-run] \$DEPLOY_RELOAD_CMD"; else bash -c "$DEPLOY_RELOAD_CMD"; fi
else
  step "9) لا DEPLOY_RELOAD_CMD: أعد تحميل PHP-FPM/OPcache يدويًا بحسب بنيتك كي يُحمَّل الكود الجديد"
fi

step "10) إعادة تشغيل آمنة (graceful) لعمال الطابور وReverb - يُكمل كل عامل مهمته الحالية ثم يخرج ويعيده المشرف (supervisor/systemd)"
run php artisan queue:restart
run php artisan reverb:restart

if [ "$MAINT_ACTIVE" -eq 1 ]; then
  step "11) إخراج التطبيق من وضع الصيانة"
  run php artisan up
  MAINT_ACTIVE=0
fi

step "12) فحص دخان بعد النشر (GET فقط، بلا كتابة)"
SMOKE_ARGS=()
[ -n "${DEPLOY_SMOKE_URL:-}" ] && SMOKE_ARGS+=("--url=${DEPLOY_SMOKE_URL}")
[ -n "$RELEASE" ] && SMOKE_ARGS+=("--expect-release=${RELEASE}")
run php artisan release:smoke ${SMOKE_ARGS[@]+"${SMOKE_ARGS[@]}"}

step "13) فحص الإنتاج النهائي"
run php artisan production:check

echo
echo "اكتمل تسلسل النشر. راقب السجلات ولوحة العمليات 15-30 دقيقة (docs/operations-runbook.md)."
