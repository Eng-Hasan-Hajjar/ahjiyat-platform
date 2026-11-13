{{--
    CIN-1 IMMERSIVE - أنماط الوضع الغامر للمشغّل (طبقة فوق أنماط المشغّل القديمة cin-*، لا بديلًا عنها).
    ─ كلها محصورة تحت [data-cinematic-player] فلا أثر لها على أي صفحة أخرى. لا تعتمد على أي صنف Tailwind جديد (لا حاجة إلى npm run build).
    ─ نظام الطبقات (من الخلف إلى الأمام):
        1) .imm-backdrop   خلفية محيطة (نسخة ضبابية من صورة/ملصق المشهد إن وُجد) تملأ الشاشة فلا فراغ أسود.
        2) .imm-stage      المسرح: خلية شبكة تحمل الوسيط (فيديو/صورة/منطقة نقر) متوسّطًا بنسبة أبعاده الحقيقية دون قصّ (container query).
           └ طبقة التفاعل (توهّج/نبض تلميح/وميض نجاح/مناطق المعاينة الإدارية) داخل إطار الصورة نفسه فتتبع نسبه المئوية.
        3) .imm-paper      الورقة: بطاقة الخيارات/النص (عمود جانبي على الشاشات العريضة، ورقة سفلية على الجوال العمودي).
                           في مشاهد «المسرح» (hotspot/container/flashlight) تصبح تعليقًا شفافًا فوق المشهد على الشاشات العريضة.
        4) .imm-hud        شريط علوي نحيف (عودة/عنوان/ملء الشاشة/أدوات) يختفي أثناء تشغيل الفيديو.
        5) .imm-veil       ستارة انتقال بين المشاهد.
        6) .imm-admin      درج أدوات المعاينة الإشرافية (للإدارة فقط؛ لا يُرسَم أصلًا للاعب الحقيقي).
    ─ المقاسات: عمودي/ضيق (<= 720px أو orientation:portrait) = ورقة سفلية؛ أفقي عريض = عمود جانبي؛ أفقي قصير (جوال أفقي) = أصغر.
    ─ المناطق الآمنة: env(safe-area-inset-*) (تتطلب viewport-fit=cover الذي يضيفه التخطيط عند @section('immersive')).
