#!/usr/bin/env bash
# =============================================================================
# E25 - استعادة نسخة قاعدة بيانات إلى بيئة *جديدة/نظيفة* للاختبار أو التعافي. تُرفض افتراضيًا أي استعادة تبدو موجَّهة للإنتاج الحي.
# docs/backup-disaster-recovery.md (دليل الاستعادة + تمرين الاستعادة ربع السنوي)
#
# المدخلات من البيئة (لا من سطر الأوامر):
#   RESTORE_DB_ENGINE     mysql|mariadb|pgsql                (إلزامي)
#   RESTORE_DB_DATABASE   اسم القاعدة الهدف (فارغة وموجودة)  (إلزامي)
#   RESTORE_DB_HOST RESTORE_DB_PORT RESTORE_DB_USERNAME
#   كلمة المرور: MYSQL_PWD أو PGPASSWORD أو RESTORE_DB_DEFAULTS_FILE (my.cnf 600) - لا كوسيط أبدًا
#   فك التشفير: RESTORE_AGE_IDENTITY (ملف مفتاح age) للملفات .age؛ gpg يستعمل حلقة المفاتيح المحلية للملفات .gpg
#   (للمقارنة بالقاعدة الحية، إن ضُبطت بنفس بيئة التطبيق: APP_ENV, DB_HOST, DB_DATABASE)
#
# الاستعمال: bash tools/backup/db-restore.sh [--dry-run] [--yes] [--allow-non-empty] [--allow-production] <ملف-النسخة>
#
# حُرّاس الرفض (exit 3) قبل أي اتصال بالقاعدة:
#   1) APP_ENV=production        2) اسم الهدف يشبه prod|production|live      3) الهدف هو نفسه قاعدة التطبيق الحية (DB_DATABASE + DB_HOST)
#   تجاوز أي منها يتطلب --allow-production + RESTORE_CONFIRM_DATABASE=<اسم القاعدة الهدف بالضبط>، ثم تأكيدًا بكتابة اسم القاعدة (إلا مع --yes).
#   4) القاعدة الهدف غير فارغة     (تجاوزه: --allow-non-empty) - الاستعادة لا تُجرى فوق بيانات موجودة افتراضيًا.
# =============================================================================
set -Eeuo pipefail
umask 077

DRY_RUN=0
ASSUME_YES=0
ALLOW_NON_EMPTY=0
ALLOW_PRODUCTION=0
FILE=""

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --yes) ASSUME_YES=1 ;;
    --allow-non-empty) ALLOW_NON_EMPTY=1 ;;
    --allow-production) ALLOW_PRODUCTION=1 ;;
    -h|--help) sed -n '2,22p' "$0"; exit 0 ;;
    -*) echo "خيار غير معروف: $arg" >&2; exit 2 ;;
    *) [ -z "$FILE" ] && FILE="$arg" || { echo "وسيط زائد: $arg" >&2; exit 2; } ;;
  esac
done

die() { echo "خطأ: $1" >&2; exit "${2:-2}"; }
refuse() { echo "رُفضت الاستعادة: $*" >&2; exit 3; }

[ -n "$FILE" ] || die "مرّر ملف النسخة كوسيط (انظر --help)"
[ -f "$FILE" ] || die "ملف النسخة غير موجود"

ENGINE="${RESTORE_DB_ENGINE:-}"
case "$ENGINE" in
  mysql|mariadb) ENGINE="mysql" ;;
  pgsql|postgres|postgresql) ENGINE="pgsql" ;;
  *) die "RESTORE_DB_ENGINE غير مضبوط/غير مدعوم (mysql|mariadb|pgsql)" ;;
esac
TARGET_DB="${RESTORE_DB_DATABASE:-}"
[ -n "$TARGET_DB" ] || die "RESTORE_DB_DATABASE إلزامي"
[[ "$TARGET_DB" =~ ^[A-Za-z0-9_.-]+$ ]] || die "اسم القاعدة الهدف يحتوي أحرفًا غير مسموحة"
TARGET_HOST="${RESTORE_DB_HOST:-}"

norm_host() {
  case "${1:-}" in ""|localhost|127.0.0.1|::1) echo "local" ;; *) echo "$1" | tr '[:upper:]' '[:lower:]' ;; esac
}

# ───── الحُرّاس (كلها قبل أي أمر قاعدة بيانات) ─────
REASONS=()
[ "${APP_ENV:-}" = "production" ] && REASONS+=("APP_ENV=production بهذه البيئة")
if [[ "$(echo "$TARGET_DB" | tr '[:upper:]' '[:lower:]')" =~ (prod|production|live) ]]; then
  REASONS+=("اسم القاعدة الهدف يشبه قاعدة إنتاج")
fi
if [ -n "${DB_DATABASE:-}" ] && [ "$TARGET_DB" = "$DB_DATABASE" ] && [ "$(norm_host "$TARGET_HOST")" = "$(norm_host "${DB_HOST:-}")" ]; then
  REASONS+=("الهدف هو نفسه قاعدة التطبيق الحية (DB_DATABASE على DB_HOST)")
fi

