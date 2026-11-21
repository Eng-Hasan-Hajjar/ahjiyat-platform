{{--
    CIN-2 - سلوك أنواع التفاعل الجديدة (answer / inspect / multi_lock) وسجل الأدلة. PLM-3/4: board_move (رقعة) وinference (استنتاج + مصدره). يُدمج في مكوّن Alpine cinematicPlayer عبر window.cinStage2Mixin
    بنفس أسلوب window.cinImmersiveMixin. عرض وجمع مدخلات فقط:
      - لا حل ولا إجابة صحيحة ولا تلميح هنا: كل ذلك يأتي من الخادم بعد الإرسال (result.hint / result.reveal / result.detail).
      - التقدّم والمكافآت وحفظ الأدلة كلها على الخادم؛ سجل الأدلة يُستقبَل من الخادم (data.evidence) ولا يُحسب هنا.
      - الأرقام العربية/الفارسية تُقبل كما هي: التوحيد على الخادم (هنا يُمنع فقط ما ليس رقمًا في خانات القفل الرقمية).
--}}
<script data-cinematic-cin2-script>
@verbatim
(function () {
    var DIG = { '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9', '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9' };
    var clamp = function (v, a, b) { return Math.max(a, Math.min(b, v)); };
    var reduced = function () { try { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) { return false; } };

    window.cinStage2Mixin = function () {
        /* مؤشرات اللمس/الفأرة غير تفاعلية (لا تحتاج مراقبة Alpine) فتبقى في الإغلاق لكل نسخة مكوّن */
        var ptrs = {}; var pinch = null; var moved = false; var dragEnd = 0;
        var AR = '٠١٢٣٤٥٦٧٨٩';

        return {
            evidence: [], evOpen: false, evIndex: 0, evBig: false, tries: 0,
            inspected: [], flipped: false, zs: 1, zx: 0, zy: 0, dragging: false, seenId: null,
            lockVals: {}, lockMark: {},
            /* PLM-3/4: الرقعة والاستنتاج */
            bPending: null, bDrag: null, bFocus: null, bShake: false, infConclusion: '', infSources: [],

            /* ───── سجل الأدلة ───── */
            /* اللعب الحقيقي: القائمة من الخادم (أدلة مشاهد مكتملة فقط). المعاينة: أدلة المشاهد السابقة للمشهد الحالي (+ الحالي إن نجح) بلا حفظ. */
            get evidenceList() {
                if (!this.preview) { return Array.isArray(this.evidence) ? this.evidence : []; }
                var map = this.cfg.evidence_by_scene || {}; var ids = this.previewIds; var upto = this.previewIndex + (this.doneNow ? 1 : 0);
                return ids.slice(0, Math.max(0, upto)).map(function (id) { return map[id]; }).filter(Boolean);
            },
            get evCurrent() { var l = this.evidenceList; return l.length ? l[clamp(this.evIndex, 0, l.length - 1)] : null; },
            openEvidence() {
                var l = this.evidenceList; this.evIndex = Math.max(0, l.length - 1); this.evBig = false; this.evOpen = true;
                var self = this; this.$nextTick(function () { var b = self.$refs.evClose; if (b) { b.focus({ preventScroll: true }); } });
            },
            closeEvidence() {
                this.evOpen = false; this.evBig = false;
                var self = this; this.$nextTick(function () { var b = self.$refs.evToggle; if (b) { b.focus({ preventScroll: true }); } });
            },
            evStep(d) { var n = this.evidenceList.length; if (n) { this.evIndex = clamp(this.evIndex + d, 0, n - 1); this.evBig = false; } },
            markerStyle(m) { return m ? 'left:' + m.x + '%;top:' + m.y + '%;' : ''; },

            /* ───── إعادة التهيئة عند كل مشهد ───── */
            resetStage2() {
                this.inspected = []; this.flipped = false; this.zs = 1; this.zx = 0; this.zy = 0; this.dragging = false; this.seenId = null;
                this.lockVals = {}; this.lockMark = {}; this.tries = 0; ptrs = {}; pinch = null; moved = false;
                this.bPending = null; this.bDrag = null; this.bFocus = null; this.bShake = false; this.infConclusion = ''; this.infSources = []; dragEnd = 0;
            },

            /* ───── answer ───── */
            submitAnswer() {
                var v = String(this.answer || '').trim();
                if (!v || this.busy || this.doneNow) { return; }
                this.submit({ answer: v });
            },

            /* ───── inspect ───── */
            get inspectNeeded() { return this.ix && this.ix.required_count ? this.ix.required_count : 0; },
            get inspectReady() { return this.inspected.length >= this.inspectNeeded && this.inspectNeeded > 0; },
            /* ملاحظات ما فُحص بترتيب الفحص؛ الوجه الخلفي معرّف خاص back. */
            get inspectedNotes() {
                var self = this; var areas = (this.ix && this.ix.areas) || []; var back = this.ix ? this.ix.back : null;
                return this.inspected.map(function (id) {
                    if (id === 'back') { return back ? { id: 'back', label: back.label, note: back.text } : null; }
                    var a = areas.filter(function (x) { return x.id === id; })[0];
                    return a ? { id: a.id, label: a.label, note: a.note || '' } : null;
                }).filter(Boolean);
            },
            get inspectStyle() { return 'transform: translate(' + this.zx + 'px,' + this.zy + 'px) scale(' + this.zs + ');'; },
            markInspected(id) {
                if (this.doneNow) { return; }
                if (this.inspected.indexOf(id) === -1) { this.inspected = this.inspected.concat([id]); }
                this.seenId = id; this.nudge = '';
            },
            inspBox() { var st = this.$refs.inspectStage; return st ? st.getBoundingClientRect() : null; },
            clampPan() {
                var b = this.inspBox(); if (!b) { return; }
                this.zx = clamp(this.zx, b.width * (1 - this.zs), 0); this.zy = clamp(this.zy, b.height * (1 - this.zs), 0);
            },
            /* تكبير حول نقطة (إحداثيات داخل الإطار) مع تثبيت النقطة تحت الإصبع/المؤشر */
            zoomAt(factor, px, py) {
                var nz = clamp(this.zs * factor, 1, 4);
                if (nz === this.zs) { return; }
                var k = nz / this.zs;
                this.zx = px - (px - this.zx) * k; this.zy = py - (py - this.zy) * k; this.zs = nz;
                this.clampPan();
            },
            zoomCenter(factor) { var b = this.inspBox(); if (b) { this.zoomAt(factor, b.width / 2, b.height / 2); } },
            zoomReset() { this.zs = 1; this.zx = 0; this.zy = 0; },
            inspectWheel(ev) {
                if (this.flipped) { return; }
                var b = this.inspBox(); if (!b) { return; }
                this.zoomAt(ev.deltaY < 0 ? 1.2 : 1 / 1.2, ev.clientX - b.left, ev.clientY - b.top);
            },
            inspectDouble(ev) {
                if (this.flipped) { return; }
                var b = this.inspBox(); if (!b) { return; }
                if (this.zs > 1.05) { this.zoomReset(); } else { this.zoomAt(2.4, ev.clientX - b.left, ev.clientY - b.top); }
            },
            inspectKey(ev) {
                if (ev.ctrlKey || ev.metaKey || ev.altKey) { return; }
                var k = ev.key; var step = 40;
                if (k === '+' || k === '=') { ev.preventDefault(); this.zoomCenter(1.25); }
                else if (k === '-' || k === '_') { ev.preventDefault(); this.zoomCenter(1 / 1.25); }
                else if (k === '0') { ev.preventDefault(); this.zoomReset(); }
                else if (this.zs > 1 && k === 'ArrowLeft') { ev.preventDefault(); this.zx += step; this.clampPan(); }
                else if (this.zs > 1 && k === 'ArrowRight') { ev.preventDefault(); this.zx -= step; this.clampPan(); }
                else if (this.zs > 1 && k === 'ArrowUp') { ev.preventDefault(); this.zy += step; this.clampPan(); }
                else if (this.zs > 1 && k === 'ArrowDown') { ev.preventDefault(); this.zy -= step; this.clampPan(); }
            },
            /* المنطقة تحت نقطة (بإحداثيات الشاشة) مع مراعاة التكبير والإزاحة، وهامش لمس لا يقل عن 44px على الشاشة؛ الأقرب إلى المركز يفوز. */
            inspectAreaAt(cx, cy) {
                var b = this.inspBox(); if (!b || !b.width || !b.height || !this.ix || !this.ix.areas) { return null; }
                var s = this.zs; var px = (cx - b.left - this.zx) / s; var py = (cy - b.top - this.zy) / s; var best = null; var bd = Infinity;
                this.ix.areas.forEach(function (a) {
                    var x = a.x / 100 * b.width, y = a.y / 100 * b.height, w = a.w / 100 * b.width, h = a.h / 100 * b.height;
                    var padX = Math.max(0, (44 / s - w) / 2), padY = Math.max(0, (44 / s - h) / 2);
                    if (px >= x - padX && px <= x + w + padX && py >= y - padY && py <= y + h + padY) {
                        var d = Math.hypot(px - (x + w / 2), py - (y + h / 2));
                        if (d < bd) { best = a; bd = d; }
                    }
                });
                return best;
            },
            inspPointerDown(ev) {
                if (this.flipped || this.busy) { return; }
                var st = this.$refs.inspectStage; try { st.setPointerCapture(ev.pointerId); } catch (e) { /* لا بأس */ }
                ptrs[ev.pointerId] = { x: ev.clientX, y: ev.clientY, sx: ev.clientX, sy: ev.clientY };
                var ids = Object.keys(ptrs);
                if (ids.length === 1) { moved = false; }
                if (ids.length === 2) { pinch = { d: Math.hypot(ptrs[ids[0]].x - ptrs[ids[1]].x, ptrs[ids[0]].y - ptrs[ids[1]].y) }; moved = true; }
            },
            inspPointerMove(ev) {
                var p = ptrs[ev.pointerId];
                if (!p) {
                    if (ev.pointerType === 'mouse' && !this.flipped) { var h = this.inspectAreaAt(ev.clientX, ev.clientY); this.hoverId = h ? h.id : null; }
                    return;
                }
                var dx = ev.clientX - p.x, dy = ev.clientY - p.y; p.x = ev.clientX; p.y = ev.clientY;
                var ids = Object.keys(ptrs);
                if (ids.length >= 2 && pinch) {
                    var a = ptrs[ids[0]], c = ptrs[ids[1]]; var d = Math.hypot(a.x - c.x, a.y - c.y); var b = this.inspBox();
                    if (b && pinch.d > 0 && d > 0) { this.zoomAt(d / pinch.d, (a.x + c.x) / 2 - b.left, (a.y + c.y) / 2 - b.top); }
                    pinch.d = d; moved = true; return;
                }
                if (Math.hypot(ev.clientX - p.sx, ev.clientY - p.sy) > 6) { moved = true; }
                if (moved && this.zs > 1) { this.dragging = true; this.zx += dx; this.zy += dy; this.clampPan(); }
            },
            inspPointerUp(ev) {
                var p = ptrs[ev.pointerId]; delete ptrs[ev.pointerId];
                var left = Object.keys(ptrs).length;
                if (ev.type === 'pointerup' && p && !moved && left === 0 && !pinch && !this.flipped) { this.inspectTap(ev); }
                if (left < 2) { pinch = null; }
                if (left === 0) { this.dragging = false; moved = false; }
            },
            inspectTap(ev) {
                if (this.busy || this.doneNow) { return; }
                var a = this.inspectAreaAt(ev.clientX, ev.clientY);
                if (a) { this.markInspected(a.id); return; }
                this.nudge = 'لا شيء لافت هنا. كبّر الصورة وانقر على ما يلفت نظرك.';
            },
            flipPhoto() {
                if (!this.ix || !this.ix.back || this.doneNow) { return; }
                this.flipped = !this.flipped;
                if (this.flipped) { this.markInspected('back'); this.zoomReset(); }
            },
            finishInspect() {
                if (this.busy || this.doneNow || !this.inspectReady) { return; }
                this.submit({ inspected: this.inspected.slice() });
            },

            /* ───── multi_lock ───── */
            lockKey(lockId, i) { return lockId + ':' + i; },
            lockVal(lockId, i) { var v = this.lockVals[this.lockKey(lockId, i)]; return v === undefined ? '' : v; },
            setLockVal(lockId, i, v) { var o = Object.assign({}, this.lockVals); o[this.lockKey(lockId, i)] = v; this.lockVals = o; this.clearMark(lockId); },
            /* خانات الأرقام: تُقبل الأرقام العربية والفارسية والإنجليزية فقط (التوحيد النهائي على الخادم) */
            onDigits(lockId, i, len, ev) {
                var raw = String(ev.target.value || '').split('').map(function (ch) { return DIG[ch] !== undefined ? DIG[ch] : ch; }).join('');
                var clean = raw.replace(/[^0-9]/g, '').slice(0, len);
                if (ev.target.value !== clean) { ev.target.value = clean; }
                this.setLockVal(lockId, i, clean);
            },
            clearMark(lockId) { if (this.lockMark[lockId] !== undefined) { var m = Object.assign({}, this.lockMark); delete m[lockId]; this.lockMark = m; } },
            lockClass(lockId) { var m = this.lockMark[lockId]; return m === true ? 'is-ok' : (m === false ? 'is-no' : ''); },
            lockComplete(lock) {
                var self = this; return (lock.segments || []).every(function (sg, i) {
                    var v = String(self.lockVal(lock.id, i)); return sg.type === 'digits' ? v.length === sg.length : v !== '';
                });
            },
            get locksReady() { var self = this; return !!(this.ix && this.ix.locks) && this.ix.locks.every(function (l) { return self.lockComplete(l); }); },
            submitLocks() {
                if (this.busy || this.doneNow || !this.locksReady) { return; }
                var self = this; var values = {};
                this.ix.locks.forEach(function (l) { values[l.id] = (l.segments || []).map(function (sg, i) { return String(self.lockVal(l.id, i)); }); });
                this.submit({ values: values });
            },
            /* ───── PLM-3: board_move (رقعة + حصان) ─────
               الإحداثيات: الصف 1 في أسفل الرقعة والعمود 1 على يسارها (ثابتان: الشبكة dir=ltr والصفوف تُرسم تنازليًا). لا حل هنا: يُرسَل الموضع المختار فقط
               (row, col) والخادم يقرّر: أحد الآثار المعروضة + هو الأثر الصحيح + نقلة حصان مشروعة. عند الخطأ يعود الحصان إلى البداية (applyBoardResult). */
            arNum(n) { return String(n).replace(/[0-9]/g, function (d) { return AR[Number(d)]; }); },
            get bRows() { return this.ix && this.ix.board ? this.ix.board.rows : 8; },
            get bCols() { return this.ix && this.ix.board ? this.ix.board.cols : 8; },
            get bStart() { return this.ix && this.ix.piece ? { row: this.ix.piece.row, col: this.ix.piece.col } : { row: 1, col: 1 }; },
            get bPos() { return this.bPending || this.bStart; },
            get boardRowNumbers() { var a = []; for (var r = this.bRows; r >= 1; r--) { a.push(r); } return a; },
            get boardColNumbers() { var a = []; for (var c = 1; c <= this.bCols; c++) { a.push(c); } return a; },
            get boardSquares() {
                if (this.kind !== 'board_move' || !this.ix || !this.ix.board) { return []; }
                var out = []; var rows = this.bRows; var cols = this.bCols;
                for (var r = rows; r >= 1; r--) { for (var c = 1; c <= cols; c++) { out.push({ key: r + '-' + c, row: r, col: c, gr: rows - r + 1, gc: c, dark: (r + c) % 2 === 0 }); } }
                return out;
            },
            /* الآثار كأزرار بديلة مرتبة بصريًا (صف تنازليًا ثم عمود تصاعديًا) فلا يُكشف ترتيب التأليف */
            get boardTraceList() {
                return ((this.ix && this.ix.targets) || []).slice().sort(function (a, b) { return b.row - a.row || a.col - b.col; });
            },
            get knightStyle() {
                var p = this.bPos; var st = 'grid-row:' + (this.bRows - p.row + 1) + ';grid-column:' + p.col + ';';
                if (this.bDrag && this.bDrag.on) { st += 'transform:translate(' + this.bDrag.dx + 'px,' + this.bDrag.dy + 'px);'; }
                return st;
            },
            get boardStatus() {
                var s = this.bStart; var p = this.bPending;
                if (this.doneNow) { return 'استقرّ الحصان في موضعه الجديد.'; }
                if (!p) { return 'الحصان عند الصف ' + this.arNum(s.row) + ' والعمود ' + this.arNum(s.col) + '. اسحبه إلى الأثر المختار، أو المس المربع ثم اضغط «أكّد النقلة».'; }
                return 'الموضع المختار: الصف ' + this.arNum(p.row) + ' والعمود ' + this.arNum(p.col) + '. أكّد النقلة أو أعد الحصان.';
            },
            traceAt(r, c) { return !!(this.ix && this.ix.targets && this.ix.targets.some(function (t) { return t.row === r && t.col === c; })); },
            sqLabel(sq) {
                var s = this.bStart; var l = 'الصف ' + this.arNum(sq.row) + '، العمود ' + this.arNum(sq.col);
                if (this.traceAt(sq.row, sq.col)) { l += '، أثر محتمل'; }
                if (sq.row === this.bPos.row && sq.col === this.bPos.col) { l += '، الحصان هنا'; } else if (sq.row === s.row && sq.col === s.col) { l += '، موضع البداية'; }
                return l;
            },
            sqTab(sq) { var f = this.bFocus || this.bStart; return f.row === sq.row && f.col === sq.col ? 0 : -1; },
            placeAt(r, c) {
                if (this.busy || this.doneNow) { return; }
                var s = this.bStart; this.nudge = '';
                this.bPending = (r === s.row && c === s.col) ? null : { row: r, col: c };
                this.bFocus = { row: r, col: c };
            },
            sqClick(sq) { if (Date.now() - dragEnd < 350) { return; } this.placeAt(sq.row, sq.col); },
            boardReturn() { if (this.busy || this.doneNow) { return; } this.bPending = null; this.bDrag = null; this.bFocus = null; },
            confirmBoardMove() {
                if (this.busy || this.doneNow || !this.bPending) { return; }
                this.submit({ row: this.bPending.row, col: this.bPending.col });
            },
            /* الخطأ: يعود الحصان إلى البداية (الرسالة والتلميح من الخادم)؛ النجاح: يبقى في موضعه */
            applyBoardResult(result) {
                if (this.kind !== 'board_move' || !result) { return; }
                if (!result.correct) {
                    this.bPending = null; this.bDrag = null; this.bFocus = null; this.bShake = true;
                    var self = this; setTimeout(function () { self.bShake = false; }, 600);
                }
            },
            boardKey(ev) {
                var f = this.bFocus || this.bStart; var r = f.row; var c = f.col; var k = ev.key;
                if (k === 'ArrowUp') { r += 1; } else if (k === 'ArrowDown') { r -= 1; } else if (k === 'ArrowLeft') { c -= 1; } else if (k === 'ArrowRight') { c += 1; } else { return; }
                ev.preventDefault();
                r = clamp(r, 1, this.bRows); c = clamp(c, 1, this.bCols); this.bFocus = { row: r, col: c };
                var g = this.$refs.boardGrid; var self = this;
                this.$nextTick(function () { var el = g && g.querySelector('[data-cin34-sq][data-row="' + r + '"][data-col="' + c + '"]'); if (el) { el.focus({ preventScroll: true }); } });
            },
            /* مربع الرقعة تحت نقطة شاشة، بالحساب الهندسي لشبكة المربعات (بلا اعتماد على عنصر تحت المؤشر) */
            boardCellAt(cx, cy) {
                var g = this.$refs.boardGrid; if (!g) { return null; }
                var b = g.getBoundingClientRect();
                if (!b.width || !b.height || cx < b.left || cx >= b.right || cy < b.top || cy >= b.bottom) { return null; }
                var c = Math.floor((cx - b.left) / b.width * this.bCols) + 1; var r = this.bRows - Math.floor((cy - b.top) / b.height * this.bRows);
                return { row: clamp(r, 1, this.bRows), col: clamp(c, 1, this.bCols) };
            },
            knightDown(ev) {
                if (this.busy || this.doneNow || (ev.pointerType === 'mouse' && ev.button !== 0)) { return; }
                this.bDrag = { id: ev.pointerId, sx: ev.clientX, sy: ev.clientY, dx: 0, dy: 0, on: false };
                try { ev.currentTarget.setPointerCapture(ev.pointerId); } catch (e) { /* لا بأس */ }
            },
            knightMove(ev) {
                var d = this.bDrag; if (!d || d.id !== ev.pointerId) { return; }
                d.dx = ev.clientX - d.sx; d.dy = ev.clientY - d.sy;
                if (!d.on && Math.hypot(d.dx, d.dy) > 6) { d.on = true; }
            },
            knightUp(ev) {
                var d = this.bDrag; if (!d || d.id !== ev.pointerId) { return; }
                this.bDrag = null;
                if (!d.on) {
                    /* لمسة على الحصان نفسه: إن كان منقولًا مؤقتًا فتُعيده إلى البداية */
                    if (this.bPending) { this.bPending = null; }
                    return;
                }
                dragEnd = Date.now();
                var cell = this.boardCellAt(ev.clientX, ev.clientY);
                if (cell) { this.placeAt(cell.row, cell.col); }
            },
            knightCancel() { this.bDrag = null; },

            /* ───── PLM-4: inference (استنتاج + مصدره معًا) ───── */
            get infReady() { return !!this.infConclusion && this.infSources.length > 0; },
            get infGroups() {
                var groups = []; var idx = {};
                ((this.ix && this.ix.sources) || []).forEach(function (s) {
                    var t = s.group || 'المصادر';
                    if (idx[t] === undefined) { idx[t] = groups.length; groups.push({ title: t, items: [] }); }
                    groups[idx[t]].items.push(s);
                });
                return groups;
            },
            infPick(id) { if (this.busy || this.doneNow) { return; } this.infConclusion = id; },
            infToggle(id) {
                if (this.busy || this.doneNow) { return; }
                var i = this.infSources.indexOf(id);
                this.infSources = i === -1 ? this.infSources.concat([id]) : this.infSources.filter(function (x) { return x !== id; });
            },
            submitInference() {
                if (this.busy || this.doneNow || !this.infReady) { return; }
                /* لا مسح للاختيارات بعد الخطأ: يعدّل اللاعب ما يراه ناقصًا؛ الخادم لا يكشف أي نصف كان صحيحًا */
                this.submit({ conclusion: this.infConclusion, sources: this.infSources.slice() });
            },

            /* علامات كل قفل بعد الإرسال (من الخادم: result.detail) */
            applyLockDetail(result) {
                if (!result || !result.detail || typeof result.detail !== 'object') { return; }
                var m = {}; Object.keys(result.detail).forEach(function (k) { m[k] = result.detail[k] === true; });
                this.lockMark = m;
            }
        };
    };
})();
@endverbatim
</script>