--}}
<style data-cinematic-immersive-styles>
@verbatim
body.cin-immersive-body { margin: 0; overflow: hidden; background: #070b16; }
[data-cinematic-player] [x-cloak] { display: none !important; }

[data-cinematic-player].imm-root {
    --imm-safe-t: env(safe-area-inset-top, 0px); --imm-safe-r: env(safe-area-inset-right, 0px); --imm-safe-b: env(safe-area-inset-bottom, 0px); --imm-safe-l: env(safe-area-inset-left, 0px);
    --imm-hud-h: 3.25rem; --imm-gap: .75rem;
    --imm-night: #070b16; --imm-night-2: #111a2e; --imm-text: #f8fafc; --imm-muted: #cbd5e1; --imm-gold: #f5c451; --imm-amethyst: #a78bfa;
    --imm-ink: #2a1a0c; --imm-ink-soft: #6b4a2b; --imm-paper: #f4e9d1; --imm-paper-2: #e8d6ad; --imm-edge: #a97a3a; --imm-rust: #7c2d12; --imm-rust-2: #9a3412;
    position: fixed; inset: 0; z-index: 40; overflow: hidden; direction: rtl; background: var(--imm-night); color: var(--imm-text);
    font-family: var(--font-body, ui-sans-serif, system-ui, sans-serif); line-height: 1.55; -webkit-tap-highlight-color: transparent; text-size-adjust: 100%; -webkit-text-size-adjust: 100%;
}
[data-cinematic-player].imm-root *, [data-cinematic-player].imm-root *::before, [data-cinematic-player].imm-root *::after { box-sizing: border-box; }
[data-cinematic-player].imm-root.is-idle { cursor: none; }
[data-cinematic-player] .imm-root-noscript { position: relative; z-index: 2; margin: 5rem 1rem 0; padding: 1rem; border-radius: 1rem; background: rgba(15,23,42,.9); color: var(--imm-text); font-size: .9rem; }

/* 1) الخلفية المحيطة */
[data-cinematic-player] .imm-backdrop { position: absolute; inset: 0; background: radial-gradient(120% 90% at 50% 20%, #17233b 0, #0b1222 55%, #05070e 100%); overflow: hidden; pointer-events: none; }
[data-cinematic-player] .imm-backdrop::before { content: ''; position: absolute; inset: -8%; background-image: var(--imm-bg, none); background-size: cover; background-position: center; filter: blur(26px) brightness(.42) saturate(1.2); opacity: 0; transition: opacity .6s ease; }
[data-cinematic-player] .imm-backdrop.has-bg::before { opacity: 1; }
[data-cinematic-player] .imm-backdrop::after { content: ''; position: absolute; inset: 0; background: radial-gradient(ellipse at center, transparent 45%, rgba(0,0,0,.55) 100%); }

/* 5) الستارة */
[data-cinematic-player] .imm-veil { position: absolute; inset: 0; z-index: 70; background: #04060c; opacity: 0; pointer-events: none; transition: opacity .22s ease; }
[data-cinematic-player] .imm-veil.is-on { opacity: 1; pointer-events: auto; }

/* 4) الشريط العلوي */
[data-cinematic-player] .imm-hud { position: absolute; z-index: 50; top: 0; inset-inline: 0; display: flex; align-items: center; gap: .5rem; min-height: calc(var(--imm-hud-h) + var(--imm-safe-t)); padding: calc(var(--imm-safe-t) + .35rem) calc(var(--imm-safe-r) + .6rem) .35rem calc(var(--imm-safe-l) + .6rem); background: linear-gradient(to bottom, rgba(4,7,14,.82), rgba(4,7,14,.38) 70%, transparent); transition: opacity .35s ease, transform .35s ease; }
[data-cinematic-player].is-idle .imm-hud { opacity: 0; transform: translateY(-.4rem); pointer-events: none; }
[data-cinematic-player] .imm-hud:focus-within { opacity: 1; transform: none; pointer-events: auto; }
[data-cinematic-player] .imm-title { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; justify-content: center; }
[data-cinematic-player] .imm-h1 { margin: 0; font-size: 1rem; font-weight: 800; line-height: 1.25; color: var(--imm-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-shadow: 0 1px 8px rgba(0,0,0,.7); }
[data-cinematic-player] .imm-kicker { margin: 0; font-size: .72rem; color: var(--imm-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-shadow: 0 1px 6px rgba(0,0,0,.7); }
[data-cinematic-player] .imm-hud-end { display: flex; align-items: center; gap: .35rem; flex: 0 0 auto; }
[data-cinematic-player] .imm-iconbtn { display: inline-flex; align-items: center; justify-content: center; gap: .4rem; min-width: 2.75rem; min-height: 2.75rem; padding: 0 .55rem; border-radius: 9999px; border: 1px solid rgba(255,255,255,.18); background: rgba(10,16,30,.55); color: var(--imm-text); font-size: .8rem; font-weight: 800; cursor: pointer; text-decoration: none; backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); transition: background .15s, border-color .15s; }
[data-cinematic-player] .imm-iconbtn:hover { background: rgba(30,41,70,.8); border-color: rgba(255,255,255,.4); }
[data-cinematic-player] .imm-iconbtn[disabled] { opacity: .4; cursor: not-allowed; }
[data-cinematic-player] .imm-iconbtn svg { width: 1.2rem; height: 1.2rem; flex: 0 0 auto; }
[data-cinematic-player] .imm-iconbtn.is-on { background: rgba(124,58,237,.55); border-color: rgba(196,181,253,.8); }
[data-cinematic-player] .imm-pill-admin { border-color: rgba(252,211,77,.55); color: #fde68a; }
[data-cinematic-player] .imm-pill-admin.is-on { background: rgba(120,83,8,.7); border-color: #fcd34d; color: #fff7d6; }
[data-cinematic-player] .imm-progress { position: absolute; inset-inline: 0; bottom: 0; height: 3px; background: rgba(255,255,255,.12); }
[data-cinematic-player] .imm-progress > span { display: block; height: 100%; background: linear-gradient(to left, var(--imm-gold), var(--imm-amethyst)); transition: width .5s ease; }
[data-cinematic-player] .imm-hide-sm { display: none; }
@media (min-width: 640px) { [data-cinematic-player] .imm-hide-sm { display: inline; } [data-cinematic-player] .imm-h1 { font-size: 1.1rem; } }

/* 2) المسرح والمشهد (شبكة: عمودي = مسرح فوق وورقة تحت) */
[data-cinematic-player] .imm-scene { position: absolute; inset: 0; display: grid; grid-template-columns: minmax(0, 1fr); grid-template-rows: minmax(0, 1fr) auto; row-gap: .6rem;
    padding: calc(var(--imm-safe-t) + var(--imm-hud-h)) var(--imm-safe-r) 0 var(--imm-safe-l); animation: imm-enter .45s ease both; }
@keyframes imm-enter { from { opacity: 0; transform: scale(.985); } to { opacity: 1; transform: none; } }
[data-cinematic-player] .imm-scene[data-imm-layout="media"], [data-cinematic-player] .imm-scene[data-imm-layout="title"] { grid-template-rows: minmax(0, 1fr); padding-bottom: var(--imm-safe-b); }
[data-cinematic-player] .imm-stage { grid-column: 1; grid-row: 1; position: relative; min-width: 0; min-height: 0; display: grid; place-items: center; container-type: size; container-name: imm-stage; }

/* الوسيط: فيديو بنسبة 16:9 يتسع في المسرح كاملًا دون قصّ */
[data-cinematic-player] .imm-stage .cin-video { --vr: 1.7778; width: min(100cqw, calc(100cqh * var(--vr))); max-width: none; aspect-ratio: var(--vr); border-radius: .75rem; border: 0; background: #000; box-shadow: 0 24px 70px rgba(0,0,0,.65), 0 0 0 1px rgba(255,255,255,.08); }
[data-cinematic-player] .imm-stage .cin-video video { object-fit: contain; }
[data-cinematic-player] .imm-stage .cin-overlay { gap: .8rem; background: radial-gradient(circle at center, rgba(4,7,14,.4), rgba(4,7,14,.72)); }
[data-cinematic-player] .imm-stage .cin-overlay.is-ended { flex-direction: row; flex-wrap: wrap; background: rgba(4,7,14,.66); }
[data-cinematic-player] .imm-stage .cin-bigplay { width: 5rem; height: 5rem; border: 2px solid rgba(255,255,255,.9); background: radial-gradient(circle at 35% 30%, #8b5cf6, #5b21b6); box-shadow: 0 0 0 0 rgba(139,92,246,.5), 0 10px 30px rgba(0,0,0,.5); animation: imm-breathe 2.4s ease-in-out infinite; }
@keyframes imm-breathe { 0%, 100% { box-shadow: 0 0 0 0 rgba(139,92,246,.45), 0 10px 30px rgba(0,0,0,.5); } 50% { box-shadow: 0 0 0 14px rgba(139,92,246,0), 0 10px 30px rgba(0,0,0,.5); } }
[data-cinematic-player] .imm-loadtxt { margin: 0; font-size: .8rem; font-weight: 700; color: var(--imm-muted); }
[data-cinematic-player] .imm-spinner { width: 2rem; height: 2rem; border-radius: 9999px; border: 2px solid var(--imm-amethyst); border-top-color: transparent; }

/* صورة المشهد (مرجع بصري لمشاهد الورقة): تملأ المسرح كاملًا بلا قصّ، فوق الخلفية المحيطة. العقد القديم (IMG-03) يبقى: aspect-video/object-cover تُلغى هنا عمدًا. */
[data-cinematic-player] .imm-stage .imm-art { position: absolute; inset: 0; aspect-ratio: auto; border: 0; border-radius: 0; background: transparent; display: grid; place-items: center; padding: .6rem; }
[data-cinematic-player] .imm-stage .imm-art img { position: static; inset: auto; width: 100%; height: 100%; object-fit: contain; border-radius: .75rem; filter: drop-shadow(0 18px 40px rgba(0,0,0,.6)); }
[data-cinematic-player] .imm-fill { width: 100%; height: 100%; display: grid; place-items: center; padding: 1rem; text-align: center; overflow: auto; }
[data-cinematic-player] .imm-float { position: absolute; z-index: 25; top: calc(var(--imm-safe-t) + var(--imm-hud-h) + .5rem); inset-inline: calc(var(--imm-safe-l) + 1rem) calc(var(--imm-safe-r) + 1rem); max-width: 34rem; margin-inline: auto; pointer-events: none; }
/* مشاهد «الضغط على الصورة»: العائمة مخفية افتراضيًا (التغذية الراجعة في تذييل الورقة فوق المشهد على الشاشات العريضة)، وتظهر على الورقة السفلية */
[data-cinematic-player] .imm-float[data-imm-for="stage"] { display: none; }
@media not all and (min-width: 721px) and (orientation: landscape) {
    [data-cinematic-player] .imm-float[data-imm-for="stage"] { display: block; }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-paper-foot .imm-feedback { display: none; }
}

/* مناطق النقر على الصورة: الإطار يتسع في المسرح بنسبة الصورة الحقيقية (الإحداثيات تبقى مقيسة من الصورة) */
[data-cinematic-player] .imm-stage .cin-stage { width: min(100cqw, calc(100cqh * var(--r, 1.7778))); max-width: none; border: 0; border-radius: .6rem; box-shadow: 0 24px 70px rgba(0,0,0,.65), 0 0 0 1px rgba(255,255,255,.08); }
[data-cinematic-player] .imm-stage .cin-stage.is-pointer { cursor: pointer; }
[data-cinematic-player] .imm-stage .cin-stage-img { transform-origin: var(--zx, 50%) var(--zy, 50%); transition: transform 1.6s cubic-bezier(.2,.7,.2,1); will-change: transform; }
[data-cinematic-player] .imm-stage .cin-stage.is-done .cin-stage-img { transform: scale(1.12); }
[data-cinematic-player] .imm-pulse { position: absolute; pointer-events: none; border-radius: 9999px; opacity: 0; border: 2px solid rgba(253,230,138,.9); box-shadow: 0 0 22px 4px rgba(253,224,71,.35), inset 0 0 14px rgba(253,224,71,.25); transition: opacity .6s ease; }
[data-cinematic-player] .imm-pulse.on { opacity: 1; animation: imm-pulse 1.9s ease-in-out infinite; }
@keyframes imm-pulse { 0%, 100% { transform: scale(.9); opacity: .55; } 50% { transform: scale(1.12); opacity: 1; } }
[data-cinematic-player] .imm-flash { position: absolute; inset: 0; pointer-events: none; opacity: 0; background: radial-gradient(circle at var(--zx, 50%) var(--zy, 50%), rgba(255,236,170,.85) 0, rgba(255,214,120,.35) 22%, transparent 55%); animation: imm-flash 1.5s ease-out both; }
@keyframes imm-flash { 0% { opacity: 0; } 25% { opacity: .95; } 100% { opacity: .0; } }
[data-cinematic-player] .imm-zone { position: absolute; pointer-events: none; border: 2px dashed rgba(251,191,36,.95); background: rgba(251,191,36,.12); border-radius: .35rem; }
[data-cinematic-player] .imm-zone > span { position: absolute; inset-inline-start: 0; top: -1.35rem; padding: .05rem .4rem; border-radius: .35rem; background: rgba(120,83,8,.92); color: #fff7d6; font-size: .68rem; font-weight: 800; white-space: nowrap; direction: rtl; }

/* حالات المسرح المركزية: تحميل/خطأ/نهاية/انتقال/فيديو غير متاح */
[data-cinematic-player] .imm-center { position: absolute; inset: 0; z-index: 20; display: grid; place-items: center; padding: calc(var(--imm-safe-t) + var(--imm-hud-h)) calc(var(--imm-safe-r) + 1rem) calc(var(--imm-safe-b) + 1rem) calc(var(--imm-safe-l) + 1rem); text-align: center; }
[data-cinematic-player] .imm-card { width: min(100%, 30rem); display: flex; flex-direction: column; align-items: center; gap: .8rem; padding: 1.5rem 1.25rem; border-radius: 1.25rem; background: rgba(12,18,34,.86); border: 1px solid rgba(255,255,255,.14); box-shadow: 0 24px 70px rgba(0,0,0,.6); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); animation: imm-enter .4s ease both; }
[data-cinematic-player] .imm-card h1, [data-cinematic-player] .imm-card .imm-card-title { margin: 0; font-size: 1.4rem; font-weight: 900; line-height: 1.3; color: var(--imm-text); }
[data-cinematic-player] .imm-card p { margin: 0; font-size: .9rem; color: var(--imm-muted); overflow-wrap: anywhere; }
[data-cinematic-player] .imm-card.is-error { border-color: rgba(251,113,133,.45); }
[data-cinematic-player] .imm-card-row { display: flex; flex-wrap: wrap; gap: .6rem; justify-content: center; }
[data-cinematic-player] .imm-title-card { width: min(100%, 44rem); background: none; border: 0; box-shadow: none; backdrop-filter: none; -webkit-backdrop-filter: none; }
[data-cinematic-player] .imm-title-card .imm-big { margin: 0; font-size: clamp(1.8rem, 6vw, 3.4rem); font-weight: 900; line-height: 1.2; letter-spacing: .01em; color: var(--imm-text); text-shadow: 0 4px 30px rgba(0,0,0,.7); }
[data-cinematic-player] .imm-title-card .imm-rule { width: 4rem; height: 2px; background: linear-gradient(to left, transparent, var(--imm-gold), transparent); border: 0; margin: .2rem 0; }
[data-cinematic-player] .imm-gold { color: var(--imm-gold); font-weight: 800; }

/* الأزرار (مشتركة بين الخلفية الداكنة والورقة) */
[data-cinematic-player] .imm-btn { display: inline-flex; align-items: center; justify-content: center; gap: .45rem; min-height: 2.75rem; padding: .5rem 1.25rem; border-radius: .8rem; border: 1px solid rgba(255,255,255,.28); background: rgba(255,255,255,.08); color: var(--imm-text); font-size: .9rem; font-weight: 800; cursor: pointer; text-decoration: none; transition: transform .12s, background .15s, border-color .15s; }
[data-cinematic-player] .imm-btn:hover { background: rgba(255,255,255,.16); border-color: rgba(255,255,255,.5); }
[data-cinematic-player] .imm-btn:active { transform: scale(.97); }
[data-cinematic-player] .imm-btn[disabled] { opacity: .45; cursor: not-allowed; transform: none; }
[data-cinematic-player] .imm-btn svg { width: 1.1rem; height: 1.1rem; flex: 0 0 auto; }
[data-cinematic-player] .imm-btn-primary { border-color: #fbbf24; background: linear-gradient(160deg, #b45309, #7c2d12); color: #fff7ed; box-shadow: 0 6px 20px rgba(124,45,18,.5), inset 0 1px 0 rgba(255,255,255,.25); }
[data-cinematic-player] .imm-btn-primary:hover { background: linear-gradient(160deg, #c2610c, #8a3414); border-color: #fde68a; }
[data-cinematic-player] .imm-btn-sm { min-height: 2.5rem; padding: .3rem .9rem; font-size: .8rem; }
[data-cinematic-player] .imm-bigplay-label { font-size: .85rem; font-weight: 800; color: #fff; text-shadow: 0 1px 8px rgba(0,0,0,.8); }
[data-cinematic-player] .imm-root button:focus-visible, [data-cinematic-player].imm-root button:focus-visible, [data-cinematic-player] a:focus-visible, [data-cinematic-player] input:focus-visible, [data-cinematic-player] select:focus-visible, [data-cinematic-player] [tabindex]:focus-visible { outline: 3px solid #fde68a; outline-offset: 2px; }
/* العنوان يُركَّز برمجيًا لقارئ الشاشة فقط: بلا إطار مرئي */
[data-cinematic-player] .imm-h1:focus, [data-cinematic-player] .imm-h1:focus-visible { outline: none; }

/* 3) الورقة (افتراضيًا: ورقة سفلية للشاشات الضيقة/العمودية) */
[data-cinematic-player] .imm-paper { grid-column: 1; grid-row: 2; position: relative; z-index: 10; display: flex; flex-direction: column; min-height: 0; max-height: min(56vh, 31rem); max-height: min(56dvh, 31rem);
    padding: .85rem 1rem calc(var(--imm-safe-b) + .85rem); margin: 0; color: var(--imm-ink);
    background-color: var(--imm-paper);
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='160' height='160'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 .45  0 0 0 0 .32  0 0 0 0 .15  0 0 0 .13 0'/></filter><rect width='160' height='160' filter='url(%23n)'/></svg>"), linear-gradient(165deg, var(--imm-paper), var(--imm-paper-2));
    border-radius: 1.25rem 1.25rem 0 0; border: 1px solid rgba(169,122,58,.55); border-bottom: 0;
    box-shadow: 0 -14px 40px rgba(0,0,0,.5), inset 0 0 36px rgba(150,100,40,.22); animation: imm-sheet .5s cubic-bezier(.2,.8,.2,1) both; }
@keyframes imm-sheet { from { opacity: 0; transform: translateY(1.2rem); } to { opacity: 1; transform: none; } }
[data-cinematic-player] .imm-paper::before { content: ''; align-self: center; flex: 0 0 auto; width: 2.6rem; height: .28rem; margin: -.35rem 0 .55rem; border-radius: 9999px; background: rgba(107,74,43,.35); }
[data-cinematic-player] .imm-paper-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; overscroll-behavior: contain; -webkit-overflow-scrolling: touch; display: flex; flex-direction: column; gap: .7rem; padding-block: .1rem; scrollbar-width: thin; scrollbar-color: rgba(107,74,43,.5) transparent; }
[data-cinematic-player] .imm-paper-foot { flex: 0 0 auto; display: flex; flex-direction: column; gap: .55rem; padding-top: .6rem; }
[data-cinematic-player] .imm-paper-actions { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
[data-cinematic-player] .imm-prompt { margin: 0; font-size: 1.15rem; font-weight: 900; line-height: 1.45; color: var(--imm-ink); overflow-wrap: anywhere; }
[data-cinematic-player] .imm-sub { margin: 0; font-size: .82rem; line-height: 1.6; color: var(--imm-ink-soft); overflow-wrap: anywhere; }
[data-cinematic-player] .imm-help { margin: 0; padding: .5rem .7rem; border-radius: .7rem; background: rgba(107,74,43,.1); border: 1px dashed rgba(107,74,43,.4); font-size: .8rem; line-height: 1.6; color: var(--imm-ink-soft); overflow-wrap: anywhere; }
[data-cinematic-player] .imm-helpbtn { align-self: flex-start; display: inline-flex; align-items: center; gap: .35rem; min-height: 2.25rem; padding: .15rem .65rem; border-radius: 9999px; border: 1px solid rgba(107,74,43,.45); background: transparent; color: var(--imm-ink-soft); font-size: .75rem; font-weight: 800; cursor: pointer; }
[data-cinematic-player] .imm-helpbtn:hover { background: rgba(107,74,43,.12); }
[data-cinematic-player] .imm-dialog { margin: 0; padding: .1rem .75rem .1rem 0; border-inline-start: 3px solid rgba(169,122,58,.7); font-size: .88rem; line-height: 1.7; color: var(--imm-ink); font-style: italic; overflow-wrap: anywhere; }
[data-cinematic-player] .imm-dialog b { font-style: normal; font-weight: 900; color: var(--imm-rust); }
[data-cinematic-player] .imm-lines { display: flex; flex-direction: column; gap: .55rem; }
[data-cinematic-player] .imm-line { margin: 0; font-size: 1rem; line-height: 1.85; color: var(--imm-ink); overflow-wrap: anywhere; }
[data-cinematic-player] .imm-line b { font-weight: 900; color: var(--imm-rust); }

/* عناصر الخيارات داخل الورقة */
[data-cinematic-player] .imm-chips { margin: 0; padding: 0; list-style: none; display: flex; flex-wrap: wrap; gap: .5rem; }
[data-cinematic-player] .imm-chip { display: inline-flex; align-items: center; gap: .4rem; min-height: 2.75rem; padding: .4rem .95rem; border-radius: .85rem; border: 1px solid rgba(107,74,43,.55); background: rgba(255,250,236,.72); color: var(--imm-ink); font-size: .9rem; font-weight: 800; cursor: pointer; transition: transform .12s, background .15s, border-color .15s, box-shadow .15s; }
[data-cinematic-player] .imm-chip:hover { background: #fffaf0; border-color: var(--imm-rust); }
[data-cinematic-player] .imm-chip:active { transform: scale(.97); }
[data-cinematic-player] .imm-chip[disabled] { opacity: .45; cursor: not-allowed; }
[data-cinematic-player] .imm-chip.is-on { border-color: var(--imm-rust); background: #fff1d0; box-shadow: 0 0 0 2px rgba(154,52,18,.35); }
[data-cinematic-player] .imm-choices { margin: 0; padding: 0; list-style: none; display: grid; grid-template-columns: repeat(auto-fit, minmax(8.5rem, 1fr)); gap: .55rem; }
[data-cinematic-player] .imm-choice { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .2rem; width: 100%; min-height: 4.2rem; padding: .6rem .7rem; border-radius: .9rem; border: 1px solid rgba(107,74,43,.55); background: rgba(255,250,236,.72); color: var(--imm-ink); font-size: .88rem; font-weight: 800; line-height: 1.35; text-align: center; cursor: pointer; transition: transform .12s, background .15s, border-color .15s, box-shadow .15s; overflow-wrap: anywhere; }
[data-cinematic-player] .imm-choice:hover { background: #fffaf0; border-color: var(--imm-rust); }
[data-cinematic-player] .imm-choice:active { transform: scale(.98); }
[data-cinematic-player] .imm-choice[disabled] { opacity: .5; cursor: not-allowed; }
[data-cinematic-player] .imm-choice.is-on { border-color: var(--imm-rust); background: #fff1d0; box-shadow: 0 0 0 2px rgba(154,52,18,.4); }
[data-cinematic-player] .imm-choice .imm-sym { font-size: 1.6rem; line-height: 1; }
[data-cinematic-player] .imm-order { margin: 0; padding: 0; list-style: none; display: flex; flex-wrap: wrap; gap: .4rem; min-height: 2.5rem; }
[data-cinematic-player] .imm-order li { padding: .25rem .75rem; border-radius: 9999px; background: rgba(124,45,18,.12); border: 1px solid rgba(124,45,18,.4); font-size: .85rem; font-weight: 900; color: var(--imm-ink); }
[data-cinematic-player] .imm-note { margin: 0; font-size: .78rem; color: var(--imm-ink-soft); }
[data-cinematic-player] .imm-placed { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: .3rem; font-size: .84rem; color: var(--imm-ink); }
[data-cinematic-player] .imm-placed li { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
[data-cinematic-player] .imm-linkbtn { border: 0; background: none; padding: .3rem .4rem; color: #9f1239; font-size: .78rem; font-weight: 800; text-decoration: underline; cursor: pointer; min-height: 2rem; }
[data-cinematic-player] .imm-code { align-self: flex-start; margin: 0; padding: .6rem 1.2rem; border-radius: .9rem; border: 1px solid rgba(124,45,18,.55); background: #fff1d0; color: var(--imm-rust); font-size: 1.6rem; font-weight: 900; letter-spacing: .25em; direction: ltr; }
[data-cinematic-player] .imm-input { width: 100%; min-height: 2.9rem; padding: .6rem .9rem; border-radius: .8rem; border: 1px solid rgba(107,74,43,.6); background: #fffaf0; color: var(--imm-ink); font-size: 1rem; }
[data-cinematic-player] .imm-input::placeholder { color: #8b6a47; }
[data-cinematic-player] .imm-radio { display: flex; align-items: center; gap: .55rem; min-height: 2.75rem; padding: .45rem .8rem; border-radius: .8rem; border: 1px solid rgba(107,74,43,.5); background: rgba(255,250,236,.72); color: var(--imm-ink); font-weight: 800; cursor: pointer; }
[data-cinematic-player] .imm-puzzle-img { width: 100%; height: auto; max-height: 40dvh; object-fit: contain; border-radius: .7rem; }
[data-cinematic-player] .imm-ls { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
[data-cinematic-player] .imm-ls .imm-note { flex: 1 1 auto; }

/* الورقة داخل الورقة: أزرار على خلفية فاتحة */
[data-cinematic-player] .imm-paper .imm-btn { border-color: rgba(107,74,43,.6); background: rgba(255,250,236,.8); color: var(--imm-ink); }
[data-cinematic-player] .imm-paper .imm-btn:hover { background: #fffaf0; border-color: var(--imm-rust); }
[data-cinematic-player] .imm-paper .imm-btn-primary { border-color: #fbbf24; background: linear-gradient(160deg, #b45309, #7c2d12); color: #fff7ed; }
[data-cinematic-player] .imm-paper .imm-btn-primary:hover { background: linear-gradient(160deg, #c2610c, #8a3414); }

/* بديل لوحة المفاتيح لمناطق الصورة: مخفي بصريًا ويظهر عند التركيز */
[data-cinematic-player] .imm-kbd { margin: 0; padding: 0; list-style: none; display: flex; flex-wrap: wrap; gap: .4rem; position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
[data-cinematic-player] .imm-kbd:focus-within { position: static; width: auto; height: auto; overflow: visible; clip: auto; white-space: normal; padding: .4rem 0; }

/* التعليق والتغذية الراجعة */
[data-cinematic-player] .imm-feedback { display: flex; flex-direction: column; gap: .45rem; }
[data-cinematic-player] .imm-msg { display: flex; align-items: flex-start; gap: .6rem; margin: 0; padding: .65rem .85rem; border-radius: .9rem; font-size: .9rem; font-weight: 800; line-height: 1.6; animation: imm-enter .35s ease both; overflow-wrap: anywhere; }
[data-cinematic-player] .imm-msg svg { width: 1.2rem; height: 1.2rem; flex: 0 0 auto; margin-top: .2rem; }
[data-cinematic-player] .imm-msg small { display: block; margin-top: .15rem; font-size: .74rem; font-weight: 600; opacity: .85; }
[data-cinematic-player] .imm-msg.is-ok { background: rgba(5,46,36,.92); color: #d1fae5; border: 1px solid rgba(52,211,153,.55); box-shadow: 0 0 24px rgba(52,211,153,.2); }
[data-cinematic-player] .imm-msg.is-story { background: rgba(58,36,8,.92); color: #fff3cf; border: 1px solid rgba(245,196,81,.7); box-shadow: 0 0 28px rgba(245,196,81,.25); font-style: italic; }
[data-cinematic-player] .imm-msg.is-lock { background: rgba(58,42,5,.92); color: #fde68a; border: 1px solid rgba(251,191,36,.55); }
[data-cinematic-player] .imm-msg.is-no { background: rgba(59,18,25,.92); color: #fecdd3; border: 1px solid rgba(251,113,133,.5); }
[data-cinematic-player] .imm-msg.is-hint { background: rgba(15,23,42,.9); color: #e2e8f0; border: 1px solid rgba(148,163,184,.45); font-weight: 600; }
[data-cinematic-player] .imm-auto { height: 3px; border-radius: 9999px; background: rgba(255,255,255,.14); overflow: hidden; }
[data-cinematic-player] .imm-auto > span { display: block; height: 100%; background: var(--imm-gold); transition: width .12s linear; }

/* لوحة النص البديل للفيديو */
[data-cinematic-player] .imm-sheet { position: absolute; z-index: 30; inset-inline: calc(var(--imm-safe-l) + .6rem) calc(var(--imm-safe-r) + .6rem); bottom: calc(var(--imm-safe-b) + .6rem); max-height: 48dvh; overflow: auto; padding: .9rem 1rem; border-radius: 1.1rem; background: rgba(244,233,209,.97); color: var(--imm-ink); border: 1px solid rgba(169,122,58,.6); box-shadow: 0 18px 50px rgba(0,0,0,.6); animation: imm-sheet .35s ease both; }
[data-cinematic-player] .imm-sheet-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; margin-bottom: .5rem; }
[data-cinematic-player] .imm-sheet-head b { font-size: .95rem; font-weight: 900; color: var(--imm-rust); }
[data-cinematic-player] .imm-sheet .imm-btn { border-color: rgba(107,74,43,.6); background: rgba(255,250,236,.8); color: var(--imm-ink); }
[data-cinematic-player] .imm-hint-rotate { display: none; position: absolute; z-index: 5; inset-inline: 1rem; bottom: calc(var(--imm-safe-b) + 1rem); margin: 0; text-align: center; font-size: .75rem; color: rgba(203,213,225,.85); pointer-events: none; }

/* 6) درج أدوات المعاينة الإشرافية (للإدارة فقط) */
[data-cinematic-player] .imm-admin { position: absolute; z-index: 60; top: calc(var(--imm-safe-t) + var(--imm-hud-h)); inset-inline-end: calc(var(--imm-safe-r) + .6rem); width: min(27rem, calc(100% - var(--imm-safe-l) - var(--imm-safe-r) - 1.2rem)); max-height: calc(100% - var(--imm-safe-t) - var(--imm-hud-h) - var(--imm-safe-b) - .6rem); overflow: auto; display: flex; flex-direction: column; gap: .65rem; padding: .85rem; border-radius: 1rem; background: rgba(11,17,32,.97); color: #e2e8f0; border: 1px solid rgba(252,211,77,.4); box-shadow: 0 24px 70px rgba(0,0,0,.7); font-size: .8rem; animation: imm-enter .25s ease both; }
[data-cinematic-player] .imm-admin h2 { margin: 0; font-size: .9rem; font-weight: 900; color: #fde68a; }
[data-cinematic-player] .imm-admin .cin-pv-row { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; }
[data-cinematic-player] .imm-admin .cin-tag { display: inline-flex; align-items: center; padding: .1rem .55rem; border-radius: 9999px; font-size: .72rem; font-weight: 800; background: #1e293b; color: #f1f5f9; border: 1px solid #475569; }
[data-cinematic-player] .imm-admin .cin-tag.ok { background: #052e24; color: #6ee7b7; border-color: transparent; }
[data-cinematic-player] .imm-admin .cin-tag.warn { background: #3a2a05; color: #fde68a; border-color: transparent; }
[data-cinematic-player] .imm-admin .cin-tag.err { background: #3b1219; color: #fda4af; border-color: transparent; }
[data-cinematic-player] .imm-admin .cin-tbtn { display: inline-flex; align-items: center; min-height: 2.5rem; padding: .3rem .85rem; border-radius: .6rem; font-size: .8rem; font-weight: 800; background: #1e293b; color: #f8fafc; border: 1px solid #64748b; cursor: pointer; text-decoration: none; }
[data-cinematic-player] .imm-admin .cin-tbtn:hover { border-color: #c4b5fd; }
[data-cinematic-player] .imm-admin .cin-tbtn[disabled] { opacity: .5; cursor: not-allowed; }
[data-cinematic-player] .imm-admin .cin-tbtn.is-on { background: #78350f; border-color: #fcd34d; color: #fff7d6; }
[data-cinematic-player] .imm-admin p { margin: 0; color: #cbd5e1; line-height: 1.6; }
[data-cinematic-player] .imm-admin details { color: #e2e8f0; }
[data-cinematic-player] .imm-admin summary { cursor: pointer; font-weight: 800; min-height: 2rem; display: flex; align-items: center; }
[data-cinematic-player] .imm-admin ul { margin: .3rem 0 0; padding-inline-start: 1.2rem; list-style: disc; color: #cbd5e1; display: flex; flex-direction: column; gap: .2rem; }
[data-cinematic-player] .imm-admin dl { margin: .3rem 0 0; display: grid; grid-template-columns: auto 1fr; gap: .15rem .7rem; }
[data-cinematic-player] .imm-admin dt { color: #94a3b8; }
[data-cinematic-player] .imm-admin dd { margin: 0; color: #f1f5f9; direction: ltr; text-align: end; overflow-wrap: anywhere; }
[data-cinematic-player] .imm-admin select { background-color: #0f172a; color: #f8fafc; border: 1px solid #64748b; border-radius: .6rem; padding: .4rem .6rem; font-size: .8rem; color-scheme: dark; max-width: 100%; min-height: 2.5rem; width: 100%; }
[data-cinematic-player] .imm-admin select option, [data-cinematic-player] .imm-admin select optgroup { background-color: #0f172a; color: #f8fafc; }
[data-cinematic-player] .imm-admin select option:checked { background-color: #5b21b6; color: #ffffff; }
[data-cinematic-player] .imm-admin label { display: flex; flex-direction: column; gap: .3rem; font-weight: 800; color: #e2e8f0; }

/* تنبيه النسخة الأحدث */
[data-cinematic-player] .imm-upgrade { position: absolute; z-index: 45; top: calc(var(--imm-safe-t) + var(--imm-hud-h)); inset-inline: calc(var(--imm-safe-l) + .6rem) calc(var(--imm-safe-r) + .6rem); display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .6rem; padding: .6rem .9rem; border-radius: .9rem; background: rgba(46,16,101,.92); border: 1px solid rgba(167,139,250,.55); color: #ede9fe; font-size: .8rem; }

/* عمودي/ضيق: يبقى التخطيط الافتراضي (ورقة سفلية). فيديو عمودي: تلميح تدوير */
@media (orientation: portrait) { [data-cinematic-player] .imm-scene[data-imm-layout="media"] .imm-hint-rotate { display: block; } }

/* ورقة بلا فنّ على شاشة عمودية: بطاقة وحيدة في وسط الشاشة بدل ورقة سفلية فوق فراغ */
@media (orientation: portrait), (max-width: 720px) {
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="no"] .imm-stage { display: none; }
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="no"] .imm-paper { grid-row: 1 / -1; align-self: center; border-radius: 1.2rem; border-bottom: 1px solid rgba(169,122,58,.55); margin-inline: .6rem; max-height: min(78dvh, 34rem); }
}

/* أفقي عريض: الورقة عمود جانبي بجانب المسرح (عند الورقة) أو تعليق شفاف فوق المشهد (عند «المسرح») */
@media (min-width: 721px) and (orientation: landscape) {
    [data-cinematic-player] .imm-scene { padding-bottom: var(--imm-safe-b); grid-template-rows: minmax(0, 1fr); }
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"] { grid-template-columns: min(26rem, 38vw) minmax(0, 1fr); column-gap: .6rem; padding-inline-start: calc(var(--imm-safe-l) + .6rem); }
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"] .imm-stage { grid-column: 2; }
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"] .imm-paper { grid-column: 1; grid-row: 1; align-self: center; max-height: 100%; border-radius: 1.2rem; border-bottom: 1px solid rgba(169,122,58,.55); margin-block: .4rem; box-shadow: 0 18px 50px rgba(0,0,0,.55), inset 0 0 36px rgba(150,100,40,.22); padding-bottom: .95rem; }
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"] .imm-paper::before { display: none; }
    /* ورقة بلا فنّ: بطاقة وحيدة في وسط الشاشة */
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="no"] { grid-template-columns: minmax(0, 1fr); padding-inline-start: var(--imm-safe-l); }
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="no"] .imm-stage { display: none; }
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"][data-imm-art="no"] .imm-paper { grid-column: 1; justify-self: center; width: min(38rem, 92%); }

    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-stage { grid-area: 1 / 1; }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-paper { grid-area: 1 / 1; z-index: 12; padding: .5rem 1rem calc(var(--imm-safe-b) + .9rem); margin: 0; max-height: none; background: none; box-shadow: none; border: 0; border-radius: 0; color: var(--imm-text); justify-content: space-between; align-items: center; pointer-events: none; animation: none; }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-paper::before { display: none; }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-paper-body { flex: 0 0 auto; width: min(40rem, 100%); overflow: visible; padding: .6rem .95rem; border-radius: 1rem; background: rgba(6,10,20,.66); border: 1px solid rgba(255,255,255,.14); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); transition: opacity .8s ease; }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-paper-body.is-quiet { opacity: .0; }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-paper-body:hover, [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-paper-body:focus-within { opacity: 1; }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-paper-foot { width: min(40rem, 100%); }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-paper button, [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-paper a { pointer-events: auto; }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-prompt { color: var(--imm-text); font-size: 1.05rem; }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-sub, [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-help { color: var(--imm-muted); }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-help { background: rgba(255,255,255,.07); border-color: rgba(255,255,255,.25); }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-helpbtn { border-color: rgba(255,255,255,.35); color: var(--imm-muted); }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-dialog { color: var(--imm-text); border-inline-start-color: var(--imm-gold); }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-dialog b { color: var(--imm-gold); }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-note { color: var(--imm-muted); }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-paper .imm-btn { background: rgba(255,255,255,.1); color: var(--imm-text); border-color: rgba(255,255,255,.3); }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-paper .imm-btn-primary { background: linear-gradient(160deg, #b45309, #7c2d12); color: #fff7ed; border-color: #fbbf24; }
    [data-cinematic-player] .imm-scene[data-imm-layout="stage"] .imm-paper .imm-chip { background: rgba(255,255,255,.1); color: var(--imm-text); border-color: rgba(255,255,255,.3); }
}

/* جوال أفقي (ارتفاع قصير): أصغر وأقرب للحواف */
@media (orientation: landscape) and (max-height: 520px) {
    [data-cinematic-player].imm-root { --imm-hud-h: 2.75rem; }
    [data-cinematic-player] .imm-h1 { font-size: .9rem; }
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"] { grid-template-columns: min(22rem, 44vw) minmax(0, 1fr); }
    [data-cinematic-player] .imm-prompt { font-size: 1rem; }
    [data-cinematic-player] .imm-stage .cin-bigplay { width: 3.75rem; height: 3.75rem; }
    [data-cinematic-player] .imm-card { padding: 1rem; gap: .55rem; }
    [data-cinematic-player] .imm-sheet { max-height: 60dvh; }
}

/* شاشات كبيرة */
@media (min-width: 1400px) and (orientation: landscape) {
    [data-cinematic-player] .imm-scene[data-imm-layout="paper"] { grid-template-columns: min(28rem, 30vw) minmax(0, 1fr); }
    [data-cinematic-player] .imm-prompt { font-size: 1.25rem; }
}

/* جوال صغير */
@media (max-width: 380px) { [data-cinematic-player] .imm-paper { padding-inline: .8rem; } [data-cinematic-player] .imm-btn { padding-inline: .9rem; } [data-cinematic-player] .imm-choices { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

@media (prefers-reduced-motion: reduce) {
    [data-cinematic-player] .imm-scene, [data-cinematic-player] .imm-paper, [data-cinematic-player] .imm-card, [data-cinematic-player] .imm-msg, [data-cinematic-player] .imm-sheet, [data-cinematic-player] .imm-admin { animation: none; }
    [data-cinematic-player] .imm-stage .cin-bigplay, [data-cinematic-player] .imm-pulse.on { animation: none; }
    [data-cinematic-player] .imm-pulse.on { opacity: .9; }
    [data-cinematic-player] .imm-flash { animation: none; opacity: .35; }
    [data-cinematic-player] .imm-veil, [data-cinematic-player] .imm-hud, [data-cinematic-player] .imm-progress > span, [data-cinematic-player] .imm-backdrop::before, [data-cinematic-player] .imm-paper-body { transition: none; }
    [data-cinematic-player] .imm-stage .cin-stage-img { transition: none; }
    [data-cinematic-player] .imm-stage .cin-stage.is-done .cin-stage-img { transform: none; }
}
@endverbatim
</style>
