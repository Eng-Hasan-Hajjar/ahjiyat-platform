{{--
    CIN-2 - «سجل الأدلة»: لوحة فوق المشغّل (لا تفتح صفحة جديدة ولا تعيد تحميل المشهد، فيبقى موضع اللاعب كما هو).
    القائمة من الخادم (مشاهد مكتملة فقط) في اللعب الحقيقي، ومن أدلة المشاهد السابقة في المعاينة. لا أدلة مستقبلية ولا حلول.
--}}
@verbatim
<section class="cin2-ev" x-show="evOpen" x-cloak role="dialog" aria-modal="true" aria-labelledby="cin2-ev-title" x-on:keydown.escape.window="evOpen && closeEvidence()" data-cin2-evidence>
    <div class="cin2-ev-card">
        <header class="cin2-ev-head">
            <h2 id="cin2-ev-title">سجل الأدلة</h2>
            <button type="button" class="imm-btn imm-btn-sm" x-ref="evClose" x-on:click="closeEvidence()" data-cin2-evidence-close>إغلاق</button>
        </header>
        <p class="imm-sub" x-show="!evidenceList.length">لم تكتشف أي دليل بعد.</p>
        <div class="cin2-ev-body" x-show="evidenceList.length">
            <ul class="cin2-ev-list" aria-label="الأدلة المكتشفة" data-cin2-evidence-list>
                <template x-for="(e, i) in evidenceList" :key="e.key">
                    <li><button type="button" :class="i === evIndex ? 'is-on' : ''" :aria-current="i === evIndex ? 'true' : 'false'" x-on:click="evIndex = i; evBig = false">
                        <span class="cin2-badge" x-show="e.clue" x-text="e.clue"></span><span x-text="e.title"></span></button></li>
                </template>
            </ul>
            <template x-if="evCurrent">
                <article class="cin2-ev-detail" data-cin2-evidence-detail>
                    <p class="cin2-ev-chapter" x-show="evCurrent.chapter" x-text="evCurrent.chapter"></p>
                    <h3 x-text="evCurrent.title"></h3>
                    <p class="imm-line" x-show="evCurrent.summary" x-text="evCurrent.summary"></p>
                    <template x-if="evCurrent.image">
                        <div class="cin2-ev-imgwrap" :class="evBig ? 'is-big' : ''" dir="ltr">
                            <div class="cin2-ev-imgbox">
                                <img :src="evCurrent.image" alt="" draggable="false" loading="lazy">
                                <template x-if="evCurrent.marker"><span class="cin2-marker" :style="markerStyle(evCurrent.marker)"><span x-show="evCurrent.marker.label" x-text="evCurrent.marker.label"></span></span></template>
                            </div>
                        </div>
                    </template>
                    <div class="imm-ls">
                        <button type="button" class="imm-btn imm-btn-sm" x-show="evCurrent.image" x-on:click="evBig = !evBig" :aria-pressed="evBig ? 'true' : 'false'" x-text="evBig ? 'تصغير الصورة' : 'تكبير الصورة'"></button>
                        <button type="button" class="imm-btn imm-btn-sm" x-on:click="evStep(-1)" :disabled="evIndex <= 0">الدليل السابق</button>
                        <button type="button" class="imm-btn imm-btn-sm" x-on:click="evStep(1)" :disabled="evIndex >= evidenceList.length - 1">الدليل التالي</button>
                    </div>
                </article>
            </template>
        </div>
    </div>
</section>
@endverbatim
