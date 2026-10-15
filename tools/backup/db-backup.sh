#!/usr/bin/env bash
# =============================================================================
# E25 - نسخة احتياطية منطقية لقاعدة البيانات (MySQL/MariaDB أو PostgreSQL). محايدة للمزوّد، بلا أسرار مضمَّنة، لا تعدّل القاعدة (قراءة فقط).
# docs/backup-disaster-recovery.md
#
# المدخلات من البيئة (لا من سطر الأوامر، كي لا تظهر في سجل العمليات):
#   BACKUP_DIR              (إلزامي) مجلد الإخراج؛ يجب أن يكون خارج public/ وخارج مجلد التطبيق المخدَّم
#   DB_CONNECTION           mysql|mariadb|pgsql  (أو BACKUP_DB_ENGINE)
#   DB_HOST DB_PORT DB_DATABASE DB_USERNAME
#   كلمة المرور: MYSQL_PWD أو PGPASSWORD بالبيئة، أو BACKUP_DB_DEFAULTS_FILE (ملف my.cnf بصلاحيات 600) - لا تُمرَّر كوسيط أبدًا
#   التشفير (إلزامي افتراضيًا): BACKUP_AGE_RECIPIENT (age -r) أو BACKUP_GPG_RECIPIENT (gpg -r). بدونهما يُرفض التشغيل إلا مع --allow-unencrypted
#   BACKUP_UPLOAD_CMD       (اختياري) أمر نسخ خارج الخادم (rclone/aws/scp… بحسب مزوّدك) يُنفَّذ عبر bash -c ويرى مسار الملف كـ "$1" (مثل: rclone copyto "$1" remote:bucket/)
#
# الخيارات: --dry-run (يطبع الخطة فقط)، --allow-unencrypted (غير مُوصى به)
# مثال: BACKUP_DIR=/var/backups/ahjiyat BACKUP_AGE_RECIPIENT=age1... DB_CONNECTION=mysql DB_DATABASE=ahjiyat DB_USERNAME=backup bash tools/backup/db-backup.sh
# =============================================================================
set -Eeuo pipefail
umask 077

DRY_RUN=0
ALLOW_UNENCRYPTED=0
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --allow-unencrypted) ALLOW_UNENCRYPTED=1 ;;
    -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
    *) echo "خيار غير معروف: $arg" >&2; exit 2 ;;
  esac
done

die() { echo "خطأ: $1" >&2; exit "${2:-2}"; }

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
ENGINE="${BACKUP_DB_ENGINE:-${DB_CONNECTION:-}}"
case "$ENGINE" in
  mysql|mariadb) ENGINE="mysql" ;;
  pgsql|postgres|postgresql) ENGINE="pgsql" ;;
  *) die "مشغّل القاعدة غير مدعوم أو غير مضبوط (DB_CONNECTION/BACKUP_DB_ENGINE = mysql|mariadb|pgsql)" ;;
esac

: "${BACKUP_DIR:?BACKUP_DIR إلزامي}"
: "${DB_DATABASE:?DB_DATABASE إلزامي}"
[[ "$DB_DATABASE" =~ ^[A-Za-z0-9_.-]+$ ]] || die "اسم القاعدة يحتوي أحرفًا غير مسموحة"

