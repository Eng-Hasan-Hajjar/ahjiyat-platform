{{--
    CIN-1 UX - أنماط صفحات إدارة السلاسل السينمائية (المنشئ، المكتبة، القصص).

    لماذا هنا وليس ملف CSS عامًا؟
     - لوحة Filament في هذا المشروع بلا ثيم Vite مخصّص، فلا تُبنى فيها أي أصناف Tailwind خارج ما يستعمله Filament نفسه.
       لذلك تعتمد صفحات CIN-1 على أصناف `cin-*` معرَّفة هنا حصريًا، وكل قاعدة محصورة بالمحيط `.cin-admin`.
     - لا توجد أي قاعدة عامة على select/option/input خارج `.cin-admin`، فلا يتأثر أي جزء آخر من اللوحة.

    إصلاح ألوان القوائم (الأولوية القصوى):
     - السبب: الحقول كانت تأخذ خلفية شبه شفافة (rgba) ولون نص فاتح، ولا يكون للـ<option> خلفية خاصة؛ فتظهر قائمة المتصفح/النظام المنسدلة
       بخلفية بيضاء ونص أبيض (غير مقروء).
     - العلاج: ألوان صريحة مصمتة للحقل والخيار والخيار المحدد والمعطّل، مع color-scheme ليرسم المتصفح قائمته الأصلية بالسمة نفسها.

    الألوان رموز (tokens) في كتلتين: الفاتح (افتراضي) و`.dark` (لوحة الإدارة غامقة دائمًا). نسب التباين مفحوصة آليًا في اختبار CinUxContrastTest
    (النص العادي ≥ 4.5:1، حدود الحقول والتركيز ≥ 3:1) فلا تُغيَّر القيم دون إعادة الفحص.
--}}
<style data-cin-admin-styles>
.cin-admin {
    --cin-surface: #ffffff;
    --cin-surface-2: #f3f4f6;
    --cin-field: #ffffff;
    --cin-fg: #111827;
    --cin-muted: #4b5563;
    --cin-border: #6b7280;
    --cin-border-soft: #d1d5db;
    --cin-primary: #6d28d9;
    --cin-primary-fg: #ffffff;
    --cin-link: #5b21b6;
    --cin-danger: #9f1239;
    --cin-danger-bg: #ffe4e6;
    --cin-ok: #065f46;
    --cin-ok-bg: #d1fae5;
    --cin-warn: #78350f;
    --cin-warn-bg: #fef3c7;
    --cin-sel-bg: #5b21b6;
    --cin-sel-fg: #ffffff;
    --cin-sel-soft: #ede9fe;
    --cin-focus: #5b21b6;
    --cin-disabled-fg: #4b5563;
    --cin-disabled-bg: #e5e7eb;
    --cin-t-video-fg: #4c1d95; --cin-t-video-bg: #ede9fe;
    --cin-t-narrative-fg: #075985; --cin-t-narrative-bg: #e0f2fe;
    --cin-t-puzzle-fg: #78350f; --cin-t-puzzle-bg: #fef3c7;
    --cin-t-interaction-fg: #065f46; --cin-t-interaction-bg: #d1fae5;
    --cin-t-exploration-fg: #115e59; --cin-t-exploration-bg: #ccfbf1;
    --cin-t-transition-fg: #1f2937; --cin-t-transition-bg: #e5e7eb;
    color-scheme: light;
    color: var(--cin-fg);
    font-size: 0.9rem;
    line-height: 1.5;
}

.dark .cin-admin {
    --cin-surface: #111827;
    --cin-surface-2: #1f2937;
    --cin-field: #0b1220;
    --cin-fg: #f3f4f6;
    --cin-muted: #a8b1c0;
    --cin-border: #6b7280;
    --cin-border-soft: #374151;
    --cin-primary: #7c3aed;
    --cin-primary-fg: #ffffff;
    --cin-link: #c4b5fd;
    --cin-danger: #fda4af;
    --cin-danger-bg: #3b1219;
    --cin-ok: #6ee7b7;
    --cin-ok-bg: #052e24;
    --cin-warn: #fde68a;
    --cin-warn-bg: #3a2a05;
    --cin-sel-bg: #5b21b6;
    --cin-sel-fg: #ffffff;
    --cin-sel-soft: #2a1f4d;
    --cin-focus: #c4b5fd;
    --cin-disabled-fg: #9ca3af;
    --cin-disabled-bg: #1b2231;
    --cin-t-video-fg: #ddd6fe; --cin-t-video-bg: #2e1065;
    --cin-t-narrative-fg: #bae6fd; --cin-t-narrative-bg: #0c4a6e;
    --cin-t-puzzle-fg: #fde68a; --cin-t-puzzle-bg: #78350f;
    --cin-t-interaction-fg: #a7f3d0; --cin-t-interaction-bg: #064e3b;
    --cin-t-exploration-fg: #99f6e4; --cin-t-exploration-bg: #134e4a;
    --cin-t-transition-fg: #e5e7eb; --cin-t-transition-bg: #374151;
    color-scheme: dark;
}

