{{--
    محرِّر الفروق المرئي (Generic) - نقر بدل إحداثيات يدوية. CSS خام مضمَّن
    بأسفل الملف (لا Tailwind Utility جديدة - Filament تُصدِر CSS مُجمَّعة
    سلفاً لا تعرف عن أصناف مضافة بواجهات مخصَّصة) - يعمل فوراً بلا Build.
--}}
@php
    $statePath = $getStatePath();
    $imagePath = $get($getImageStatePath());
    $imageUrl = $imagePath ? \Illuminate\Support\Facades\Storage::disk('public')->url($imagePath) : null;

    $beforePath = $getBeforeImageStatePath() ? $get($getBeforeImageStatePath()) : null;
    $beforeUrl = $beforePath ? \Illuminate\Support\Facades\Storage::disk('public')->url($beforePath) : null;
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="{
            hotspots: $wire.$entangle('{{ $statePath }}'),
            selectedIndex: null,
            draggingIndex: null,
            radiusPresets: { small: 0.035, medium: 0.05, large: 0.075 },

            init() {
                if (! Array.isArray(this.hotspots)) {
                    this.hotspots = [];
                }
            },

            relativePoint(event, container) {
                const rect = container.getBoundingClientRect();
                const x = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
                const y = Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height));
                return [Math.round(x * 1000) / 1000, Math.round(y * 1000) / 1000];
            },

            addHotspot(event) {
                if (this.draggingIndex !== null) return;
                const [x, y] = this.relativePoint(event, event.currentTarget);
                this.hotspots.push({ x, y, radius: this.radiusPresets.medium });
                this.selectedIndex = this.hotspots.length - 1;
            },

            selectHotspot(index) {
                this.selectedIndex = (this.selectedIndex === index) ? null : index;
            },

            startDrag(index, event) {
                event.stopPropagation();
                this.selectedIndex = index;
                this.draggingIndex = index;
            },

            dragMove(event) {
                if (this.draggingIndex === null) return;
                const [x, y] = this.relativePoint(event, event.currentTarget);
                this.hotspots[this.draggingIndex].x = x;
                this.hotspots[this.draggingIndex].y = y;
            },

            endDrag() {
                this.draggingIndex = null;
            },

            removeHotspot(index) {
                this.hotspots.splice(index, 1);
                this.selectedIndex = null;
            },

            clearAll() {
                if (this.hotspots.length && confirm('هل أنت متأكد من حذف كل الفروق المحدَّدة؟')) {
                    this.hotspots = [];
                    this.selectedIndex = null;
                }
            },

            setRadius(index, preset) {
                this.hotspots[index].radius = this.radiusPresets[preset];
            },

            radiusPresetOf(radius) {
                if (radius <= 0.0425) return 'small';
                if (radius <= 0.0625) return 'medium';
                return 'large';
            },

            markerStyle(hotspot) {
                const diameterPct = Math.min(60, hotspot.radius * 200);
                return `left: ${hotspot.x * 100}%; top: ${hotspot.y * 100}%; width: ${diameterPct}%; height: ${diameterPct}%;`;
            },
        }"
        class="hotspot-editor"
    >
        <div class="hotspot-editor-images">
            @if ($beforeUrl)
                <div class="hotspot-editor-before-wrap">
                    <span class="hotspot-editor-image-label">قبل</span>
                    <img src="{{ $beforeUrl }}" class="hotspot-editor-before-image" alt="" draggable="false">
                </div>
            @endif

            <div class="hotspot-editor-after-wrap">
                @if ($beforeUrl)
                    <span class="hotspot-editor-image-label">بعد (حدِّد الفروق هنا)</span>
                @endif

                @if (! $imageUrl)
                    <div class="hotspot-editor-empty">
                        📷 ارفع الصورة الثانية (فيها الفروق) أولاً لتتمكن من تحديد الفروقات.
                    </div>
                @else
                    <div
                        class="hotspot-editor-stage"
                        @click="addHotspot($event)"
                        @mousemove="dragMove($event)"
                        @mouseup="endDrag()"
                        @mouseleave="endDrag()"
                    >
                        <img src="{{ $imageUrl }}" class="hotspot-editor-image" alt="" draggable="false">

                        <template x-for="(hotspot, index) in hotspots" :key="index">
                            <button
                                type="button"
                                class="hotspot-editor-marker"
                                :class="{ 'is-selected': selectedIndex === index }"
                                :style="markerStyle(hotspot)"
                                @click.stop="selectHotspot(index)"
                                @mousedown="startDrag(index, $event)"
                                x-text="index + 1"
                            ></button>
                        </template>
                    </div>
                @endif
            </div>
        </div>

        @if ($imageUrl)
            <div class="hotspot-editor-toolbar">
                <span class="hotspot-editor-count">
                    <strong x-text="hotspots.length"></strong> فروق محدَّدة (اللاعب سيحتاج إيجادها كلها للفوز)
                </span>

                <button type="button" class="hotspot-editor-clear-btn" @click="clearAll()" x-show="hotspots.length > 0">
                    مسح الكل
                </button>
            </div>

            <template x-if="selectedIndex !== null && hotspots[selectedIndex]">
                <div class="hotspot-editor-inspector">
                    <span class="hotspot-editor-inspector-title">
                        الفرق رقم <span x-text="selectedIndex + 1"></span> — حجم منطقة الالتقاط
                    </span>

                    <div class="hotspot-editor-radius-buttons">
                        <button type="button"
                            :class="{ 'is-active': radiusPresetOf(hotspots[selectedIndex].radius) === 'small' }"
                            @click="setRadius(selectedIndex, 'small')">صغير</button>
                        <button type="button"
                            :class="{ 'is-active': radiusPresetOf(hotspots[selectedIndex].radius) === 'medium' }"
                            @click="setRadius(selectedIndex, 'medium')">متوسط</button>
                        <button type="button"
                            :class="{ 'is-active': radiusPresetOf(hotspots[selectedIndex].radius) === 'large' }"
                            @click="setRadius(selectedIndex, 'large')">كبير</button>
                    </div>

                    <span class="hotspot-editor-technical-hint" x-text="'radius: ' + hotspots[selectedIndex].radius"></span>

                    <button type="button" class="hotspot-editor-delete-btn" @click="removeHotspot(selectedIndex)">
                        حذف هذا الفرق
                    </button>
                </div>
            </template>

            <p class="hotspot-editor-help">
                انقر أي مكان بالصورة لإضافة فرق جديد. انقر فرقاً موجوداً لتحديده وتعديل حجمه، أو اسحبه لتغيير موقعه.
            </p>
        @endif
    </div>
