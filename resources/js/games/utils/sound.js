const cache = new Map();

// الأصوات اختيارية بالكامل - أي ملف mp3 غير موجود بـ public/game-assets/sounds
// يُتجاهل بصمت (catch) بدون كسر اللعبة إطلاقاً.
export function playSound(key, { muted = false } = {}) {
    if (muted) return;

    try {
        let audio = cache.get(key);
        if (!audio) {
            audio = new Audio(`/game-assets/sounds/${key}.mp3`);
            cache.set(key, audio);
        }
        audio.currentTime = 0;
        audio.play().catch(() => {});
    } catch {
        // بيئة بدون دعم صوت - تجاهل بأمان
    }
}