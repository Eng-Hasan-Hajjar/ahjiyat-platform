#!/usr/bin/env bash
# =============================================================================
# E25 - نسخة احتياطية للملفات الدائمة (الهوية البصرية، وسائط التجميليات، أي رفع دائم): storage/app/public افتراضيًا.
# لا تشمل storage/logs ولا storage/framework (كاش/جلسات/مترجمات: قابلة لإعادة البناء). محايدة للمزوّد، بلا أسرار.
# إن كانت الملفات على قرص سحابي (S3 وغيره) فاستعمل أداة المزوّد (versioning/replication) بدل هذا السكربت.
#
# المدخلات: BACKUP_DIR (إلزامي)، BACKUP_FILES_SOURCE (الافتراضي storage/app/public نسبةً لمجلد التطبيق)،
#           BACKUP_AGE_RECIPIENT | BACKUP_GPG_RECIPIENT (التشفير إلزامي إلا مع --allow-unencrypted)، BACKUP_UPLOAD_CMD (اختياري)
# الخيارات: --dry-run، --allow-unencrypted
# =============================================================================
set -Eeuo pipefail
umask 077

DRY_RUN=0
ALLOW_UNENCRYPTED=0
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --allow-unencrypted) ALLOW_UNENCRYPTED=1 ;;
    -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
    *) echo "خيار غير معروف: $arg" >&2; exit 2 ;;
  esac
done

die() { echo "خطأ: $1" >&2; exit "${2:-2}"; }

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
: "${BACKUP_DIR:?BACKUP_DIR إلزامي}"
SOURCE="${BACKUP_FILES_SOURCE:-storage/app/public}"
case "$SOURCE" in /*) ;; *) SOURCE="$APP_DIR/$SOURCE" ;; esac

case "$(cd "$BACKUP_DIR" 2>/dev/null && pwd || echo "$BACKUP_DIR")" in
  "$APP_DIR"/public|"$APP_DIR"/public/*|"$SOURCE"|"$SOURCE"/*) die "BACKUP_DIR داخل public/ أو داخل مصدر النسخ نفسه" ;;
esac

ENCRYPT=none
if [ -n "${BACKUP_AGE_RECIPIENT:-}" ]; then ENCRYPT=age
elif [ -n "${BACKUP_GPG_RECIPIENT:-}" ]; then ENCRYPT=gpg
elif [ "$ALLOW_UNENCRYPTED" -ne 1 ]; then
  die "لا تشفير مضبوط (BACKUP_AGE_RECIPIENT أو BACKUP_GPG_RECIPIENT). النسخ غير المشفّرة مرفوضة افتراضيًا" 3
fi

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${BACKUP_DIR%/}/files-${STAMP}.tar.gz"
[ "$ENCRYPT" = age ] && OUT="${OUT}.age"
[ "$ENCRYPT" = gpg ] && OUT="${OUT}.gpg"

echo "المصدر: $SOURCE | التشفير: $ENCRYPT"
echo "الإخراج: $OUT (+ .sha256)"
[ -n "${BACKUP_UPLOAD_CMD:-}" ] || echo "تنبيه: لا BACKUP_UPLOAD_CMD - نسخة على نفس الخادم فقط ليست نسخة احتياطية حقيقية"

if [ "$DRY_RUN" -eq 1 ]; then
  echo "[dry-run] لم يُنفَّذ شيء."
  exit 0
fi

[ -d "$SOURCE" ] || die "مجلد المصدر غير موجود: $SOURCE"
[ -d "$BACKUP_DIR" ] && [ -w "$BACKUP_DIR" ] || die "BACKUP_DIR غير موجود أو غير قابل للكتابة"

PARTIAL="${OUT}.partial"
trap 'rm -f "$PARTIAL"' ERR

pack() { tar -C "$SOURCE" -czf - .; }

case "$ENCRYPT" in
  age) command -v age >/dev/null 2>&1 || die "age غير موجود"; pack | age -r "$BACKUP_AGE_RECIPIENT" > "$PARTIAL" ;;
  gpg) command -v gpg >/dev/null 2>&1 || die "gpg غير موجود"; pack | gpg --batch --yes --encrypt --recipient "$BACKUP_GPG_RECIPIENT" --output "$PARTIAL" ;;
  none) pack > "$PARTIAL" ;;
esac

[ -s "$PARTIAL" ] || die "الملف الناتج فارغ - فشلت النسخة" 4
mv "$PARTIAL" "$OUT"
( cd "$(dirname "$OUT")" && sha256sum "$(basename "$OUT")" > "$(basename "$OUT").sha256" )

echo "تمت النسخة: $(basename "$OUT") ($(wc -c < "$OUT") بايت)"

if [ -n "${BACKUP_UPLOAD_CMD:-}" ]; then
  bash -c "$BACKUP_UPLOAD_CMD" _ "$OUT"
  bash -c "$BACKUP_UPLOAD_CMD" _ "$OUT.sha256"
  echo "تم النسخ الخارجي."
fi