# لا نسخ داخل مجلدات تُخدَّم للعامة
case "$(cd "$BACKUP_DIR" 2>/dev/null && pwd || echo "$BACKUP_DIR")" in
  "$APP_DIR"/public|"$APP_DIR"/public/*) die "BACKUP_DIR داخل public/ - ستُخدَّم النسخة للعامة!" ;;
esac

ENCRYPT=none
if [ -n "${BACKUP_AGE_RECIPIENT:-}" ]; then ENCRYPT=age
elif [ -n "${BACKUP_GPG_RECIPIENT:-}" ]; then ENCRYPT=gpg
elif [ "$ALLOW_UNENCRYPTED" -ne 1 ]; then
  die "لا تشفير مضبوط (BACKUP_AGE_RECIPIENT أو BACKUP_GPG_RECIPIENT). النسخ غير المشفّرة مرفوضة افتراضيًا؛ استعمل --allow-unencrypted لتجربة محلية فقط" 3
fi

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${BACKUP_DIR%/}/db-${DB_DATABASE}-${STAMP}.sql.gz"
[ "$ENCRYPT" = age ] && OUT="${OUT}.age"
[ "$ENCRYPT" = gpg ] && OUT="${OUT}.gpg"

echo "المشغّل: $ENGINE | القاعدة: $DB_DATABASE | التشفير: $ENCRYPT"
echo "الإخراج: $OUT (+ .sha256)"
[ -n "${BACKUP_UPLOAD_CMD:-}" ] && echo "نسخ خارجي: سيُنفَّذ BACKUP_UPLOAD_CMD بعد النجاح" || echo "تنبيه: لا BACKUP_UPLOAD_CMD - نسخة على نفس الخادم فقط ليست نسخة احتياطية حقيقية (انسخها خارج نطاق الفشل)"

if [ "$DRY_RUN" -eq 1 ]; then
  echo "[dry-run] لم يُنفَّذ شيء."
  exit 0
fi

[ -d "$BACKUP_DIR" ] && [ -w "$BACKUP_DIR" ] || die "BACKUP_DIR غير موجود أو غير قابل للكتابة"
command -v gzip >/dev/null 2>&1 || die "gzip غير موجود"
case "$ENGINE" in
  mysql) command -v mysqldump >/dev/null 2>&1 || die "mysqldump غير موجود" ;;
  pgsql) command -v pg_dump >/dev/null 2>&1 || die "pg_dump غير موجود" ;;
esac
[ "$ENCRYPT" = age ] && { command -v age >/dev/null 2>&1 || die "age غير موجود"; }
[ "$ENCRYPT" = gpg ] && { command -v gpg >/dev/null 2>&1 || die "gpg غير موجود"; }

PARTIAL="${OUT}.partial"
trap 'rm -f "$PARTIAL"' ERR

dump() {
  case "$ENGINE" in
    mysql)
      local args=(--single-transaction --quick --routines --triggers --events --no-tablespaces --hex-blob --default-character-set=utf8mb4)
      [ -n "${BACKUP_DB_DEFAULTS_FILE:-}" ] && args=("--defaults-extra-file=${BACKUP_DB_DEFAULTS_FILE}" "${args[@]}")
      [ -n "${DB_HOST:-}" ] && args+=("--host=${DB_HOST}")
      [ -n "${DB_PORT:-}" ] && args+=("--port=${DB_PORT}")
      [ -n "${DB_USERNAME:-}" ] && args+=("--user=${DB_USERNAME}")
      mysqldump "${args[@]}" "$DB_DATABASE"
      ;;
    pgsql)
      local args=(--no-owner --no-privileges --format=plain)
      [ -n "${DB_HOST:-}" ] && args+=("--host=${DB_HOST}")
      [ -n "${DB_PORT:-}" ] && args+=("--port=${DB_PORT}")
      [ -n "${DB_USERNAME:-}" ] && args+=("--username=${DB_USERNAME}")
      pg_dump "${args[@]}" "$DB_DATABASE"
      ;;
  esac
}

case "$ENCRYPT" in
  age) dump | gzip -9 | age -r "$BACKUP_AGE_RECIPIENT" > "$PARTIAL" ;;
  gpg) dump | gzip -9 | gpg --batch --yes --encrypt --recipient "$BACKUP_GPG_RECIPIENT" --output "$PARTIAL" ;;
  none) dump | gzip -9 > "$PARTIAL" ;;
esac

[ -s "$PARTIAL" ] || die "الملف الناتج فارغ - فشلت النسخة" 4
[ "$ENCRYPT" = none ] && gzip -t "$PARTIAL"
mv "$PARTIAL" "$OUT"
( cd "$(dirname "$OUT")" && sha256sum "$(basename "$OUT")" > "$(basename "$OUT").sha256" )

echo "تمت النسخة: $(basename "$OUT") ($(wc -c < "$OUT") بايت)"

if [ -n "${BACKUP_UPLOAD_CMD:-}" ]; then
  bash -c "$BACKUP_UPLOAD_CMD" _ "$OUT"
  bash -c "$BACKUP_UPLOAD_CMD" _ "$OUT.sha256"
  echo "تم النسخ الخارجي."
fi

echo "تذكير: النسخة غير مفيدة حتى تُجرَّب استعادتها (tools/backup/db-restore.sh على بيئة نظيفة) - docs/backup-disaster-recovery.md"
