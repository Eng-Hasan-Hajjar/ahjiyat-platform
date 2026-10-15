#!/usr/bin/env bash
# =============================================================================
# E25 - استعادة نسخة الملفات (files-backup.sh) إلى مجلد *فارغ* منفصل. لا تستبدل مجلد الإنتاج الحي افتراضيًا.
#
# المدخلات: RESTORE_FILES_TARGET (إلزامي: مجلد هدف فارغ أو غير موجود)، RESTORE_AGE_IDENTITY (للملفات .age)
# الاستعمال: bash tools/backup/files-restore.sh [--dry-run] [--allow-production] <ملف-النسخة>
# الرفض (exit 3): APP_ENV=production، أو الهدف داخل storage/app/public الحي/مطابق له، أو الهدف غير فارغ.
# تجاوز الإنتاج: --allow-production + RESTORE_CONFIRM_TARGET=<مسار الهدف بالضبط>.
# =============================================================================
set -Eeuo pipefail
umask 077

DRY_RUN=0
ALLOW_PRODUCTION=0
FILE=""
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --allow-production) ALLOW_PRODUCTION=1 ;;
    -h|--help) sed -n '2,9p' "$0"; exit 0 ;;
    -*) echo "خيار غير معروف: $arg" >&2; exit 2 ;;
    *) [ -z "$FILE" ] && FILE="$arg" || { echo "وسيط زائد: $arg" >&2; exit 2; } ;;
  esac
done

die() { echo "خطأ: $1" >&2; exit "${2:-2}"; }
refuse() { echo "رُفضت الاستعادة: $*" >&2; exit 3; }

[ -n "$FILE" ] || die "مرّر ملف النسخة كوسيط"
[ -f "$FILE" ] || die "ملف النسخة غير موجود"
: "${RESTORE_FILES_TARGET:?RESTORE_FILES_TARGET إلزامي}"

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
LIVE="$APP_DIR/storage/app/public"
TARGET="${RESTORE_FILES_TARGET%/}"
TARGET_ABS="$(cd "$TARGET" 2>/dev/null && pwd || echo "$TARGET")"

REASONS=()
[ "${APP_ENV:-}" = "production" ] && REASONS+=("APP_ENV=production بهذه البيئة")
case "$TARGET_ABS" in "$LIVE"|"$LIVE"/*) REASONS+=("الهدف هو مجلد الملفات الحي أو داخله") ;; esac

if [ "${#REASONS[@]}" -gt 0 ]; then
  [ "$ALLOW_PRODUCTION" -eq 1 ] || refuse "${REASONS[*]}. استعد إلى مجلد منفصل ثم انسخ بوعي."
  [ "${RESTORE_CONFIRM_TARGET:-}" = "$TARGET_ABS" ] || refuse "التجاوز يتطلب RESTORE_CONFIRM_TARGET مطابقًا تمامًا لمسار الهدف."
fi

if [ -d "$TARGET" ] && [ -n "$(ls -A "$TARGET" 2>/dev/null)" ]; then
  refuse "المجلد الهدف غير فارغ."
fi

CAT=(cat "$FILE")
case "$FILE" in
  *.age) command -v age >/dev/null 2>&1 || die "age غير موجود"; [ -f "${RESTORE_AGE_IDENTITY:-}" ] || die "RESTORE_AGE_IDENTITY مطلوب"; CAT=(age -d -i "$RESTORE_AGE_IDENTITY" "$FILE") ;;
  *.gpg) command -v gpg >/dev/null 2>&1 || die "gpg غير موجود"; CAT=(gpg --batch --quiet --decrypt "$FILE") ;;
esac

echo "الملف: $(basename "$FILE") → الهدف: $TARGET_ABS"

if [ "$DRY_RUN" -eq 1 ]; then
  echo "[dry-run] اجتازت كل حُرّاس الرفض. لم يُنفَّذ شيء."
  exit 0
fi

if [ -f "${FILE}.sha256" ]; then
  ( cd "$(dirname "$FILE")" && sha256sum -c "$(basename "$FILE").sha256" >/dev/null ) || die "فشل التحقق من checksum" 4
fi

mkdir -p "$TARGET"
"${CAT[@]}" | tar -xzf - -C "$TARGET"
echo "تمت الاستعادة إلى $TARGET_ABS. راجع المحتوى ثم انسخه إلى مكانه يدويًا إن لزم."