.cin-admin *, .cin-admin *::before, .cin-admin *::after { box-sizing: border-box; }
.cin-admin { display: flex; flex-direction: column; gap: 1rem; min-width: 0; }
.cin-admin h2, .cin-admin h3, .cin-admin h4 { margin: 0; color: var(--cin-fg); }
.cin-admin p { margin: 0; }
.cin-admin ul, .cin-admin ol { margin: 0; padding: 0; list-style: none; }
.cin-admin a { color: var(--cin-link); }
.cin-admin .cin-muted { color: var(--cin-muted); }
.cin-admin .cin-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.cin-admin .cin-ltr { direction: ltr; unicode-bidi: isolate; }
.cin-admin [x-cloak] { display: none !important; }

/* ───────── الحقول (إصلاح ألوان القوائم): ألوان صريحة مصمتة، محصورة بـ.cin-admin ───────── */
.cin-admin :where(input:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=range]), select, textarea) {
    display: block;
    width: 100%;
    min-height: 2.5rem;
    padding: 0.5rem 0.75rem;
    font: inherit;
    font-size: 0.9rem;
    line-height: 1.4;
    color: var(--cin-fg);
    background-color: var(--cin-field);
    border: 1px solid var(--cin-border);
    border-radius: 0.5rem;
    box-shadow: none;
}
.cin-admin :where(select) { padding-inline-end: 2.25rem; cursor: pointer; }
.cin-admin :where(textarea) { min-height: 5rem; resize: vertical; }
.cin-admin select option, .cin-admin select optgroup {
    background-color: var(--cin-field);
    color: var(--cin-fg);
}
.cin-admin select option:checked { background-color: var(--cin-sel-bg); color: var(--cin-sel-fg); }
.cin-admin select option:disabled { color: var(--cin-disabled-fg); }
.cin-admin ::placeholder { color: var(--cin-muted); opacity: 1; }
.cin-admin :where(input, select, textarea):disabled,
.cin-admin fieldset:disabled :where(input, select, textarea) {
    color: var(--cin-disabled-fg);
    background-color: var(--cin-disabled-bg);
    cursor: not-allowed;
    opacity: 1;
}
.cin-admin :where(input, select, textarea, button, a, summary, [tabindex]):focus-visible {
    outline: 2px solid var(--cin-focus);
    outline-offset: 2px;
}
.cin-admin :where(input, select, textarea):focus { border-color: var(--cin-focus); }
.cin-admin input[type=checkbox], .cin-admin input[type=radio] {
    width: 1.1rem; height: 1.1rem; flex: none; accent-color: var(--cin-primary); cursor: pointer;
}
.cin-admin input[type=file] { color: var(--cin-fg); max-width: 100%; }
.cin-admin fieldset { border: 0; margin: 0; padding: 0; min-width: 0; }
.cin-admin legend { padding: 0 0.4rem; }

.cin-admin .cin-field { display: flex; flex-direction: column; gap: 0.3rem; min-width: 0; font-weight: 600; color: var(--cin-fg); }
.cin-admin .cin-field > small, .cin-admin .cin-help { color: var(--cin-muted); font-weight: 400; font-size: 0.78rem; }
.cin-admin .cin-check { display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: var(--cin-fg); }
.cin-admin .cin-error { color: var(--cin-danger); background: var(--cin-danger-bg); border-radius: 0.4rem; padding: 0.25rem 0.5rem; font-size: 0.8rem; }

