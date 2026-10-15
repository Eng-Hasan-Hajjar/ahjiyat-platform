{{--
    E27: تراكب البحث العام (اقتراحات فورية). يعمل بـJavaScript فقط؛ بدونه يبقى زر الرأس رابطًا عاديًا إلى /search (نموذج GET كامل).
    - لا <form> هنا عمدًا (صفحات قراءة عامة كالمتجر تتحقق من غياب النماذج): الإدخال + أزرار، وEnter يفتح المحدَّد أو /search?q=.
    - Combobox/listbox: role=combobox بـaria-controls/aria-expanded/aria-activedescendant والخيارات role=option؛ التركيز يبقى بالحقل. Esc يغلق ويعيد التركيز لمن فتحه. Tab محصور بالحوار.
    - النصوص من JSON تُعرض بـx-text (textContent) لا HTML. z-60 (فوق التنقل السفلي z-50 ودون الحدّ 100).
    - جوال: شاشة كاملة؛ سطح المكتب: لوحة وسطية بحدّ أقصى 75vh. حركة مخفَّضة تُحترم (لا حركات هنا أصلًا).
--}}
<div x-data="globalSearch" data-suggest-url="{{ route('search.suggest', [], false) }}" data-search-url="{{ route('search.index', [], false) }}" data-min="{{ (int) config('search.min_length', 2) }}"
    @open-search.window="show()" @keydown.window="shortcut($event)" @keydown.escape.window="close()">
    <div x-show="open" x-cloak style="display: none" class="fixed inset-0 z-[60]">
        <div class="absolute inset-0 bg-black/60" @click="close()" aria-hidden="true"></div>

        <div x-ref="panel" role="dialog" aria-modal="true" aria-label="البحث في المنصة" @keydown.tab="trap($event)"
            class="relative mx-auto flex h-full w-full max-w-2xl flex-col overflow-hidden bg-night-900 border-white/10 shadow-2xl shadow-black/50 sm:mt-16 sm:h-auto sm:max-h-[75vh] sm:rounded-3xl sm:border">
            <div role="search" class="flex items-center gap-2 border-b border-white/10 p-3">
                <x-ui-icon name="search" class="w-5 h-5 shrink-0 text-slate-400 ms-1" />
                <input x-ref="input" x-model="q" type="search" maxlength="64" autocomplete="off" enterkeyhint="search" spellcheck="false"
                    role="combobox" aria-autocomplete="list" aria-controls="gs-listbox" aria-label="ابحث في المنصة" placeholder="ابحث عن أحجية أو فريق أو لاعب…"
                    :aria-expanded="(state === 'ok').toString()" :aria-activedescendant="activeId"
                    @input="onInput()" @compositionstart="composing = true" @compositionend="composing = false; onInput()"
                    @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter="enter($event)"
                    class="min-w-0 flex-1 rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white placeholder:text-slate-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                <button type="button" @click="go()" class="btn-gem !py-2 !px-4 text-sm shrink-0">بحث</button>
                <button type="button" @click="close()" aria-label="إغلاق البحث" title="إغلاق (Esc)"
                    class="grid place-items-center w-10 h-10 shrink-0 rounded-xl border border-white/10 bg-white/5 text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                    <x-ui-icon name="x" class="w-5 h-5" />
                </button>
            </div>

            <div class="flex-1 overflow-y-auto p-2 sm:min-h-[7rem]">
                <p class="sr-only" role="status" aria-live="polite" x-text="status"></p>

                <p x-show="state === 'idle'" class="px-3 py-8 text-center text-sm text-slate-400">ابدأ الكتابة لتظهر الاقتراحات فورًا. اضغط <kbd class="rounded border border-white/20 px-1.5 text-xs">/</kbd> في أي صفحة لفتح البحث. أو <a href="{{ route('discover.index', [], false) }}" class="font-bold text-amethyst hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">تصفّح مركز الاكتشاف</a>.</p>
                <p x-show="state === 'short'" class="px-3 py-8 text-center text-sm text-slate-400">اكتب حرفين على الأقل.</p>
                <p x-show="state === 'loading'" class="px-3 py-8 text-center text-sm text-slate-400">جارٍ البحث…</p>
                <p x-show="state === 'empty'" class="px-3 py-8 text-center text-sm text-slate-400">لا نتائج مطابقة. جرّب كلمة أخرى أو افتح البحث الكامل.</p>
                <p x-show="state === 'error'" class="px-3 py-8 text-center text-sm text-rose-400">تعذّر تحميل الاقتراحات. اضغط «بحث» لفتح صفحة البحث الكاملة.</p>
                <p x-show="state === 'throttled'" class="px-3 py-8 text-center text-sm text-gold">طلبات كثيرة خلال وقت قصير. انتظر لحظة ثم تابع الكتابة.</p>

                <div id="gs-listbox" role="listbox" aria-label="اقتراحات البحث" x-show="state === 'ok'">
                    <template x-for="(group, gi) in groups" :key="group.type">
                        <div role="group" :aria-label="group.label" class="mb-2">
                            <p class="px-3 pt-2 pb-1 text-[11px] font-black tracking-wide text-slate-500" x-text="group.label"></p>
                            <template x-for="(item, ii) in group.items" :key="item.url">
                                <a role="option" tabindex="-1" aria-label="نتيجة بحث" :aria-label="item.title" :href="item.url" :id="'gs-opt-' + indexOf(gi, ii)"
                                    :aria-selected="(active === indexOf(gi, ii)).toString()" :class="active === indexOf(gi, ii) ? 'bg-white/10' : ''"
                                    class="flex items-center gap-3 rounded-xl px-3 py-2.5 hover:bg-white/5 focus:outline-none">
                                    <span class="min-w-0 flex-1 text-start">
                                        <span class="block truncate text-sm font-black text-white" x-text="item.title"></span>
                                        <span x-show="item.subtitle" class="block truncate text-xs text-slate-400" x-text="item.subtitle"></span>
                                    </span>
                                    <span x-show="item.badge" class="shrink-0 rounded-full border border-white/10 bg-white/5 px-2 py-0.5 text-[11px] font-black text-slate-300" x-text="item.badge"></span>
                                </a>
                            </template>
                            <a x-show="group.more" :href="group.more" class="block rounded-lg px-3 py-1 text-xs font-bold text-amethyst hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">عرض كل النتائج في هذا القسم</a>
                        </div>
                    </template>
                    <p x-show="note" class="px-3 py-1 text-xs text-slate-500" x-text="note"></p>
                </div>

                <a x-show="state === 'ok' || state === 'empty'" :href="allUrl || '{{ route('search.index', [], false) }}'"
                    class="mt-1 block rounded-xl px-3 py-2.5 text-center text-sm font-bold text-amethyst hover:bg-white/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">فتح صفحة البحث الكاملة</a>
            </div>
        </div>
    </div>
</div>
