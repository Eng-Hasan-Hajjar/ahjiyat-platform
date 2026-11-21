{{--
    CIN-2 - أنماط الأنواع الجديدة وسجل الأدلة. كلها تحت [data-cinematic-player] ولا تعتمد على أصناف Tailwind جديدة (لا حاجة إلى npm run build).
    تعيد استعمال متغيرات ونظام الطبقات في immersive-styles (الورقة/المسرح/الأزرار) ولا تغيّر هويته البصرية.
--}}
<style data-cinematic-cin2-styles>
@verbatim
/* ───── فحص الصورة ───── */
[data-cinematic-player] .cin2-inspect { touch-action: none; cursor: grab; overflow: hidden; }
[data-cinematic-player] .cin2-inspect.is-pointer { cursor: pointer; }
[data-cinematic-player] .cin2-inspect.is-dragging { cursor: grabbing; }
[data-cinematic-player] .cin2-inspect:focus-visible { outline: 3px solid #fde68a; outline-offset: 2px; }
[data-cinematic-player] .cin2-zoom { position: absolute; inset: 0; transform-origin: 0 0; transition: transform .18s ease-out; will-change: transform; }
[data-cinematic-player] .cin2-inspect.is-dragging .cin2-zoom { transition: none; }
[data-cinematic-player] .cin2-seen { position: absolute; pointer-events: none; border: 2px solid rgba(245,196,81,.85); border-radius: .35rem; box-shadow: 0 0 0 1px rgba(0,0,0,.35), 0 0 14px rgba(245,196,81,.35); }
[data-cinematic-player] .cin2-seen.is-last { animation: cin2-seen .9s ease-out both; }
@keyframes cin2-seen { from { box-shadow: 0 0 0 0 rgba(245,196,81,.9), 0 0 0 1px rgba(0,0,0,.35); } to { box-shadow: 0 0 0 16px rgba(245,196,81,0), 0 0 14px rgba(245,196,81,.35); } }
[data-cinematic-player] .cin2-tools { position: absolute; z-index: 5; top: .5rem; inset-inline-end: .5rem; display: flex; flex-direction: column; align-items: stretch; gap: .35rem; direction: rtl; }
[data-cinematic-player] .cin2-tool { min-width: 2.75rem; min-height: 2.75rem; padding: 0 .6rem; border-radius: .75rem; border: 1px solid rgba(255,255,255,.35); background: rgba(8,12,24,.72); color: #f8fafc; font-size: 1.1rem; font-weight: 900; line-height: 1; cursor: pointer; backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); }
[data-cinematic-player] .cin2-tool:hover { border-color: #fde68a; }
[data-cinematic-player] .cin2-tool[disabled] { opacity: .4; cursor: not-allowed; }
[data-cinematic-player] .cin2-tool.is-on { background: rgba(124,45,18,.85); border-color: #fbbf24; }
[data-cinematic-player] .cin2-tool-wide { font-size: .8rem; white-space: nowrap; }
[data-cinematic-player] .cin2-back { position: absolute; inset: 0; z-index: 4; display: grid; place-items: center; padding: 3.4rem .8rem .8rem; background: #1b130b; animation: cin2-flip .5s ease-out both; transform-origin: center; }
@keyframes cin2-flip { from { opacity: 0; transform: perspective(900px) rotateY(88deg); } to { opacity: 1; transform: perspective(900px) rotateY(0); } }
[data-cinematic-player] .cin2-back-paper { width: min(100%, 34rem); max-height: 100%; overflow: auto; padding: 1rem 1.2rem; border-radius: .6rem; background: #efe2c4; color: #3a2410; border: 1px solid rgba(120,80,30,.6); box-shadow: inset 0 0 40px rgba(120,80,30,.28), 0 10px 30px rgba(0,0,0,.5); direction: rtl; }
[data-cinematic-player] .cin2-back-label { margin: 0 0 .4rem; font-size: .75rem; font-weight: 800; letter-spacing: .04em; color: #7c4a1d; }
[data-cinematic-player] .cin2-back-text { margin: 0; font-size: clamp(1.05rem, 1.6cqw + .7rem, 1.7rem); line-height: 1.9; font-style: italic; overflow-wrap: anywhere; white-space: pre-line; }
[data-cinematic-player] .cin2-notes { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: .4rem; }
[data-cinematic-player] .cin2-notes li { display: flex; flex-direction: column; gap: .1rem; padding: .45rem .7rem; border-radius: .7rem; background: rgba(107,74,43,.1); border: 1px solid rgba(107,74,43,.35); font-size: .86rem; line-height: 1.6; color: var(--imm-ink); overflow-wrap: anywhere; }
[data-cinematic-player] .cin2-notes li b { font-weight: 900; color: var(--imm-rust); }
[data-cinematic-player] .cin2-notes li.is-last { border-color: var(--imm-rust); background: #fff1d0; }

/* ───── إجابة ───── */
[data-cinematic-player] .cin2-facts { margin: 0; display: grid; gap: .35rem; }
[data-cinematic-player] .cin2-facts > div { display: flex; align-items: baseline; justify-content: space-between; gap: .8rem; padding: .45rem .75rem; border-radius: .7rem; background: rgba(107,74,43,.1); border: 1px dashed rgba(107,74,43,.45); }
[data-cinematic-player] .cin2-facts dt { margin: 0; font-size: .85rem; font-weight: 800; color: var(--imm-ink-soft); }
[data-cinematic-player] .cin2-facts dd { margin: 0; font-size: 1.15rem; font-weight: 900; color: var(--imm-rust); direction: ltr; unicode-bidi: plaintext; }
[data-cinematic-player] .cin2-field { display: block; }
[data-cinematic-player] .cin2-inputrow { display: flex; align-items: center; gap: .6rem; }
[data-cinematic-player] .cin2-answer-input { flex: 1 1 auto; min-width: 0; font-size: 1.25rem; font-weight: 800; text-align: center; letter-spacing: .06em; }
[data-cinematic-player] .cin2-unit { flex: 0 0 auto; font-size: .9rem; font-weight: 800; color: var(--imm-ink-soft); }

/* ───── الخزانة ذات الأقفال ───── */
[data-cinematic-player] .cin2-locks { gap: .7rem; }
[data-cinematic-player] .cin2-lock { margin: 0; padding: .55rem .8rem .8rem; border-radius: .9rem; border: 1px solid rgba(107,74,43,.6); background: rgba(255,250,236,.55); min-width: 0; }
[data-cinematic-player] .cin2-lock legend { padding: 0 .4rem; font-size: .85rem; font-weight: 900; color: var(--imm-rust); }
[data-cinematic-player] .cin2-lock.is-ok { border-color: #15803d; background: rgba(220,252,231,.55); box-shadow: 0 0 0 2px rgba(21,128,61,.22); }
[data-cinematic-player] .cin2-lock.is-no { border-color: #b91c1c; background: rgba(254,226,226,.55); box-shadow: 0 0 0 2px rgba(185,28,28,.2); }
[data-cinematic-player] .cin2-lock[disabled] { opacity: .9; }
[data-cinematic-player] .cin2-segs { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; }
[data-cinematic-player] .cin2-seg { display: flex; align-items: center; min-width: 0; }
[data-cinematic-player] .cin2-cell { width: calc(var(--n, 2) * 1.55rem + 1.1rem); min-height: 3rem; padding: .3rem .5rem; border-radius: .7rem; border: 1px solid rgba(107,74,43,.7); background: #fffaf0; color: var(--imm-ink); font-size: 1.5rem; font-weight: 900; text-align: center; letter-spacing: .35em; text-indent: .35em; font-variant-numeric: tabular-nums; }
[data-cinematic-player] .cin2-letters { display: flex; flex-wrap: wrap; gap: .35rem; }
[data-cinematic-player] .cin2-letter { min-width: 2.75rem; justify-content: center; font-size: 1.1rem; padding: .3rem .6rem; }
[data-cinematic-player] .cin2-lockmark { font-size: 1.4rem; font-weight: 900; }
[data-cinematic-player] .cin2-lock.is-ok .cin2-lockmark { color: #15803d; }
[data-cinematic-player] .cin2-lock.is-no .cin2-lockmark { color: #b91c1c; }
[data-cinematic-player] .cin2-reveal { margin: 0; padding: .65rem .85rem; border-radius: .9rem; background: #fff1d0; border: 1px solid rgba(124,45,18,.55); color: var(--imm-ink); font-size: .95rem; font-weight: 800; line-height: 1.75; overflow-wrap: anywhere; white-space: pre-line; animation: imm-enter .4s ease both; }

/* ───── سجل الأدلة ───── */
[data-cinematic-player] .cin2-count { display: inline-flex; align-items: center; justify-content: center; min-width: 1.25rem; height: 1.25rem; padding: 0 .3rem; border-radius: 9999px; background: var(--imm-gold); color: #3a2410; font-size: .72rem; font-weight: 900; }
[data-cinematic-player] .cin2-ev { position: absolute; inset: 0; z-index: 65; display: grid; place-items: center; padding: calc(var(--imm-safe-t) + var(--imm-hud-h) + .3rem) calc(var(--imm-safe-r) + .6rem) calc(var(--imm-safe-b) + .6rem) calc(var(--imm-safe-l) + .6rem); background: rgba(4,7,14,.78); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); }
[data-cinematic-player] .cin2-ev-card { width: min(100%, 46rem); max-height: 100%; display: flex; flex-direction: column; gap: .6rem; padding: .9rem 1rem; border-radius: 1.1rem; background: #f4e9d1; color: var(--imm-ink); border: 1px solid rgba(169,122,58,.7); box-shadow: 0 24px 70px rgba(0,0,0,.65), inset 0 0 36px rgba(150,100,40,.22); min-height: 0; }
[data-cinematic-player] .cin2-ev-head { display: flex; align-items: center; justify-content: space-between; gap: .6rem; }
[data-cinematic-player] .cin2-ev-head h2 { margin: 0; font-size: 1.1rem; font-weight: 900; color: var(--imm-rust); }
[data-cinematic-player] .cin2-ev-card .imm-btn { border-color: rgba(107,74,43,.6); background: rgba(255,250,236,.8); color: var(--imm-ink); }
[data-cinematic-player] .cin2-ev-body { display: grid; grid-template-columns: minmax(0, 12rem) minmax(0, 1fr); gap: .8rem; min-height: 0; overflow: auto; overscroll-behavior: contain; }
[data-cinematic-player] .cin2-ev-list { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: .35rem; align-self: start; }
[data-cinematic-player] .cin2-ev-list button { display: flex; align-items: center; gap: .45rem; width: 100%; min-height: 2.75rem; padding: .35rem .65rem; border-radius: .75rem; border: 1px solid rgba(107,74,43,.5); background: rgba(255,250,236,.7); color: var(--imm-ink); font-size: .85rem; font-weight: 800; text-align: start; cursor: pointer; }
[data-cinematic-player] .cin2-ev-list button.is-on { border-color: var(--imm-rust); background: #fff1d0; box-shadow: 0 0 0 2px rgba(154,52,18,.3); }
[data-cinematic-player] .cin2-badge { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 1.5rem; height: 1.5rem; border-radius: 9999px; background: var(--imm-rust); color: #fff7ed; font-size: .78rem; font-weight: 900; }
[data-cinematic-player] .cin2-ev-detail { display: flex; flex-direction: column; gap: .55rem; min-width: 0; }
[data-cinematic-player] .cin2-ev-detail h3 { margin: 0; font-size: 1.05rem; font-weight: 900; color: var(--imm-ink); overflow-wrap: anywhere; }
[data-cinematic-player] .cin2-ev-chapter { margin: 0; font-size: .75rem; font-weight: 800; color: var(--imm-ink-soft); }
[data-cinematic-player] .cin2-ev-imgwrap { max-height: 40dvh; overflow: auto; border-radius: .7rem; border: 1px solid rgba(107,74,43,.5); background: #1b130b; }
[data-cinematic-player] .cin2-ev-imgwrap.is-big { max-height: 56dvh; }
[data-cinematic-player] .cin2-ev-imgbox { position: relative; width: 100%; }
[data-cinematic-player] .cin2-ev-imgwrap.is-big .cin2-ev-imgbox { width: 220%; }
[data-cinematic-player] .cin2-ev-imgbox img { display: block; width: 100%; height: auto; }
[data-cinematic-player] .cin2-marker { position: absolute; width: 1.6rem; height: 1.6rem; margin: -.8rem 0 0 -.8rem; border-radius: 9999px; border: 3px solid #fcd34d; box-shadow: 0 0 0 2px rgba(0,0,0,.45), 0 0 14px rgba(252,211,77,.7); pointer-events: none; }
[data-cinematic-player] .cin2-marker > span { position: absolute; top: 100%; left: 50%; transform: translateX(-50%); margin-top: .25rem; padding: .05rem .45rem; border-radius: .4rem; background: rgba(8,12,24,.88); color: #fde68a; font-size: .7rem; font-weight: 800; white-space: nowrap; direction: rtl; }

/* ───── PLM-3 board_move: الرقعة (الصف 1 أسفل والعمود 1 يسار — الشبكة dir=ltr والصفوف تُرسم تنازليًا) ───── */
[data-cinematic-player] .cin2-board { --cell: calc(min(100cqw, 100cqh) / (var(--rows) + .7)); display: grid; grid-template-columns: .7fr repeat(var(--cols), 1fr); grid-template-rows: repeat(var(--rows), 1fr) .7fr;
    width: min(100cqw, calc(100cqh * (var(--cols) + .7) / (var(--rows) + .7))); aspect-ratio: calc((var(--cols) + .7) / (var(--rows) + .7)); max-width: none; padding: 0; border-radius: .6rem; position: relative;
    background: linear-gradient(160deg, #3a2412, #22140a); box-shadow: 0 24px 70px rgba(0,0,0,.65), 0 0 0 1px rgba(255,255,255,.1), inset 0 0 0 .3rem #4a2f17; user-select: none; -webkit-user-select: none; }
[data-cinematic-player] .cin2-axis { display: flex; align-items: center; justify-content: center; color: #f3d9a6; font-weight: 800; font-size: clamp(.6rem, calc(var(--cell) * .38), 1.05rem); line-height: 1; direction: ltr; }
[data-cinematic-player] .cin2-board-grid { grid-area: 1 / 2 / span var(--rows) / span var(--cols); display: grid; grid-template-columns: repeat(var(--cols), 1fr); grid-template-rows: repeat(var(--rows), 1fr); position: relative; border: 2px solid #1b1007; touch-action: manipulation; }
[data-cinematic-player] .cin2-sq { position: relative; display: block; padding: 0; margin: 0; border: 0; border-radius: 0; min-width: 0; min-height: 0; cursor: pointer; appearance: none; -webkit-appearance: none; }
[data-cinematic-player] .cin2-sq.is-light { background: #e7d3a8; }
[data-cinematic-player] .cin2-sq.is-dark { background: #9a7449; }
[data-cinematic-player] .cin2-sq:disabled { cursor: default; opacity: 1; }
[data-cinematic-player] .cin2-sq:focus-visible { outline: 3px solid #7c3aed; outline-offset: -3px; z-index: 3; }
[data-cinematic-player] .cin2-sq.is-start::after { content: ''; position: absolute; inset: 12%; border: 2px dotted rgba(27,16,7,.55); border-radius: 9999px; }
[data-cinematic-player] .cin2-sq.is-pending { box-shadow: inset 0 0 0 3px #38bdf8; }
/* الأثر الدائري المحتمل: شكل (حلقة متقطعة) لا لون وحده */
[data-cinematic-player] .cin2-trace { position: absolute; inset: 14%; border: 3px dashed #fde047; border-radius: 9999px; box-shadow: 0 0 0 2px rgba(27,16,7,.55), inset 0 0 0 2px rgba(27,16,7,.35); pointer-events: none; }
[data-cinematic-player] .cin2-knight { grid-row: 1; grid-column: 1; position: relative; z-index: 5; display: flex; align-items: center; justify-content: center; touch-action: none; cursor: grab; transition: transform .22s ease; }
[data-cinematic-player] .cin2-knight > span { font-size: calc(var(--cell) * .82); line-height: 1; color: #fff; text-shadow: 0 0 2px #1b1007, 0 0 2px #1b1007, 0 2px 3px rgba(0,0,0,.7); -webkit-text-stroke: 1.5px #1b1007; paint-order: stroke fill; pointer-events: none; }
[data-cinematic-player] .cin2-knight.is-dragging { transition: none; cursor: grabbing; z-index: 8; filter: drop-shadow(0 8px 10px rgba(0,0,0,.55)); }
[data-cinematic-player] .cin2-knight.is-moved { filter: drop-shadow(0 0 6px rgba(56,189,248,.9)); }
[data-cinematic-player] .cin2-board.is-done .cin2-knight { cursor: default; filter: drop-shadow(0 0 8px rgba(253,224,71,.85)); }
[data-cinematic-player] .cin2-board.is-shake .cin2-board-grid { animation: cin2-shake .5s ease; }
@keyframes cin2-shake { 0%,100% { transform: none; } 20%,60% { transform: translateX(-4px); } 40%,80% { transform: translateX(4px); } }
[data-cinematic-player] .cin34-board-paper .imm-note { margin: 0; }

/* ───── PLM-4 inference: استنتاج + مصدره ───── */
[data-cinematic-player] .cin34-infer { gap: .55rem; }
[data-cinematic-player] .cin34-opts { display: flex; flex-direction: column; gap: .4rem; }
[data-cinematic-player] .cin34-opt { text-align: start; justify-content: flex-start; min-height: 2.9rem; white-space: normal; line-height: 1.55; }
[data-cinematic-player] .cin34-lead { margin: .2rem 0 0; font-weight: 800; color: var(--imm-ink-soft, #6b4a2b); font-size: .85rem; }
[data-cinematic-player] .cin34-srcs { display: flex; flex-direction: column; gap: .5rem; }
[data-cinematic-player] .cin34-group { display: flex; flex-direction: column; gap: .3rem; }
[data-cinematic-player] .cin34-group-title { margin: 0; font-size: .78rem; font-weight: 800; color: var(--imm-ink-soft, #6b4a2b); letter-spacing: .02em; }
[data-cinematic-player] .cin34-src { display: flex; flex-direction: column; align-items: flex-start; gap: .15rem; text-align: start; white-space: normal; line-height: 1.55; min-height: 2.9rem; }
[data-cinematic-player] .cin34-src > b { font-size: .88rem; }
[data-cinematic-player] .cin34-src > span { font-size: .8rem; font-weight: 600; opacity: .9; }
[data-cinematic-player] .cin34-opt.is-on::before, [data-cinematic-player] .cin34-src.is-on::before { content: '✓ '; font-weight: 900; }
@media (max-width: 640px) {
    [data-cinematic-player] .cin2-ev-body { grid-template-columns: minmax(0, 1fr); }
    [data-cinematic-player] .cin2-ev-list { flex-direction: row; flex-wrap: wrap; }
    [data-cinematic-player] .cin2-ev-list button { width: auto; }
    [data-cinematic-player] .cin2-tool { min-width: 2.75rem; }
}
@media (orientation: landscape) and (max-height: 520px) {
    [data-cinematic-player] .cin2-tools { flex-direction: row; top: .35rem; }
    [data-cinematic-player] .cin2-back { padding-top: 3.2rem; }
    [data-cinematic-player] .cin2-ev-imgwrap { max-height: 32dvh; }
}
/* جوال أفقي ضيّق (≤720px عرضًا، مثل 640×360 و667×375): تخطيط CIN-1 يضع الورقة سفلية فتُضغط الصورة إلى نحو 100px ولا يمكن فحصها.
   للأنواع الجديدة فقط نعيد استعمال التخطيط الجانبي نفسه (ورقة في عمود، والصورة بجانبها) كما في الأفقي العريض. باقي أنواع CIN-1 لا تتغير. */
@media (orientation: landscape) and (max-width: 720px) and (max-height: 520px) {
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="yes"][data-kind="inspect"],
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="yes"][data-kind="answer"],
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="yes"][data-kind="multi_lock"],
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="yes"][data-kind="board_move"] { padding-bottom: var(--imm-safe-b); grid-template-rows: minmax(0, 1fr); grid-template-columns: min(17rem, 46vw) minmax(0, 1fr); column-gap: .5rem; padding-inline-start: calc(var(--imm-safe-l) + .5rem); }
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="yes"][data-kind="inspect"] .imm-stage,
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="yes"][data-kind="answer"] .imm-stage,
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="yes"][data-kind="multi_lock"] .imm-stage,
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="yes"][data-kind="board_move"] .imm-stage { grid-column: 2; }
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="yes"][data-kind="inspect"] .imm-paper,
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="yes"][data-kind="answer"] .imm-paper,
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="yes"][data-kind="multi_lock"] .imm-paper,
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="yes"][data-kind="board_move"] .imm-paper { grid-column: 1; grid-row: 1; align-self: center; max-height: 100%; border-radius: 1.2rem; border-bottom: 1px solid rgba(169,122,58,.55); margin-block: .3rem; box-shadow: 0 18px 50px rgba(0,0,0,.55), inset 0 0 36px rgba(150,100,40,.22); padding-bottom: .8rem; }
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="yes"][data-kind="inspect"] .imm-paper::before,
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="yes"][data-kind="answer"] .imm-paper::before,
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="yes"][data-kind="multi_lock"] .imm-paper::before,
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="yes"][data-kind="board_move"] .imm-paper::before { display: none; }
}
@media (prefers-reduced-motion: reduce) {
    [data-cinematic-player] .cin2-zoom { transition: none; }
    [data-cinematic-player] .cin2-back, [data-cinematic-player] .cin2-seen.is-last, [data-cinematic-player] .cin2-reveal, [data-cinematic-player] .cin2-board.is-shake .cin2-board-grid { animation: none; }
    [data-cinematic-player] .cin2-knight { transition: none; }
}
@endverbatim
</style>