/* ───────── البطاقات والتخطيط ───────── */
.cin-admin .cin-card { background: var(--cin-surface); border: 1px solid var(--cin-border-soft); border-radius: 0.75rem; padding: 1rem; min-width: 0; }
.cin-admin .cin-card > * + * { margin-top: 0.75rem; }
.cin-admin .cin-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
.cin-admin .cin-row-end { margin-inline-start: auto; }
.cin-admin .cin-grid { display: grid; gap: 0.75rem; grid-template-columns: minmax(0, 1fr); }
@media (min-width: 700px) {
    .cin-admin .cin-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .cin-admin .cin-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .cin-admin .cin-span-2 { grid-column: span 2; }
}
.cin-admin .cin-title { font-size: 1.05rem; font-weight: 800; }
.cin-admin .cin-section-title { font-size: 0.95rem; font-weight: 800; display: flex; align-items: center; gap: 0.4rem; }

/* ───────── الأزرار والشارات ───────── */
.cin-admin .cin-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem;
    min-height: 2.25rem; padding: 0.4rem 0.85rem; border-radius: 0.5rem;
    border: 1px solid var(--cin-border); background: var(--cin-surface-2); color: var(--cin-fg);
    font: inherit; font-size: 0.85rem; font-weight: 700; line-height: 1.2; cursor: pointer; text-decoration: none;
}
.cin-admin .cin-btn:hover { border-color: var(--cin-link); }
.cin-admin .cin-btn-primary { background: var(--cin-primary); border-color: var(--cin-primary); color: var(--cin-primary-fg); }
.cin-admin .cin-btn-primary:hover { filter: brightness(1.12); border-color: var(--cin-primary); }
.cin-admin .cin-btn-danger { color: var(--cin-danger); border-color: var(--cin-danger); background: transparent; }
.cin-admin .cin-btn-sm { min-height: 1.9rem; padding: 0.2rem 0.6rem; font-size: 0.8rem; }
.cin-admin .cin-btn[disabled], .cin-admin .cin-btn[aria-disabled=true] { opacity: 0.55; cursor: not-allowed; }
.cin-admin .cin-link-btn { background: none; border: 0; padding: 0.2rem 0.35rem; color: var(--cin-link); font: inherit; font-weight: 700; font-size: 0.8rem; text-decoration: underline; cursor: pointer; border-radius: 0.3rem; }
.cin-admin .cin-link-btn.is-danger { color: var(--cin-danger); }
.cin-admin .cin-badge { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: var(--cin-surface-2); color: var(--cin-fg); border: 1px solid var(--cin-border-soft); white-space: nowrap; }
.cin-admin .cin-badge svg { width: 0.9rem; height: 0.9rem; flex: none; }
.cin-admin .cin-badge.is-ok { color: var(--cin-ok); background: var(--cin-ok-bg); border-color: transparent; }
.cin-admin .cin-badge.is-warn { color: var(--cin-warn); background: var(--cin-warn-bg); border-color: transparent; }
.cin-admin .cin-badge.is-err { color: var(--cin-danger); background: var(--cin-danger-bg); border-color: transparent; }
.cin-admin .cin-badge.is-sel { background: var(--cin-sel-bg); color: var(--cin-sel-fg); border-color: transparent; }
.cin-admin .cin-t-video { color: var(--cin-t-video-fg); background: var(--cin-t-video-bg); border-color: transparent; }
.cin-admin .cin-t-narrative { color: var(--cin-t-narrative-fg); background: var(--cin-t-narrative-bg); border-color: transparent; }
.cin-admin .cin-t-puzzle { color: var(--cin-t-puzzle-fg); background: var(--cin-t-puzzle-bg); border-color: transparent; }
.cin-admin .cin-t-interaction { color: var(--cin-t-interaction-fg); background: var(--cin-t-interaction-bg); border-color: transparent; }
.cin-admin .cin-t-exploration { color: var(--cin-t-exploration-fg); background: var(--cin-t-exploration-bg); border-color: transparent; }
.cin-admin .cin-t-transition { color: var(--cin-t-transition-fg); background: var(--cin-t-transition-bg); border-color: transparent; }

