{{--
    CIN-2 - ورقة الأنواع الجديدة: answer (إجابة) / inspect (ملاحظات الفحص) / multi_lock (الخزانة ذات القفلين) + نص الكشف بعد النجاح.
    تُضمَّن داخل .imm-paper-body في مكوّن Alpine cinematicPlayer. كل النصوص بـx-text (نصًّا خامًّا). التلميحات تأتي من الخادم عبر result.hint (تُعرض في طبقة التغذية الراجعة).
--}}
@verbatim
<!-- إجابة رقمية/نصية -->
<template x-if="kind === 'answer'">
    <form class="imm-lines cin2-answer" x-on:submit.prevent="submitAnswer()" data-cin2-answer>
        <template x-if="ix.facts && ix.facts.length">
            <dl class="cin2-facts" data-cin2-facts>
                <template x-for="(f, n) in ix.facts" :key="n"><div><dt x-text="f.label"></dt><dd x-text="f.value"></dd></div></template>
            </dl>
        </template>
        <label class="cin2-field">
            <span class="sr-only">إجابتك</span>
            <span class="cin2-inputrow">
                <input type="text" class="imm-input cin2-answer-input" x-model="answer" maxlength="80" autocomplete="off" autocapitalize="off" spellcheck="false" dir="auto" :inputmode="ix.input === 'number' ? 'decimal' : 'text'" :placeholder="ix.placeholder || 'اكتب إجابتك'" :disabled="busy || doneNow" data-cin2-answer-input>
                <span class="cin2-unit" x-show="ix.unit_label" x-text="ix.unit_label"></span>
            </span>
        </label>
        <div class="imm-ls"><button type="submit" class="imm-btn imm-btn-primary" :disabled="busy || doneNow || !String(answer || '').trim()" data-cin2-answer-submit>تحقق</button></div>
    </form>
</template>

<!-- فحص الصورة: عدّاد، ملاحظات ما فُحص، وإنهاء الفحص -->
<template x-if="kind === 'inspect'">
    <div class="imm-lines cin2-inspect-paper" data-cin2-inspect-paper>
        <p class="imm-note" data-cin2-inspect-count x-text="'التفاصيل التي فحصتها: ' + inspected.length + ' / ' + inspectNeeded"></p>
        <ul class="cin2-notes" aria-live="polite" aria-label="ملاحظاتك" data-cin2-notes>
            <template x-for="n in inspectedNotes" :key="n.id"><li :class="seenId === n.id ? 'is-last' : ''"><b x-text="n.label"></b><span x-show="n.note" x-text="n.note"></span></li></template>
        </ul>
        <!-- بديل لوحة المفاتيح وقارئ الشاشة: مناطق الفحص كأزرار (تظهر عند التركيز فقط) -->
        <ul class="imm-kbd" aria-label="مناطق الفحص (بديل لوحة المفاتيح وقارئ الشاشة)">
            <template x-for="a in ix.areas" :key="'k' + a.id"><li><button type="button" class="imm-chip" x-on:click="markInspected(a.id)" :disabled="busy || doneNow" x-text="a.label"></button></li></template>
            <template x-if="ix.back"><li><button type="button" class="imm-chip" x-on:click="flipPhoto()" :disabled="busy || doneNow" x-text="ix.back.label"></button></li></template>
        </ul>
        <div class="imm-ls"><button type="button" class="imm-btn imm-btn-primary" x-on:click="finishInspect()" :disabled="busy || doneNow || !inspectReady" data-cin2-inspect-finish>أنهيت الفحص</button></div>
    </div>
</template>

<!-- الخزانة ذات الأقفال: قفل لكل بطاقة، أرقام وحروف، وعلامة لكل قفل بعد الإرسال (من الخادم) -->
<template x-if="kind === 'multi_lock'">
    <form class="imm-lines cin2-locks" x-on:submit.prevent="submitLocks()" data-cin2-locks>
        <template x-for="lk in ix.locks" :key="lk.id">
            <fieldset class="cin2-lock" :class="lockClass(lk.id)" :data-lock="lk.id" :disabled="busy || doneNow">
                <legend x-text="lk.label"></legend>
                <div class="cin2-segs">
                    <template x-for="(sg, si) in lk.segments" :key="si">
                        <div class="cin2-seg">
                            <template x-if="sg.type === 'digits'">
                                <input type="text" inputmode="numeric" autocomplete="off" dir="ltr" class="cin2-cell" :maxlength="sg.length" :style="'--n:' + sg.length" :value="lockVal(lk.id, si)" :aria-label="lk.label + ': ' + sg.length + ' أرقام'" x-on:input="onDigits(lk.id, si, sg.length, $event)" data-cin2-digits>
                            </template>
                            <template x-if="sg.type === 'letter'">
                                <div class="cin2-letters" role="group" :aria-label="lk.label + ': اختر حرفًا'">
                                    <template x-for="o in sg.options" :key="o"><button type="button" class="imm-chip cin2-letter" :class="lockVal(lk.id, si) === o ? 'is-on' : ''" :aria-pressed="lockVal(lk.id, si) === o ? 'true' : 'false'" x-on:click="setLockVal(lk.id, si, o)" x-text="o" data-cin2-letter></button></template>
                                </div>
                            </template>
                        </div>
                    </template>
                    <span class="cin2-lockmark" x-show="lockMark[lk.id] !== undefined" aria-hidden="true" x-text="lockMark[lk.id] === true ? '✓' : '✗'"></span>
                </div>
            </fieldset>
        </template>
        <div class="imm-ls">
            <button type="submit" class="imm-btn imm-btn-primary" :disabled="busy || doneNow || !locksReady" data-cin2-locks-submit>افتح</button>
            <button type="button" class="imm-btn imm-btn-sm" x-show="evidenceList.length" x-on:click="openEvidence()" data-cin2-open-evidence>سجل الأدلة</button>
        </div>
    </form>
</template>

<!-- نص الكشف بعد النجاح (من الخادم فقط) للأنواع الجديدة -->
<template x-if="revealed && (kind === 'answer' || kind === 'inspect' || kind === 'multi_lock')">
    <p class="cin2-reveal" data-cin2-reveal x-text="revealed"></p>
</template>
@endverbatim
