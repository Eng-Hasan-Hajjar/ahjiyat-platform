@php
    $statePath = $getStatePath();
    $modules = collect($getModules())->map(fn ($module, $key) => [
        'key' => $key,
        'label' => $module['label'],
        'permissions' => collect($module['permissions'])->map(fn ($label, $name) => ['name' => $name, 'label' => $label])->values()->all(),
    ])->values()->all();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="{
            selected: $wire.$entangle('{{ $statePath }}'),
            modules: @js($modules),
            search: '',
            collapsed: {},

            init() {
                if (! Array.isArray(this.selected)) this.selected = [];
            },

            isChecked(name) {
                return this.selected.includes(name);
            },

            toggle(name) {
                const i = this.selected.indexOf(name);
                if (i === -1) this.selected.push(name);
                else this.selected.splice(i, 1);
            },

            moduleNames(module) {
                return module.permissions.map(p => p.name);
            },

            countInModule(module) {
                return this.moduleNames(module).filter(n => this.selected.includes(n)).length;
            },

            selectAllInModule(module) {
                this.moduleNames(module).forEach(n => { if (! this.selected.includes(n)) this.selected.push(n); });
            },

            clearModule(module) {
                this.selected = this.selected.filter(n => ! this.moduleNames(module).includes(n));
            },

            selectAllReadGlobally() {
                this.modules.forEach(m => m.permissions.forEach(p => {
                    if (p.name.endsWith('.view') && ! this.selected.includes(p.name)) this.selected.push(p.name);
                }));
            },

            clearAll() {
                if (confirm('مسح كل الصلاحيات المحددة؟')) this.selected = [];
            },

            toggleCollapse(key) {
                this.collapsed[key] = ! this.collapsed[key];
            },

            matchesSearch(module) {
                if (! this.search.trim()) return true;
                const q = this.search.trim().toLowerCase();
                if (module.label.toLowerCase().includes(q)) return true;
                return module.permissions.some(p => p.label.toLowerCase().includes(q) || p.name.toLowerCase().includes(q));
            },

            permissionMatchesSearch(permission) {
                if (! this.search.trim()) return true;
                const q = this.search.trim().toLowerCase();
                return permission.label.toLowerCase().includes(q) || permission.name.toLowerCase().includes(q);
            },
        }"
        class="permission-matrix"
    >
        <div class="permission-matrix-toolbar">
            <input type="text" x-model="search" placeholder="ابحث عن صلاحية أو وحدة..." class="permission-matrix-search">
            <span class="permission-matrix-counter"><strong x-text="selected.length"></strong> صلاحية محددة</span>
            <button type="button" @click="selectAllReadGlobally()" class="permission-matrix-btn">تحديد كل صلاحيات العرض</button>
            <button type="button" @click="clearAll()" class="permission-matrix-btn permission-matrix-btn-danger">مسح الكل</button>
        </div>

        <div class="permission-matrix-modules">
            <template x-for="module in modules" :key="module.key">
                <div class="permission-matrix-module" x-show="matchesSearch(module)">
                    <button type="button" class="permission-matrix-module-header" @click="toggleCollapse(module.key)">
                        <span class="permission-matrix-module-title">
                            <span x-text="collapsed[module.key] ? '▸' : '▾'"></span>
                            <span x-text="module.label"></span>
                        </span>
                        <span class="permission-matrix-module-count">
                            <span x-text="countInModule(module)"></span> / <span x-text="module.permissions.length"></span>
                        </span>
                    </button>

                    <div x-show="! collapsed[module.key]" class="permission-matrix-module-body">
                        <div class="permission-matrix-module-actions">
                            <button type="button" @click="selectAllInModule(module)" class="permission-matrix-mini-btn">تحديد الكل بالوحدة</button>
                            <button type="button" @click="clearModule(module)" class="permission-matrix-mini-btn">إلغاء تحديد الوحدة</button>
                        </div>

                        <div class="permission-matrix-grid">
                            <template x-for="permission in module.permissions" :key="permission.name">
                                <label class="permission-matrix-item" x-show="permissionMatchesSearch(permission)">
                                    <input type="checkbox" :checked="isChecked(permission.name)" @change="toggle(permission.name)">
                                    <span>
                                        <span class="permission-matrix-item-label" x-text="permission.label"></span>
                                        <span class="permission-matrix-item-hint" x-text="permission.name"></span>
                                    </span>
                                </label>
                            </template>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</x-dynamic-component>

<style>
    .permission-matrix-toolbar {
        display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; margin-bottom: 1rem;
    }
    .permission-matrix-search {
        flex: 1 1 220px; padding: .5rem .8rem; border-radius: .6rem;
        border: 1px solid rgba(148,163,184,.3); background: rgba(255,255,255,.04); color: inherit;
    }
    .permission-matrix-counter { font-size: .8rem; font-weight: 800; color: #94a3b8; white-space: nowrap; }
    .permission-matrix-btn, .permission-matrix-mini-btn {
        padding: .4rem .9rem; border-radius: .6rem; border: 1px solid rgba(148,163,184,.3);
        background: rgba(139,92,246,.12); color: inherit; font-size: .78rem; font-weight: 700; cursor: pointer; white-space: nowrap;
    }
    .permission-matrix-btn-danger { background: rgba(251,113,133,.12); border-color: rgba(251,113,133,.35); }
    .permission-matrix-modules { display: flex; flex-direction: column; gap: .6rem; }
    .permission-matrix-module {
        border: 1px solid rgba(148,163,184,.2); border-radius: .8rem; overflow: hidden;
    }
    .permission-matrix-module-header {
        width: 100%; display: flex; align-items: center; justify-content: space-between;
        padding: .7rem 1rem; background: rgba(255,255,255,.03); border: none; cursor: pointer; color: inherit; font-weight: 800;
    }
    .permission-matrix-module-count { font-size: .75rem; color: #94a3b8; font-weight: 700; }
    .permission-matrix-module-body { padding: .8rem 1rem 1rem; }
    .permission-matrix-module-actions { display: flex; gap: .5rem; margin-bottom: .7rem; }
    .permission-matrix-mini-btn { padding: .3rem .7rem; font-size: .72rem; }
    .permission-matrix-grid {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: .5rem .8rem;
    }
    .permission-matrix-item {
        display: flex; align-items: flex-start; gap: .5rem; padding: .35rem 0; cursor: pointer; font-size: .85rem;
    }
    .permission-matrix-item-label { display: block; font-weight: 700; }
    .permission-matrix-item-hint { display: block; font-size: .68rem; color: #64748b; direction: ltr; text-align: right; }

    @media (max-width: 640px) {
        .permission-matrix-grid { grid-template-columns: 1fr; }
        .permission-matrix-toolbar { flex-direction: column; align-items: stretch; }
    }
</style>