.cin-admin .cin-note { border-radius: 0.6rem; padding: 0.6rem 0.8rem; font-size: 0.85rem; border: 1px solid var(--cin-border-soft); background: var(--cin-surface-2); color: var(--cin-fg); }
.cin-admin .cin-note.is-ok { background: var(--cin-ok-bg); color: var(--cin-ok); border-color: transparent; }
.cin-admin .cin-note.is-warn { background: var(--cin-warn-bg); color: var(--cin-warn); border-color: transparent; }
.cin-admin .cin-note.is-err { background: var(--cin-danger-bg); color: var(--cin-danger); border-color: transparent; }
.cin-admin .cin-note ul { list-style: disc; padding-inline-start: 1.25rem; }
.cin-admin .cin-note li + li { margin-top: 0.15rem; }

.cin-admin .cin-stat { display: flex; flex-direction: column; gap: 0.1rem; padding: 0.5rem 0.8rem; border-radius: 0.6rem; background: var(--cin-surface-2); border: 1px solid var(--cin-border-soft); min-width: 7rem; }
.cin-admin .cin-stat b { font-size: 1.15rem; color: var(--cin-fg); }
.cin-admin .cin-stat span { font-size: 0.75rem; color: var(--cin-muted); }

/* ───────── مساحة العمل: القائمة + لوحة المحرّر ───────── */
.cin-admin .cin-workspace { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1fr); align-items: start; }
@media (min-width: 1100px) {
    .cin-admin .cin-workspace { grid-template-columns: minmax(0, 5fr) minmax(0, 7fr); }
    .cin-admin .cin-editor-col { position: sticky; top: 4.75rem; max-height: calc(100vh - 5.75rem); overflow: auto; }
}
.cin-admin .cin-editor-col { min-width: 0; scroll-margin-top: 5rem; }
.cin-admin .cin-editor { border: 2px solid var(--cin-primary); border-radius: 0.75rem; background: var(--cin-surface); }
.cin-admin .cin-editor-head { position: sticky; top: 0; z-index: 6; background: var(--cin-surface); border-bottom: 1px solid var(--cin-border-soft); padding: 0.75rem 1rem; border-radius: 0.75rem 0.75rem 0 0; }
.cin-admin .cin-editor-head h3:focus { outline: none; }
.cin-admin .cin-editor-head h3:focus-visible { outline: 2px solid var(--cin-focus); outline-offset: 3px; border-radius: 0.3rem; }
.cin-admin .cin-editor-body { padding: 1rem; display: flex; flex-direction: column; gap: 1rem; }
.cin-admin .cin-block { border: 1px solid var(--cin-border-soft); border-radius: 0.6rem; padding: 0.8rem; display: flex; flex-direction: column; gap: 0.75rem; min-width: 0; }
.cin-admin .cin-block-title { font-weight: 800; font-size: 0.9rem; display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
.cin-admin .cin-block[data-solution-editor] { border-color: var(--cin-warn); background: var(--cin-warn-bg); color: var(--cin-warn); }
.cin-admin .cin-block[data-solution-editor] .cin-field { color: var(--cin-warn); }
.cin-admin .cin-savebar { position: sticky; bottom: 0; z-index: 6; display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; padding: 0.6rem 1rem; background: var(--cin-surface); border-top: 1px solid var(--cin-border); border-radius: 0 0 0.75rem 0.75rem; }
.cin-admin .cin-placeholder { border: 2px dashed var(--cin-border-soft); border-radius: 0.75rem; padding: 2rem 1rem; text-align: center; color: var(--cin-muted); }
@media (max-width: 1099px) { .cin-admin .cin-placeholder { display: none; } }

/* ───────── الشجرة: مراحل/فصول/مشاهد ───────── */
.cin-admin .cin-stage { border: 1px solid var(--cin-border-soft); border-radius: 0.75rem; padding: 0.75rem; background: var(--cin-surface); }
.cin-admin .cin-stage > * + * { margin-top: 0.6rem; }
.cin-admin .cin-chapter { border-radius: 0.6rem; padding: 0.6rem; background: var(--cin-surface-2); }
.cin-admin .cin-chapter > * + * { margin-top: 0.5rem; }
.cin-admin .cin-fold { display: inline-flex; align-items: center; gap: 0.4rem; background: none; border: 0; color: var(--cin-fg); font: inherit; font-weight: 800; cursor: pointer; text-align: start; padding: 0.2rem 0.3rem; border-radius: 0.4rem; }
.cin-admin .cin-fold svg { width: 1rem; height: 1rem; transition: transform 0.15s; flex: none; }
.cin-admin .cin-fold[aria-expanded=false] svg { transform: rotate(90deg); }
[dir=rtl] .cin-admin .cin-fold[aria-expanded=false] svg { transform: rotate(-90deg); }
.cin-admin .cin-scene {
    display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem 0.5rem; padding: 0.45rem 0.6rem;
    border: 1px solid var(--cin-border-soft); border-inline-start: 4px solid var(--cin-border); border-radius: 0.5rem;
    background: var(--cin-surface); scroll-margin-block: 6rem;
}
.cin-admin .cin-scene[data-type=video] { border-inline-start-color: var(--cin-t-video-fg); }
.cin-admin .cin-scene[data-type=narrative] { border-inline-start-color: var(--cin-t-narrative-fg); }
.cin-admin .cin-scene[data-type=puzzle] { border-inline-start-color: var(--cin-t-puzzle-fg); }
.cin-admin .cin-scene[data-type=interaction] { border-inline-start-color: var(--cin-t-interaction-fg); }
.cin-admin .cin-scene[data-type=exploration] { border-inline-start-color: var(--cin-t-exploration-fg); }
.cin-admin .cin-scene[data-type=transition] { border-inline-start-color: var(--cin-t-transition-fg); }
.cin-admin .cin-scene.is-selected { background: var(--cin-sel-soft); outline: 2px solid var(--cin-primary); outline-offset: 0; }
.cin-admin .cin-scene-name { background: none; border: 0; color: var(--cin-fg); font: inherit; font-weight: 800; cursor: pointer; text-align: start; padding: 0.15rem 0.2rem; border-radius: 0.3rem; max-width: 100%; overflow-wrap: anywhere; }
.cin-admin .cin-scene-name:hover { text-decoration: underline; }
.cin-admin .cin-key { font-size: 0.72rem; color: var(--cin-muted); direction: ltr; unicode-bidi: isolate; }
.cin-admin .cin-scene-actions { margin-inline-start: auto; display: flex; flex-wrap: wrap; align-items: center; gap: 0.15rem; }
.cin-admin .cin-more > summary { cursor: pointer; font-weight: 800; font-size: 0.8rem; color: var(--cin-link); padding: 0.25rem 0.1rem; }
.cin-admin .cin-more > summary:focus-visible { outline: 2px solid var(--cin-focus); outline-offset: 2px; border-radius: 0.3rem; }
.cin-admin .cin-add-scene { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: flex-end; }
.cin-admin .cin-add-scene .cin-field { flex: 1 1 9rem; }

/* ───────── الوسائط ───────── */
.cin-admin .cin-asset { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; padding: 0.5rem 0.6rem; border-radius: 0.5rem; background: var(--cin-surface-2); border: 1px solid var(--cin-border-soft); }
.cin-admin .cin-asset img { width: 6rem; height: auto; aspect-ratio: 16 / 9; object-fit: contain; background: #000; border-radius: 0.3rem; border: 1px solid var(--cin-border-soft); }
.cin-admin .cin-asset-meta { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; flex: 1 1 12rem; }
.cin-admin .cin-asset-name { font-weight: 700; overflow-wrap: anywhere; }
[dir=rtl] .cin-admin .cin-asset-name.cin-ltr { text-align: right; }
.cin-admin .cin-table-wrap { overflow-x: auto; }
.cin-admin .cin-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.cin-admin .cin-table th { color: var(--cin-muted); text-align: start; font-weight: 700; padding: 0.5rem; border-bottom: 1px solid var(--cin-border); white-space: nowrap; }
.cin-admin .cin-table td { color: var(--cin-fg); padding: 0.5rem; border-bottom: 1px solid var(--cin-border-soft); vertical-align: top; }
.cin-admin .cin-table tr:hover td { background: var(--cin-surface-2); }
.cin-admin .cin-progress { height: 0.6rem; background: var(--cin-surface-2); border: 1px solid var(--cin-border-soft); border-radius: 999px; overflow: hidden; }
.cin-admin .cin-progress > span { display: block; height: 100%; background: var(--cin-primary); transition: width 0.1s linear; }
.cin-admin .cin-dropzone { border: 2px dashed var(--cin-border); border-radius: 0.75rem; padding: 1rem; background: var(--cin-surface-2); }
.cin-admin .cin-dropzone.is-over { border-color: var(--cin-primary); background: var(--cin-sel-soft); }

/* لا نحدّد display هنا: Livewire يخفي عناصر wire:loading افتراضيًا ويُظهرها أثناء الطلب فقط. */
.cin-admin .cin-spin:not([wire\:loading]):not([wire\:loading\.delay]) { display: inline-block; }
.cin-admin .cin-spin { vertical-align: middle; width: 1rem; height: 1rem; border: 2px solid currentColor; border-top-color: transparent; border-radius: 50%; animation: cin-spin 0.8s linear infinite; }
@keyframes cin-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .cin-admin .cin-spin { animation: none; border-top-color: currentColor; } .cin-admin .cin-fold svg, .cin-admin .cin-progress > span { transition: none; } }

/* ───────── معايرة مناطق التفاعل فوق الصورة ───────── */
.cin-admin .cin-cal-stage { position: relative; width: 100%; max-width: 100%; margin-inline: auto; background: #000; border: 1px solid var(--cin-border); border-radius: 0.5rem; overflow: hidden; touch-action: none; user-select: none; -webkit-user-select: none; direction: ltr; }
.cin-admin .cin-cal-stage img { position: absolute; inset: 0; width: 100%; height: 100%; display: block; pointer-events: none; -webkit-user-drag: none; }
.cin-admin .cin-cal-box { position: absolute; border: 2px solid #fde047; background: rgba(250, 204, 21, 0.2); cursor: move; touch-action: none; border-radius: 3px; }
.cin-admin .cin-cal-box.is-active { border-color: #ffffff; background: rgba(139, 92, 246, 0.38); box-shadow: 0 0 0 2px #7c3aed; z-index: 2; }
.cin-admin .cin-cal-box > .cin-cal-tag { position: absolute; top: -1.45rem; inset-inline-start: -2px; background: #111827; color: #ffffff; font-size: 0.7rem; font-weight: 800; padding: 0.05rem 0.35rem; border-radius: 0.3rem; white-space: nowrap; pointer-events: none; direction: rtl; }
.cin-admin .cin-cal-box:not(.is-active) > .cin-cal-tag { display: none; }
.cin-admin .cin-cal-box:not(.is-active) .cin-cal-handle { display: none; }
.cin-admin .cin-cal-handle { position: absolute; width: 0.8rem; height: 0.8rem; background: #ffffff; border: 2px solid #5b21b6; border-radius: 3px; touch-action: none; z-index: 3; }
@media (pointer: coarse) { .cin-admin .cin-cal-handle { width: 1.1rem; height: 1.1rem; } }
.cin-admin .cin-cal-handle[data-h=nw] { top: -0.4rem; left: -0.4rem; cursor: nwse-resize; }
.cin-admin .cin-cal-handle[data-h=ne] { top: -0.4rem; right: -0.4rem; cursor: nesw-resize; }
.cin-admin .cin-cal-handle[data-h=sw] { bottom: -0.4rem; left: -0.4rem; cursor: nesw-resize; }
.cin-admin .cin-cal-handle[data-h=se] { bottom: -0.4rem; right: -0.4rem; cursor: nwse-resize; }
.cin-admin .cin-cal-handle[data-h=n] { top: -0.4rem; left: calc(50% - 0.4rem); cursor: ns-resize; }
.cin-admin .cin-cal-handle[data-h=s] { bottom: -0.4rem; left: calc(50% - 0.4rem); cursor: ns-resize; }
.cin-admin .cin-cal-handle[data-h=w] { left: -0.4rem; top: calc(50% - 0.4rem); cursor: ew-resize; }
.cin-admin .cin-cal-handle[data-h=e] { right: -0.4rem; top: calc(50% - 0.4rem); cursor: ew-resize; }
/* منطقة صغيرة (مقبض باب مثلًا): نُبقي مقابض الزوايا فقط ليبقى وسط المنطقة قابلًا للسحب؛ والتحجيم الدقيق من الحقول الرقمية. */
.cin-admin .cin-cal-box.is-small .cin-cal-handle[data-h=n], .cin-admin .cin-cal-box.is-small .cin-cal-handle[data-h=s], .cin-admin .cin-cal-box.is-small .cin-cal-handle[data-h=e], .cin-admin .cin-cal-box.is-small .cin-cal-handle[data-h=w] { display: none; }
</style>
