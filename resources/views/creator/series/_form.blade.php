@php
    /**
     * E30: نموذج السلسلة المشترك بين الإنشاء والتعديل. $works أعمال المبدع المؤهَّلة، $selected معرّفات العناصر بالترتيب، $current العناصر المحفوظة (لإظهار ما لم يعد ظاهرًا).
     * المنشئ بسيط وصالح للجوال: «أضف» من القائمة، ثم أزرار أعلى/أسفل/إزالة بحجم لمس مريح؛ الترتيب = ترتيب العناصر المرسلة (items[]). كل النصوص مهرَّبة.
     * $locked=true للقراءة فقط (قيد المراجعة/منشورة بلا نسخة تعديل).
     */
    $locked = $locked ?? false;
    $val = fn (string $key, $default = '') => old($key, $values[$key] ?? $default);
    $inputCls = 'w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst disabled:opacity-60';
    $btnCls = 'inline-flex items-center justify-center min-h-[44px] min-w-[44px] rounded-xl bg-white/5 border border-white/10 text-sm font-bold text-white hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst disabled:opacity-40';

    $worksData = collect($works)->map(fn ($w) => ['id' => $w->public_id, 'title' => $w->published_title, 'category' => $w->publishedCategory?->name, 'ok' => true])->all();
    $known = collect($worksData)->pluck('id')->all();
    foreach (($current ?? collect()) as $c) {
        if (! in_array($c->public_id, $known, true)) {
            $worksData[] = ['id' => $c->public_id, 'title' => $c->published_title, 'category' => null, 'ok' => false];
        }
    }
@endphp

<div x-data="{
        works: @js($worksData),
        selected: @js(array_values($selected)),
        max: {{ (int) $limits['max_items'] }},
        min: {{ (int) $limits['min_items'] }},
        meta(id) { return this.works.find(w => w.id === id) || { title: '', category: null, ok: false }; },
        add(id) { if (! this.selected.includes(id) && this.selected.length < this.max) { this.selected.push(id); } },
        remove(i) { this.selected.splice(i, 1); },
        up(i) { if (i > 0) { const t = this.selected[i - 1]; this.selected[i - 1] = this.selected[i]; this.selected[i] = t; } },
        down(i) { if (i < this.selected.length - 1) { const t = this.selected[i + 1]; this.selected[i + 1] = this.selected[i]; this.selected[i] = t; } },
        get available() { return this.works.filter(w => w.ok && ! this.selected.includes(w.id)); }
     }" class="space-y-5">
    <fieldset @disabled($locked) class="space-y-5">
        <div>
            <label for="title" class="block text-sm font-bold text-white mb-1">عنوان السلسلة</label>
            <input id="title" name="title" type="text" value="{{ $val('title') }}" required minlength="{{ $limits['title_min'] }}" maxlength="{{ $limits['title_max'] }}" autocomplete="off" class="{{ $inputCls }}">
            @error('title') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="description" class="block text-sm font-bold text-white mb-1">وصف قصير (اختياري)</label>
            <textarea id="description" name="description" rows="3" maxlength="{{ $limits['description_max'] }}" class="{{ $inputCls }}">{{ $val('description') }}</textarea>
            <p class="text-xs text-slate-500 mt-1">نص عادي فقط؛ لا وسوم ولا أكواد.</p>
            @error('description') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="cover" class="block text-sm font-bold text-white mb-1">غلاف (اختياري)</label>
            @if (! empty($coverUrl))
                <img src="{{ $coverUrl }}" alt="الغلاف الحالي" class="w-full max-w-xs rounded-xl border border-white/10 mb-2">
                <label class="inline-flex items-center gap-2 text-xs text-slate-300 mb-2"><input type="checkbox" name="remove_cover" value="1" class="rounded"> حذف الغلاف الحالي</label>
            @endif
            <input id="cover" name="cover" type="file" accept="image/jpeg,image/png,image/webp" class="{{ $inputCls }}">
            <p class="text-xs text-slate-500 mt-1">JPEG أو PNG أو WEBP فقط.</p>
            @error('cover') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <p class="block text-sm font-bold text-white mb-1">أحجيات السلسلة <span class="text-slate-400 font-normal" x-text="'(' + selected.length + ' من ' + max + '، الحد الأدنى ' + min + ')'"></span></p>
            <p class="text-xs text-slate-500 mb-3">من أحجياتك المنشورة والظاهرة فقط. رتّبها بالأسهم؛ هذا هو ترتيب اللعب.</p>

            <template x-for="id in selected" :key="'h' + id"><input type="hidden" name="items[]" :value="id"></template>

            <ol class="space-y-2 mb-4" data-series-selected>
                <template x-for="(id, i) in selected" :key="id">
                    <li class="glass rounded-2xl p-3 flex items-center gap-2">
                        <span class="grid place-items-center w-9 h-9 shrink-0 rounded-xl bg-amethyst/15 text-amethyst font-black" x-text="i + 1" aria-hidden="true"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-bold text-white break-words" x-text="meta(id).title"></span>
                            <span class="block text-xs text-rose" x-show="! meta(id).ok">غير ظاهرة حاليًا — أزلها قبل الإرسال.</span>
                        </span>
                        <button type="button" class="{{ $btnCls }}" @click="up(i)" :disabled="i === 0" aria-label="تقديم">↑</button>
                        <button type="button" class="{{ $btnCls }}" @click="down(i)" :disabled="i === selected.length - 1" aria-label="تأخير">↓</button>
                        <button type="button" class="{{ $btnCls }} !text-rose" @click="remove(i)" aria-label="إزالة">✕</button>
                    </li>
                </template>
            </ol>
            @error('items') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
            @error('items.*') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror

            <p class="text-sm font-bold text-white mb-2">أضف أحجية</p>
            <p class="text-xs text-slate-500" x-show="available.length === 0">لا توجد أحجيات منشورة إضافية متاحة.</p>
            <ul class="space-y-2" data-series-available>
                <template x-for="w in available" :key="w.id">
                    <li class="rounded-xl border border-white/10 bg-white/5 p-2 flex items-center gap-2">
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm text-white break-words" x-text="w.title"></span>
                            <span class="block text-[11px] text-slate-400" x-text="w.category"></span>
                        </span>
                        <button type="button" class="{{ $btnCls }} px-4" @click="add(w.id)" :disabled="selected.length >= max">إضافة</button>
                    </li>
                </template>
            </ul>
        </div>
    </fieldset>
</div>