</x-dynamic-component>

<style>
    .hotspot-editor { margin-top: .5rem; }
    .hotspot-editor-images {
        display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-start;
    }
    .hotspot-editor-before-wrap, .hotspot-editor-after-wrap {
        flex: 1 1 260px; min-width: 220px;
    }
    .hotspot-editor-image-label {
        display: block; font-size: .75rem; font-weight: 800; color: #94a3b8; margin-bottom: .35rem;
    }
    .hotspot-editor-before-image {
        display: block; max-width: 100%; height: auto; border-radius: .75rem;
        border: 1px solid rgba(148, 163, 184, .25);
    }
    .hotspot-editor-empty {
        padding: 2rem 1rem; text-align: center; color: #94a3b8; font-weight: 700;
        border: 1px dashed rgba(148, 163, 184, .35); border-radius: .75rem; background: rgba(148, 163, 184, .06);
    }
    .hotspot-editor-stage {
        position: relative; display: inline-block; max-width: 100%; cursor: crosshair;
        border-radius: .75rem; overflow: hidden; border: 1px solid rgba(148, 163, 184, .25); user-select: none;
    }
    .hotspot-editor-image { display: block; max-width: 100%; height: auto; pointer-events: none; }
    .hotspot-editor-marker {
        position: absolute; margin-left: 0; margin-top: 0; transform: translate(-50%, -50%);
        min-width: 1.5rem; min-height: 1.5rem;
        border-radius: 9999px; background: rgba(139, 92, 246, .55); border: 2px solid #fff;
        color: #fff; font-weight: 800; font-size: .75rem; line-height: 1;
        display: flex; align-items: center; justify-content: center; cursor: move;
        box-shadow: 0 2px 8px rgba(0,0,0,.35);
    }
    .hotspot-editor-marker.is-selected { background: rgba(252, 211, 77, .65); color: #1e1b2e; }
    .hotspot-editor-toolbar {
        display: flex; align-items: center; justify-content: space-between; gap: .75rem;
        margin-top: .75rem; font-size: .85rem; color: #cbd5e1;
    }
    .hotspot-editor-clear-btn, .hotspot-editor-delete-btn {
        padding: .35rem .85rem; border-radius: .5rem; border: 1px solid rgba(251, 113, 133, .4);
        background: rgba(251, 113, 133, .1); color: #fb7185; font-weight: 700; font-size: .8rem; cursor: pointer;
    }
    .hotspot-editor-inspector {
        margin-top: .75rem; padding: .85rem 1rem; border-radius: .75rem;
        background: rgba(139, 92, 246, .08); border: 1px solid rgba(139, 92, 246, .25);
        display: flex; flex-wrap: wrap; align-items: center; gap: .75rem;
    }
    .hotspot-editor-inspector-title { font-weight: 800; color: #e2e8f0; font-size: .85rem; }
    .hotspot-editor-radius-buttons { display: flex; gap: .4rem; }
    .hotspot-editor-radius-buttons button {
        padding: .35rem .75rem; border-radius: .5rem; border: 1px solid rgba(148, 163, 184, .3);
        background: rgba(255,255,255,.04); color: #cbd5e1; font-size: .8rem; font-weight: 700; cursor: pointer;
    }
    .hotspot-editor-radius-buttons button.is-active {
        background: rgba(139, 92, 246, .35); border-color: rgba(139, 92, 246, .6); color: #fff;
    }
    .hotspot-editor-technical-hint { font-size: .7rem; color: #64748b; direction: ltr; }
    .hotspot-editor-help { margin-top: .6rem; font-size: .78rem; color: #64748b; }
</style>