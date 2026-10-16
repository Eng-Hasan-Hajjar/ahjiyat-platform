@php
    /**
     * E28: حقول التأليف المشتركة بين الإنشاء والتعديل. $kind نوع الأحجية، $values القيم الحالية (من النسخة أو الافتراضية)، $categories التصنيفات، $locked=true للقراءة فقط.
     * القيم القديمة بعد فشل التحقق (old) تتقدّم على المخزَّنة. القوائم تُعرض سطرًا لكل عنصر. كل النصوص مهرَّبة؛ لا HTML من المبدع يُعرض.
     */
    $locked = $locked ?? false;
    $val = fn (string $key, $default = '') => old($key, $values[$key] ?? $default);
    $lines = function (string $key) use ($values) {
        $v = old($key, $values[$key] ?? []);

        return is_array($v) ? implode("\n", $v) : (string) $v;
    };
    $inputCls = 'w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst disabled:opacity-60';
    $l = config('creators.limits');
@endphp

<fieldset @disabled($locked) class="space-y-5">
    <div>
        <label for="title" class="block text-sm font-bold text-white mb-1">عنوان الأحجية</label>
        <input id="title" name="title" type="text" value="{{ $val('title') }}" required minlength="{{ $l['title_min'] }}" maxlength="{{ $l['title_max'] }}" autocomplete="off" class="{{ $inputCls }}">
        @error('title') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="puzzle_category_id" class="block text-sm font-bold text-white mb-1">التصنيف</label>
            <select id="puzzle_category_id" name="puzzle_category_id" required class="{{ $inputCls }}">
                <option value="">اختر التصنيف</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected((int) $val('puzzle_category_id', 0) === $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
            @error('puzzle_category_id') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="difficulty" class="block text-sm font-bold text-white mb-1">الصعوبة</label>
            <select id="difficulty" name="difficulty" required class="{{ $inputCls }}">
                @foreach (['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'] as $key => $label)
                    <option value="{{ $key }}" @selected($val('difficulty', 'easy') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            @error('difficulty') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label for="prompt" class="block text-sm font-bold text-white mb-1">نص الأحجية / السؤال</label>
        <textarea id="prompt" name="prompt" rows="4" required minlength="{{ $l['prompt_min'] }}" maxlength="{{ $l['prompt_max'] }}" class="{{ $inputCls }}">{{ $val('prompt') }}</textarea>
        <p class="text-xs text-slate-500 mt-1">نص عادي فقط؛ لا وسوم ولا أكواد.</p>
        @error('prompt') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
    </div>

    @if (in_array($kind, ['text', 'image', 'multiple_choice'], true))
        @if ($kind === 'multiple_choice')
            <div>
                <label for="choices" class="block text-sm font-bold text-white mb-1">الاختيارات (سطر لكل اختيار)</label>
                <textarea id="choices" name="choices" rows="5" class="{{ $inputCls }}" aria-describedby="choices-help">{{ $lines('choices') }}</textarea>
                <p id="choices-help" class="text-xs text-slate-500 mt-1">من {{ $l['choices_min'] }} إلى {{ $l['choices_max'] }} اختيارات مختلفة.</p>
                @error('choices') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
                @error('choices.*') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
            </div>
        @endif
        <div>
            <label for="answer" class="block text-sm font-bold text-white mb-1">الجواب الصحيح</label>
            <input id="answer" name="answer" type="text" value="{{ $val('answer') }}" required maxlength="{{ $l['answer_max'] }}" autocomplete="off" class="{{ $inputCls }}">
            <p class="text-xs text-slate-500 mt-1">{{ $kind === 'multiple_choice' ? 'اكتب الجواب مطابقًا تمامًا لأحد الاختيارات.' : 'يُقارَن بعد توحيد المسافات والتشكيل وحالة الأحرف.' }} لا يظهر الجواب لأي لاعب.</p>
            @error('answer') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>
    @endif

    @if ($kind === 'sequence')
        <div>
            <label for="items" class="block text-sm font-bold text-white mb-1">العناصر بالترتيب الصحيح (سطر لكل عنصر)</label>
            <textarea id="items" name="items" rows="6" class="{{ $inputCls }}" aria-describedby="items-help">{{ $lines('items') }}</textarea>
            <p id="items-help" class="text-xs text-slate-500 mt-1">من {{ $l['sequence_min'] }} إلى {{ $l['sequence_max'] }} عناصر مختلفة. نخلطها للاعب ويرتّبها.</p>
            @error('items') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
            @error('items.*') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>
    @endif

    @if ($kind === 'memory')
        <div>
            <label for="faces" class="block text-sm font-bold text-white mb-1">وجوه البطاقات (سطر لكل وجه)</label>
            <textarea id="faces" name="faces" rows="6" class="{{ $inputCls }}" aria-describedby="faces-help">{{ $lines('faces') }}</textarea>
            <p id="faces-help" class="text-xs text-slate-500 mt-1">من {{ $l['memory_min'] }} إلى {{ $l['memory_max'] }} وجوه مختلفة (كلمة أو رمز قصير)؛ يُنشأ لكل وجه زوج بطاقات.</p>
            @error('faces') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
            @error('faces.*') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>
    @endif

    <div>
        <label for="hint" class="block text-sm font-bold text-white mb-1">تلميح (اختياري)</label>
        <input id="hint" name="hint" type="text" value="{{ $val('hint') }}" maxlength="{{ $l['hint_max'] }}" autocomplete="off" class="{{ $inputCls }}">
        <p class="text-xs text-slate-500 mt-1">يظهر للاعب عند طلبه، ومجانًا.</p>
        @error('hint') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
    </div>

    @if ($kindConfig['media'] ?? false)
        <div>
            <label for="image" class="block text-sm font-bold text-white mb-1">صورة الأحجية</label>
            @if (! empty($imageUrl))
                <img src="{{ $imageUrl }}" alt="الصورة الحالية" class="max-h-48 rounded-xl border border-white/10 mb-2">
            @endif
            <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-slate-300 file:me-3 file:rounded-lg file:border-0 file:bg-amethyst/20 file:px-4 file:py-2 file:text-sm file:font-bold file:text-amethyst" aria-describedby="image-help">
            <p id="image-help" class="text-xs text-slate-500 mt-1">JPEG أو PNG أو WEBP، حتى {{ round(config('creators.media.max_kb') / 1024) }} ميغابايت، بأبعاد من {{ config('creators.media.min_dimension') }} إلى {{ config('creators.media.max_dimension') }} بكسل. لا SVG. الصورة مطلوبة قبل الإرسال.</p>
            @error('image') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>
    @endif
</fieldset>
