{{--
    CIN-2 - مسرح «فحص الصورة» (inspect): صورة قابلة للتكبير والسحب والقرص باللمس، مع مناطق فحص بنقر/لمس، وقلب الصورة لعرض ظهرها (نص).
    يُضمَّن داخل .imm-stage في مكوّن Alpine cinematicPlayer. لا حل هنا: المناطق المطلوبة وهويتها على الخادم؛ ما يُرسَل هو ما فحصه اللاعب فقط.
    الإحداثيات فيزيائية (LTR) كما في بقية المسارح: النسب المئوية مقيسة من الصورة نفسها قبل التكبير.
--}}
@verbatim
<!-- صورة مرجعية لمشاهد «إجابة» و«خزانة متعددة الأقفال» (مرجع بصري فقط؛ بلا تدخل في الإرسال، وتُخفى إن تعذّر تحميلها). كتلة مستقلة عن كتلة الأنواع الأربعة القديمة كي لا يُمسّ عقدها. -->
<template x-if="scene.image && (kind === 'answer' || kind === 'multi_lock')">
    <div class="imm-art relative aspect-video rounded-2xl overflow-hidden border border-white/10 bg-slate-900 select-none" data-cin2-scene-image x-data="{ broken: false }" x-show="!broken">
        <img :src="scene.image" alt="" draggable="false" class="absolute inset-0 w-full h-full object-cover pointer-events-none" loading="lazy" x-on:error="broken = true">
    </div>
</template>

<template x-if="kind === 'inspect'">
    <div x-ref="inspectStage" class="cin-stage cin2-inspect" :class="[flipped ? 'is-flipped' : '', zs > 1.001 ? 'is-zoomed' : '', dragging ? 'is-dragging' : '', hoverId ? 'is-pointer' : '']" dir="ltr" :style="stageStyle" tabindex="0" role="application"
        :aria-label="ix.alt_text || 'صورة للفحص: كبّر الصورة وحرّكها وانقر على التفاصيل. الأزرار + و - للتكبير و 0 للإعادة.'" data-cin2-inspect-stage
        x-on:wheel.prevent="inspectWheel($event)" x-on:pointerdown="inspPointerDown($event)" x-on:pointermove="inspPointerMove($event)" x-on:pointerup="inspPointerUp($event)" x-on:pointercancel="inspPointerUp($event)"
        x-on:pointerleave="hoverId = null" x-on:dblclick.prevent="inspectDouble($event)" x-on:keydown="inspectKey($event)">
        <div class="cin2-zoom" :style="inspectStyle" data-cin2-zoom-layer>
            <template x-if="scene.image"><img :src="scene.image" :alt="ix.alt_text || ''" draggable="false" class="cin-stage-img" x-on:load="imgLoaded($event)" x-init="if ($el.complete && $el.naturalWidth) { imgLoaded({ target: $el }); }"></template>
            <template x-for="a in ix.areas" :key="'g' + a.id"><span class="cin-glow" :class="hoverId === a.id && !flipped ? 'on' : ''" :style="glowStyle(a)" aria-hidden="true"></span></template>
            <template x-for="a in ix.areas" :key="'s' + a.id"><span class="cin2-seen" x-show="inspected.indexOf(a.id) !== -1" :class="seenId === a.id ? 'is-last' : ''" :style="areaStyle(a)" aria-hidden="true"></span></template>
            <template x-for="a in zoneAreas" :key="'z' + a.id"><span class="imm-zone" :style="areaStyle(a)" aria-hidden="true" data-imm-zone><span x-text="a.label"></span></span></template>
        </div>

        <!-- ظهر الصورة (نص) عند القلب -->
        <template x-if="ix.back">
            <div class="cin2-back" x-show="flipped" x-cloak data-cin2-back>
                <div class="cin2-back-paper">
                    <p class="cin2-back-label" x-text="ix.back.label"></p>
                    <p class="cin2-back-text" x-text="ix.back.text"></p>
                </div>
            </div>
        </template>

        <!-- أدوات الفحص: لا تلتقط حركة السحب على الصورة -->
        <div class="cin2-tools" x-on:pointerdown.stop x-on:dblclick.stop x-on:wheel.stop data-cin2-tools dir="rtl">
            <button type="button" class="cin2-tool" x-on:click="zoomCenter(1.4)" :disabled="flipped || zs >= 4" aria-label="تكبير" data-cin2-zoom-in><span aria-hidden="true">＋</span></button>
            <button type="button" class="cin2-tool" x-on:click="zoomCenter(1 / 1.4)" :disabled="flipped || zs <= 1" aria-label="تصغير" data-cin2-zoom-out><span aria-hidden="true">－</span></button>
            <button type="button" class="cin2-tool" x-on:click="zoomReset()" :disabled="flipped || zs === 1" aria-label="إعادة الحجم الأصلي" data-cin2-zoom-reset><span aria-hidden="true">⤢</span></button>
            <template x-if="ix.back">
                <button type="button" class="cin2-tool cin2-tool-wide" :class="flipped ? 'is-on' : ''" x-on:click="flipPhoto()" :aria-pressed="flipped ? 'true' : 'false'" :disabled="doneNow" data-cin2-flip><span x-text="flipped ? 'اقلب للأمام' : 'اقلب الصورة'"></span></button>
            </template>
        </div>
    </div>
</template>
@endverbatim