if [ "${#REASONS[@]}" -gt 0 ]; then
  if [ "$ALLOW_PRODUCTION" -ne 1 ]; then
    refuse "${REASONS[*]}. لن تُستعاد نسخة فوق إنتاج حي افتراضيًا. استعدها إلى بيئة نظيفة منفصلة (docs/backup-disaster-recovery.md)."
  fi
  if [ "${RESTORE_CONFIRM_DATABASE:-}" != "$TARGET_DB" ]; then
    refuse "تجاوز الإنتاج يتطلب RESTORE_CONFIRM_DATABASE مطابقًا تمامًا لاسم القاعدة الهدف."
  fi
fi

# ───── نوع الملف ─────
CAT=(cat "$FILE")
case "$FILE" in
  *.age)
    command -v age >/dev/null 2>&1 || die "age غير موجود"
    [ -n "${RESTORE_AGE_IDENTITY:-}" ] && [ -f "${RESTORE_AGE_IDENTITY}" ] || die "RESTORE_AGE_IDENTITY (ملف مفتاح age) مطلوب لفك التشفير"
    CAT=(age -d -i "$RESTORE_AGE_IDENTITY" "$FILE") ;;
  *.gpg)
    command -v gpg >/dev/null 2>&1 || die "gpg غير موجود"
    CAT=(gpg --batch --quiet --decrypt "$FILE") ;;
esac

echo "المشغّل: $ENGINE | الهدف: $TARGET_DB | الملف: $(basename "$FILE")"
echo "الخطة: [تحقق من الـchecksum إن وُجد] → [فحص أن الهدف فارغ] → [فك التشفير/الضغط] → [استعادة] → التالي: production:check ثم release:smoke على البيئة المستعادة"

if [ "$DRY_RUN" -eq 1 ]; then
  echo "[dry-run] اجتازت كل حُرّاس الرفض. لم يُنفَّذ شيء."
  exit 0
fi

if [ -f "${FILE}.sha256" ]; then
  ( cd "$(dirname "$FILE")" && sha256sum -c "$(basename "$FILE").sha256" >/dev/null ) || die "فشل التحقق من checksum - النسخة تالفة أو معدّلة" 4
  echo "checksum سليم."
else
  echo "تنبيه: لا ملف .sha256 بجوار النسخة - لم يُتحقَّق من سلامتها."
fi

case "$ENGINE" in
  mysql) command -v mysql >/dev/null 2>&1 || die "mysql (العميل) غير موجود" ;;
  pgsql) command -v psql >/dev/null 2>&1 || die "psql (العميل) غير موجود" ;;
esac

client_args() {
  case "$ENGINE" in
    mysql)
      local a=()
      [ -n "${RESTORE_DB_DEFAULTS_FILE:-}" ] && a+=("--defaults-extra-file=${RESTORE_DB_DEFAULTS_FILE}")
      [ -n "$TARGET_HOST" ] && a+=("--host=${TARGET_HOST}")
      [ -n "${RESTORE_DB_PORT:-}" ] && a+=("--port=${RESTORE_DB_PORT}")
      [ -n "${RESTORE_DB_USERNAME:-}" ] && a+=("--user=${RESTORE_DB_USERNAME}")
      printf '%s\0' "${a[@]+"${a[@]}"}" ;;
    pgsql)
      local a=()
      [ -n "$TARGET_HOST" ] && a+=("--host=${TARGET_HOST}")
      [ -n "${RESTORE_DB_PORT:-}" ] && a+=("--port=${RESTORE_DB_PORT}")
      [ -n "${RESTORE_DB_USERNAME:-}" ] && a+=("--username=${RESTORE_DB_USERNAME}")
      printf '%s\0' "${a[@]+"${a[@]}"}" ;;
  esac
}
ARGS=()
while IFS= read -r -d '' item; do ARGS+=("$item"); done < <(client_args)

# 4) الهدف يجب أن يكون فارغًا
if [ "$ENGINE" = mysql ]; then
  TABLES="$(mysql "${ARGS[@]+"${ARGS[@]}"}" -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()" "$TARGET_DB")"
else
  TABLES="$(psql "${ARGS[@]+"${ARGS[@]}"}" -d "$TARGET_DB" -At -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog','information_schema')")"
fi
if [ "${TABLES:-0}" != "0" ] && [ "$ALLOW_NON_EMPTY" -ne 1 ]; then
  refuse "القاعدة الهدف غير فارغة (${TABLES} جدول). استعمل قاعدة جديدة فارغة (أو --allow-non-empty عن وعي)."
fi

if [ "$ASSUME_YES" -ne 1 ]; then
  [ -t 0 ] || die "لا طرفية للتأكيد: مرّر --yes للتشغيل غير التفاعلي" 2
  read -r -p "ستُستعاد النسخة إلى القاعدة '${TARGET_DB}'. اكتب اسمها للمتابعة: " CONFIRM
  [ "$CONFIRM" = "$TARGET_DB" ] || refuse "التأكيد لا يطابق اسم القاعدة."
fi

echo "جارٍ الاستعادة …"
if [ "$ENGINE" = mysql ]; then
  "${CAT[@]}" | gunzip | mysql "${ARGS[@]+"${ARGS[@]}"}" "$TARGET_DB"
else
  "${CAT[@]}" | gunzip | psql "${ARGS[@]+"${ARGS[@]}"}" -d "$TARGET_DB" -v ON_ERROR_STOP=1 -q
fi

echo "تمت الاستعادة. التالي على البيئة المستعادة: ضبط الأسرار (بما فيها APP_KEY الأصلي)، php artisan production:check، php artisan release:preflight، ثم release:smoke."
