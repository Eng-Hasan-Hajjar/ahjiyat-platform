{{--
    CIN-1 IMMERSIVE - طبقة التغذية الراجعة (خطأ/ملاحظة محلية/نتيجة/عدّاد التقدّم التلقائي). تُضمَّن داخل مكوّن Alpine cinematicPlayer فقط (تستعمل حالته).
    تُعرض داخل الورقة لمشاهد الخيارات، وفي طبقة عائمة علوية لمشاهد الفيديو/الانتقال. كل النصوص بـx-text (نصًّا خامًّا لا HTML).
    رسالة النجاح: نص المؤلّف إن وُجد (حقل «رسالة النجاح» في المحرّر)، وإلا عبارة قصصية محايدة حسب نوع التفاعل (resultText).
--}}
@verbatim
<div class="imm-feedback" aria-live="polite" data-imm-feedback>
    <template x-if="error">
        <p class="imm-msg is-no" role="alert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9L2.4 18a2 2 0 001.7 3h15.8a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg><span x-text="error"></span></p>
    </template>
    <template x-if="nudge && !result">
        <p class="imm-msg is-hint" data-cinematic-nudge><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 110-15 7.5 7.5 0 010 15z"/></svg><span x-text="nudge"></span></p>
    </template>
    <template x-if="result">
        <p class="imm-msg" :class="result.correct ? (resultStory ? 'is-story' : 'is-ok') : (result.locked ? 'is-lock' : 'is-no')" data-cinematic-result>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="result.correct ? 'M5 13l4 4L19 7' : (result.locked ? 'M16.5 10.5V7a4.5 4.5 0 00-9 0v3.5M6 10.5h12v9H6z' : 'M6 18L18 6M6 6l12 12')"/></svg>
            <span>
                <span x-text="resultText"></span>
                <small x-show="result.attempts_left !== null && result.attempts_left !== undefined && !result.correct" x-text="'المحاولات المتبقية: ' + result.attempts_left"></small>
            </span>
        </p>
    </template>
    <template x-if="auto"><div class="imm-auto" aria-hidden="true"><span :style="'width:' + autoPct + '%'"></span></div></template>
</div>
@endverbatim
