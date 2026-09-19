import Alpine from 'alpinejs';
import sequenceGame from './games/renderers/sequence.js';
import memoryGame from './games/renderers/memory.js';
import spotDifferenceGame from './games/renderers/spot-difference.js';

window.Alpine = Alpine;

Alpine.data('sequenceGame', sequenceGame);
Alpine.data('memoryGame', memoryGame);
Alpine.data('spotDifferenceGame', spotDifferenceGame);

/**
 * مبدّل الثيم (E4) - data-theme على <html> هو مصدر الحقيقة الوحيد للعرض؛
 * localStorage فقط لتذكّر اختيار المستخدم بزيارته القادمة. لا Cookie، لا DB.
 */
Alpine.data('themeSwitcher', () => ({
    isLight: document.documentElement.getAttribute('data-theme') === 'light',

    toggle() {
        this.isLight = !this.isLight;
        const mode = this.isLight ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', mode);
        try {
            localStorage.setItem('ahjiyat-theme', mode);
        } catch (e) {
            // localStorage غير متاحة (وضع خاص مثلاً) - التبديل البصري يبقى يعمل لهذه الجلسة فقط
        }
    },
}));

Alpine.start();