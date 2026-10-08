{{--
    مبدّل ثيم بسيط (فاتح/داكن) - يعمل فقط إذا Admin فعَّل allow_theme_switch.
    يقرأ/يكتب data-theme على <html> مباشرة + localStorage - لا Cookie، لا DB.
--}}
<button
    type="button"
    x-data="themeSwitcher()"
    @click="toggle()"
    title="تبديل المظهر" class="grid place-items-center w-10 h-10 rounded-xl border border-white/10 bg-white/5 text-white shrink-0 hover:border-amethyst/40 transition motion-reduce:transition-none focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"
    :aria-label="isLight ? 'التبديل للوضع الداكن' : 'التبديل للوضع الفاتح'"
    aria-live="polite"
>
    <svg x-show="!isLight" aria-hidden="true" focusable="false" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
    </svg>
    <svg x-show="isLight" x-cloak aria-hidden="true" focusable="false" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
    </svg>
</button>