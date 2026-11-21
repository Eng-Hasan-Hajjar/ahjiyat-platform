{{--
    مكوّن «قائمة منسدلة قابلة للبحث على الخادم» (Blade + Livewire + Alpine) — مشترك بين صفحات لوحة الإدارة وواجهة المنصة.

    متى يُستعمل؟ قوائم طويلة أو متزايدة (ملفات، أحجيات، مستخدمون…) مصدرها قاعدة البيانات وليست من حقول Filament.
    قوائم Filament تستعمل App\Filament\Support\SearchableOptions (بحث Select المدمج) بدل هذا المكوّن. والقوائم الصغيرة الثابتة (نعم/لا، حالات) تبقى <select> عادية.

    الاستعمال (يُضاف <x-searchable-select.assets /> مرة واحدة في أعلى الصفحة، خارج أي كتلة شرطية: Livewire لا ينفّذ <script> المحقون بعد التحميل الأول):

        <x-searchable-select id="cin-media-video" wire:model.live="sf.media.video"
            method="searchMedia" :context="['kind' => 'video']"
            :value="$sf['media']['video']" :selected="$descriptor" allow-none
            placeholder="ابحث باسم الملف…" remove-label="إزالة الوسيط من المشهد" />

    عقد دالة الخادم (Livewire، يُفضَّل #[Renderless]):  method(string $term, int $offset, string $current, array $context): array
        تُرجع ['items' => [...], 'total' => int, 'next' => ?int]، وكل عنصر: ['id','name','parts'=>[],'dist'=>[],'retired'=>bool,'current'=>bool,'note'=>?string].
        الدالة هي المسؤولة عن الصلاحية والتحقق وعدم كشف ما لا يحق للمستخدم رؤيته؛ وعن البحث قبل التحديد بعدد (انظر App\Support\Search\TextSearch).
        القيمة تُربط عبر wire:model (حقل مخفي)؛ الحفظ يبقى بزر الحفظ في الصفحة (الاختيار لا يحفظ شيئًا بنفسه).

    إتاحة الوصول: combobox (ARIA 1.2) بحقل بحث داخل اللوحة وقائمة listbox وaria-activedescendant ومنطقة status حيّة؛ الأسهم/Enter/Escape/Tab واللمس.
    الاتجاه: اللوحة تُعرض ضمن التدفق (لا تعويم مطلق) فلا تُقصّ داخل حاويات التمرير ولا على الجوال؛ الأسماء والأرقام معزولة (bdi/isolate).
--}}
@props([
    'method',
    'context' => [],
    'value' => '',
    'selected' => null,
    'allowNone' => false,
    'placeholder' => 'ابحث…',
    'none' => '— بلا —',
    'removeLabel' => 'مسح الاختيار',
    'emptyAll' => 'لا توجد عناصر متاحة.',
    'empty' => 'لا توجد نتائج تطابق «:term».',
    'nameDir' => 'ltr',
    'id' => null,
    'labelId' => null,
    'name' => null,
    'disabled' => false,
])
@php
    $model = $attributes->whereStartsWith('wire:model')->first();
    // x-data يجب أن يبقى نصًّا ثابتًا بين تصييرات Livewire: Alpine يعدّ تغيّر قيمة x-data (حتى لو أُعيد التصيير بالقيمة نفسها لمكوّن آخر)
    // إعادة تهيئة كاملة تمسح اللوحة المفتوحة والنتائج والاختيار. لذلك القيمة والوصف الحاليان يمرّان بسمتين data-* منفصلتين تُحدَّثان بالمقارنة (morph) دون إعادة تهيئة.
    $cfg = [
        'method' => $method,
        'context' => (object) $context,
        'allowNone' => (bool) $allowNone,
        'nameDir' => $nameDir === 'auto' ? 'auto' : 'ltr',
        'model' => $model,
        'text' => array_filter(['placeholder' => $placeholder, 'none' => $none, 'remove' => $removeLabel, 'emptyAll' => $emptyAll, 'empty' => $empty]),
    ];
@endphp
<div {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'ss-root']) }}
     x-data="ssSelect(@js($cfg))" data-ss-root data-ss-value="{{ $value }}" data-ss-selected="{{ json_encode($selected, JSON_UNESCAPED_UNICODE) }}"
     x-on:keydown.escape="if (open) { $event.stopPropagation(); close(true); }"
     x-on:click.outside="close(false)"
     x-on:focusin.window="if (open && !$root.contains($event.target)) { close(false); }">
    <input type="hidden" x-ref="input" value="{{ $value }}" @if ($name) name="{{ $name }}" @endif {{ $attributes->whereStartsWith('wire:model') }}>

    <div class="ss-field">
        <button type="button" class="ss-trigger" x-ref="trigger" @if ($id) id="{{ $id }}" @endif
                role="combobox" aria-haspopup="listbox" :aria-expanded="open ? 'true' : 'false'" :aria-controls="uid + '-panel'"
                @if ($labelId && $id) aria-labelledby="{{ $labelId }} {{ $id }}" @endif
                @disabled($disabled)
                x-on:click="toggle()"
                x-on:keydown.arrow-down.prevent="openList()" x-on:keydown.arrow-up.prevent="openList()">
            <span class="ss-trigger-text">
                <template x-if="cur()">
                    <span class="ss-trigger-text">
                        <span class="ss-name" :dir="nameDir" x-text="cur().name"></span>
                        <span class="ss-meta" x-show="cur().parts && cur().parts.length"><template x-for="(p, i) in cur().parts" :key="i"><span class="ss-part"><template x-for="(g, k) in segs(p)" :key="k"><bdi :dir="g.ltr ? 'ltr' : null" x-text="g.t"></bdi></template></span></template></span>
                        <span class="ss-dist" x-show="cur().dist && cur().dist.length"><span class="ss-part"><bdi x-text="T.dup"></bdi></span><template x-for="(p, i) in cur().dist" :key="'d' + i"><span class="ss-part"><template x-for="(g, k) in segs(p)" :key="k"><bdi :dir="g.ltr ? 'ltr' : null" x-text="g.t"></bdi></template></span></template></span>
                        <span class="ss-flag" x-show="cur().retired" x-text="cur().note || T.retired"></span>
                    </span>
                </template>
                <template x-if="!cur()"><span class="ss-placeholder" x-text="allowNone ? T.none : T.choose"></span></template>
            </span>
            <svg class="ss-chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 8l5 5 5-5"/></svg>
        </button>
        <button type="button" class="ss-btn" x-show="allowNone && value" x-cloak x-on:click="remove()" @disabled($disabled) data-ss-remove>
            <span x-text="T.remove"></span>
        </button>
    </div>

    <div class="ss-panel" x-show="open" x-cloak :id="uid + '-panel'" data-ss-panel>
        <div class="ss-search">
            <input type="text" x-ref="search" x-model="term" x-on:input="onInput()" x-on:keydown="onKey($event)"
                   role="combobox" aria-autocomplete="list" aria-expanded="true" :aria-controls="uid + '-list'" :aria-activedescendant="activeId()"
                   :aria-label="T.placeholder" :placeholder="T.placeholder" maxlength="80" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search"
                   data-no-dirty data-ss-search>
            <button type="button" class="ss-clear" x-show="term !== ''" x-cloak x-on:click="clearTerm()" :aria-label="T.clear" :title="T.clear" data-ss-clear>
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15"/></svg>
            </button>
        </div>

        <p class="ss-status" role="status" aria-live="polite" x-text="statusText()" data-ss-status></p>

        <div class="ss-error" role="alert" x-show="error" x-cloak data-ss-error>
            <span x-text="T.error"></span>
            <button type="button" class="ss-btn" x-on:click="retry()"><span x-text="T.retry"></span></button>
        </div>

        <ul class="ss-list" role="listbox" :id="uid + '-list'" x-ref="list" :aria-label="T.placeholder" x-on:mousedown.prevent data-ss-list>
            <template x-for="(row, i) in rows" :key="row.none ? '__none' : row.id">
                <li role="option" class="ss-opt" :id="uid + '-o' + i" :aria-selected="row.id === value ? 'true' : 'false'" :aria-label="label(row)"
                    :class="{ 'is-active': i === active, 'is-selected': row.id === value, 'is-none': row.none }"
                    x-on:click="pick(row)" x-on:mousemove="active = i" :data-ss-id="row.id" data-ss-option>
                    <template x-if="row.none"><span class="ss-name ss-none" x-text="T.none"></span></template>
                    <template x-if="!row.none">
                        <span class="ss-opt-body">
                            <span class="ss-name" :dir="nameDir" x-text="row.name"></span>
                            <span class="ss-meta" x-show="row.parts && row.parts.length"><template x-for="(p, k) in row.parts" :key="k"><span class="ss-part"><template x-for="(g, j) in segs(p)" :key="j"><bdi :dir="g.ltr ? 'ltr' : null" x-text="g.t"></bdi></template></span></template></span>
                            <span class="ss-dist" x-show="row.dist && row.dist.length" data-ss-dist><span class="ss-part"><bdi x-text="T.dup"></bdi></span><template x-for="(p, k) in row.dist" :key="'d' + k"><span class="ss-part"><template x-for="(g, j) in segs(p)" :key="j"><bdi :dir="g.ltr ? 'ltr' : null" x-text="g.t"></bdi></template></span></template></span>
                        </span>
                    </template>
                    <span class="ss-tags">
                        <span class="ss-tag" x-show="row.current && !row.none" x-text="T.current"></span>
                        <span class="ss-tag is-warn" x-show="row.retired" x-text="T.retired"></span>
                        <svg class="ss-check" x-show="row.id === value" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10.5l4 4 8-9"/></svg>
                    </span>
                </li>
            </template>
        </ul>

        {{-- يبقى ظاهرًا أثناء التحميل (aria-disabled لا disabled) كي لا يفقد المستخدم التركيز فيتعطّل Escape ولوحة المفاتيح. --}}
        <button type="button" class="ss-btn ss-more" x-show="next !== null" x-cloak x-on:click="loadMore()" :aria-disabled="loading ? 'true' : 'false'" data-ss-more><span x-text="T.more"></span></button>
    </div>
</div>
