import Alpine from 'alpinejs';
import './echo.js';
import chatRoom from './chat.js';
import globalSearch from './search.js';
import sequenceGame from './games/renderers/sequence.js';
import memoryGame from './games/renderers/memory.js';
import spotDifferenceGame from './games/renderers/spot-difference.js';
import { seasonalBoard, seasonalVoter } from './seasonal.js';

window.Alpine = Alpine;

Alpine.data('sequenceGame', sequenceGame);
Alpine.data('memoryGame', memoryGame);
Alpine.data('spotDifferenceGame', spotDifferenceGame);
Alpine.data('chatRoom', chatRoom);
Alpine.data('globalSearch', globalSearch);
Alpine.data('seasonalBoard', seasonalBoard);
Alpine.data('seasonalVoter', seasonalVoter);

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

/**
 * E45: تطوّر AI - الزر العائم يُرسَم من الخادم؛ لوحة المحادثة (قالب + منطق) تُحمَّل كسولًا عند أول تفاعل (نقر/تركيز/مرور) أو حدث tatawwur:open
 * (يطلقه زر «اسأل تطوّر AI» في دليل المنصة). صفحات بلا زر لا تحمّل شيئًا منها.
 */
const tatawwurRoot = document.querySelector('[data-tatawwur-widget]');

if (tatawwurRoot) {
    let tatawwur = null;
    const launcher = tatawwurRoot.querySelector('[data-tatawwur-launcher]');
    const ensureTatawwur = () => (tatawwur ??= import('./tatawwur/widget.js').then((m) => m.mount(tatawwurRoot)));

    launcher?.addEventListener('click', () => ensureTatawwur().then((widget) => widget.toggle()));
    ['pointerenter', 'focus', 'touchstart'].forEach((type) => launcher?.addEventListener(type, () => { ensureTatawwur(); }, { once: true, passive: true }));
    window.addEventListener('tatawwur:open', () => ensureTatawwur().then((widget) => widget.open()));
}

Alpine.start();