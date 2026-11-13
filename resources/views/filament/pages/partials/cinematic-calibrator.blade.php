{{--
    CIN-1 UX - المعايرة البصرية لمناطق التفاعل (Alpine: cinCalibrator في cinematic-admin-scripts).
    المتغيرات: $format ('areas' للنقاط الساخنة/الأبواب | 'hidden' لمخفيات المصباح)، $source (id الحقل النصي الأصلي)، $editing (بيانات المشهد)، $pickedPoster، $prefix، $canEdit.

    - الإطار بنسبة أبعاد الصورة الحقيقية (من فحص الرفع، ثم من تحميل الصورة نفسها): النسب المئوية تُقاس من الصورة لا من إطار ثابت، فلا انزياح عند تغيير الشاشة.
    - wire:ignore: Livewire لا يلمس هذا الجزء أثناء إعادة الرسم (حالة السحب تبقى)، ويُعاد إنشاؤه فقط عند تغيير الصورة المختارة.
    - يكتب في الحقل النصي الأصلي بصيغته نفسها ويُبلغ Livewire، فيُحفظ مع المشهد بنفس مسار الحفظ والتحقق الحالي (لا مسار حفظ جديد).
--}}
@php
    $calW = $editing['imageWidth'] ?? null;
    $calH = $editing['imageHeight'] ?? null;
    $calRatio = $calW && $calH ? round($calW / $calH, 5) : 'null';
@endphp
<div wire:ignore wire:key="cal-{{ $format }}-{{ $editingScene }}-{{ $pickedPoster }}" class="cin-cal" data-calibrator="{{ $format }}"
     x-data="cinCalibrator({ source: @js($source), format: @js($format), prefix: @js($prefix), ratio: {{ $calRatio }}, width: {{ (int) $calW }}, height: {{ (int) $calH }}, canEdit: @js((bool) $canEdit) })"
     style="display:flex;flex-direction:column;gap:.6rem">
    <p class="cin-help">
        @if ($format === 'hidden')
            مواضع المخفيات (الحل) فوق صورة المشهد: لا تظهر للاعبين.
        @else
            حرّك المنطقة أو غيّر حجمها فوق الصورة مباشرة، أو انقر على الصورة لوضع مركز المنطقة المحددة عند النقطة (مناسب للمقابض الصغيرة). أسهم لوحة المفاتيح تحرّك المنطقة المحددة (Shift أسرع، Alt+الأسهم للحجم).
        @endif
        النسب مقيسة من الصورة نفسها (0–100%).
    </p>

    <template x-if="canEdit">
        <div class="cin-row" data-no-dirty>
            <label class="cin-field" style="flex:1 1 12rem">المنطقة المحددة
                <select x-model.number="active" x-on:change="select(Number($event.target.value))" data-cal-select>
                    <template x-for="(a, i) in areas" :key="i"><option :value="i" :selected="i === active" x-text="(i + 1) + '. ' + (a.label || a.id)"></option></template>
                </select>
            </label>
            <button type="button" class="cin-btn cin-btn-sm" x-on:click="add()" data-cal-add>إضافة منطقة</button>
            <button type="button" class="cin-btn cin-btn-sm cin-btn-danger" x-on:click="remove()" :disabled="!box" data-cal-remove>حذف المنطقة المحددة</button>
        </div>
    </template>

    <template x-if="canEdit && box">
        <div class="cin-grid cin-grid-3" data-cal-fields>
            <label class="cin-field">المعرّف<input type="text" x-model="box.id" x-on:input="commit()" :readonly="!box.fresh" dir="ltr"><small x-show="!box.fresh">المعرّف ثابت لأن الحل يشير إليه.</small></label>
            <label class="cin-field" x-show="hasLabel">الاسم الظاهر<input type="text" x-model="box.label" x-on:input="commit()"></label>
            <label class="cin-field">x (يسار %)<input type="number" step="0.1" min="0" max="100" x-model.number="box.x" x-on:change="numberChanged()" dir="ltr"></label>
            <label class="cin-field">y (أعلى %)<input type="number" step="0.1" min="0" max="100" x-model.number="box.y" x-on:change="numberChanged()" dir="ltr"></label>
            <label class="cin-field">العرض %<input type="number" step="0.1" min="1" max="100" x-model.number="box.w" x-on:change="numberChanged()" dir="ltr"></label>
            <label class="cin-field">الارتفاع %<input type="number" step="0.1" min="1" max="100" x-model.number="box.h" x-on:change="numberChanged()" dir="ltr"></label>
        </div>
    </template>

    <div class="cin-cal-stage" x-ref="stage" :style="'aspect-ratio:' + ratio" dir="ltr" data-cal-stage
         x-on:pointerdown="canEdit && stageDown($event)" x-on:pointermove="move($event)" x-on:pointerup="up()" x-on:pointercancel="up()">
        <img src="{{ $editing['imageUrl'] }}" alt="" draggable="false" x-on:load="imageLoaded($event)">
        <template x-for="(a, i) in areas" :key="i">
            <div class="cin-cal-box" :class="[i === active ? 'is-active' : '', small(a) ? 'is-small' : '']" :style="style(a)" :tabindex="canEdit ? 0 : -1" role="button" :aria-label="'منطقة ' + (a.label || a.id)" :data-area="a.id"
                 x-on:pointerdown="canEdit && down($event, i, 'move')" x-on:keydown="canEdit && key($event, i)" x-on:focus="select(i)">
                <span class="cin-cal-tag" x-text="a.label || a.id"></span>
                <template x-if="canEdit"><template x-for="h in ['nw','n','ne','e','se','s','sw','w']" :key="h"><span class="cin-cal-handle" :data-h="h" x-on:pointerdown="down($event, i, h)"></span></template></template>
            </div>
        </template>
    </div>

    <p class="cin-help" data-cal-info>
        <span x-show="ratioText" x-text="'أبعاد الصورة: ' + ratioText + ' — '"></span>
        <span x-show="isWide">نسبة 16:9 ✓</span>
        <span x-show="!isWide" x-cloak>الصورة ليست 16:9 تمامًا: تُعرض للاعبين بنسبتها الحقيقية فتتطابق المناطق معها، لكن يُفضّل اعتماد صور 16:9 لتطابق الفيديو.</span>
    </p>
    <p class="cin-note is-ok" x-show="hint" x-cloak x-text="hint" role="status"></p>
</div>
