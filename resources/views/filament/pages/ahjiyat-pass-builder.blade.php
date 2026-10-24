<x-filament-panels::page>
    @php
        $d = $this->data();
        $pass = $d['pass'];
        $editable = $d['editable'];
        $inputClass = 'rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm w-full disabled:opacity-60';
        $btn = 'rounded-lg bg-primary-600 text-white px-4 py-2 text-sm font-bold';
    @endphp

    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ \App\Filament\Pages\AhjiyatPassesPage::getUrl() }}" class="text-sm text-primary-600 font-bold">← المسارات</a>
        <span class="font-bold">{{ $pass->name }}</span>
        <span class="text-xs rounded-full px-2 py-0.5 {{ $editable ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">{{ $editable ? 'مسودة قابلة للتحرير' : 'منشور — مقفل' }}</span>
    </div>

    @if ($editable)
        @if (count($d['problems']) > 0)
            <div class="rounded-lg border border-amber-300 bg-amber-50 dark:bg-amber-500/10 p-3 text-xs space-y-1" data-builder-problems>
                <div class="font-bold">ما يمنع النشر الآن:</div>
                <ul class="list-disc ps-5">@foreach ($d['problems'] as $problem)<li>{{ $problem }}</li>@endforeach</ul>
            </div>
        @else
            <div class="rounded-lg border border-emerald-300 bg-emerald-50 dark:bg-emerald-500/10 p-3 text-xs font-bold" data-builder-ready>جاهز للنشر من صفحة «المسارات».</div>
        @endif
    @else
        <p class="text-xs text-gray-500">بعد النشر تُقفل النافذة والسعر والعملة والشرائح والمكافآت والمهام. يمكنك فقط تعديل النص والمظهر ونافذة بيع المميز (ضمن نافذة المسار).</p>
    @endif

    {{-- 1) الأساسيات --}}
    <section class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-builder-basics>
        <h3 class="font-bold">الأساسيات والمظهر والمميز</h3>
        <div class="grid gap-3 md:grid-cols-2">
            <label class="text-sm">الاسم<input type="text" wire:model="basics.name" maxlength="120" class="{{ $inputClass }}"></label>
            <label class="text-sm">العنوان الفرعي<input type="text" wire:model="basics.subtitle" maxlength="160" class="{{ $inputClass }}"></label>
            <label class="text-sm md:col-span-2">الوصف<textarea wire:model="basics.description" rows="2" maxlength="5000" class="{{ $inputClass }}"></textarea></label>
            <label class="text-sm">البداية<input type="datetime-local" wire:model="basics.starts_at" @disabled(! $editable) class="{{ $inputClass }}"></label>
            <label class="text-sm">النهاية<input type="datetime-local" wire:model="basics.ends_at" @disabled(! $editable) class="{{ $inputClass }}"></label>
            <label class="text-sm">أيام مهلة الاستلام<input type="number" wire:model="basics.claim_grace_days" @disabled(! $editable) min="0" max="{{ (int) config('ahjiyat_pass.lifecycle.claim_grace_max_days', 14) }}" class="{{ $inputClass }}"></label>
            <label class="text-sm flex items-center gap-2 mt-6"><input type="checkbox" wire:model="basics.is_featured">مميَّز في الواجهة</label>
            <label class="text-sm flex items-center gap-2"><input type="checkbox" wire:model="basics.premium_enabled" @disabled(! $editable)>المسار المميز مفعّل</label>
            <div class="grid grid-cols-2 gap-2">
                <label class="text-sm">السعر (وحدة صغرى، 999 = 9.99)<input type="number" wire:model="basics.premium_price_minor" @disabled(! $editable) min="0" class="{{ $inputClass }}" dir="ltr"></label>
                <label class="text-sm">العملة
                    <select wire:model="basics.currency" @disabled(! $editable) class="{{ $inputClass }}"><option value="">—</option>@foreach ($d['currencies'] as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
                </label>
            </div>
            <label class="text-sm">بدء بيع المميز (اختياري)<input type="datetime-local" wire:model="basics.premium_sales_start_at" class="{{ $inputClass }}"></label>
            <label class="text-sm">نهاية بيع المميز (اختياري)<input type="datetime-local" wire:model="basics.premium_sales_end_at" class="{{ $inputClass }}"></label>
            <label class="text-sm">اللون
                <select wire:model="basics.accent" class="{{ $inputClass }}">@foreach ($d['accents'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            </label>
            <label class="text-sm">الخلفية
                <select wire:model="basics.background" class="{{ $inputClass }}">@foreach ($d['backgrounds'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            </label>
            <label class="text-sm md:col-span-2">شعار نصي قصير<input type="text" wire:model="basics.tagline" maxlength="{{ (int) config('ahjiyat_pass.theme.tagline_max', 90) }}" class="{{ $inputClass }}"></label>
        </div>
        @if ($d['canManage'])
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" wire:click="saveBasics" class="{{ $btn }}">حفظ الأساسيات</button>
                <input type="file" wire:model="banner" accept="image/jpeg,image/png,image/webp" class="text-xs">
                <button type="button" wire:click="uploadBanner" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm">رفع البانر</button>
                @if ($pass->bannerUrl())<img src="{{ $pass->bannerUrl() }}" alt="" class="h-10 rounded">@endif
            </div>
            <p class="text-xs text-gray-500">البانر: JPEG/PNG/WEBP فقط (يُفحص نوع المحتوى والأبعاد، بلا SVG)، حتى {{ (int) config('ahjiyat_pass.banner.max_kb', 2048) }} ك.ب وعرض {{ (int) config('ahjiyat_pass.banner.min_width', 600) }}px على الأقل.</p>
        @endif
    </section>

    {{-- 2) الشرائح --}}
    <section class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-builder-tiers>
        <h3 class="font-bold">الشرائح والمكافآت</h3>
        <p class="text-xs text-gray-500">العتبات تراكمية تصاعدية صارمة (الشريحة 1 عادةً 0). مكافأة واحدة كحدّ أقصى لكل مسار (مجاني/مميز) لكل شريحة: عناصر تجميلية/وصول/رقمية غير معروضة للبيع فقط. لا جواهر ولا نقد.</p>

        @if ($editable && $d['canManage'])
            <div class="flex flex-wrap items-end gap-2" data-builder-generate>
                <label class="text-xs">عدد الشرائح<input type="number" wire:model="gen.count" min="1" max="{{ (int) config('ahjiyat_pass.limits.max_tiers', 100) }}" class="{{ $inputClass }} !w-24"></label>
                <label class="text-xs">خطوة النقاط<input type="number" wire:model="gen.step" min="1" class="{{ $inputClass }} !w-24"></label>
                <label class="text-xs">زيادة الخطوة<input type="number" wire:model="gen.ramp" min="0" class="{{ $inputClass }} !w-24"></label>
                <label class="text-xs">محطة كل<input type="number" wire:model="gen.milestone_every" min="0" class="{{ $inputClass }} !w-24"></label>
                <button type="button" wire:click="generate" wire:confirm="سيُستبدل ترتيب الشرائح بما يُولَّد (تُحفظ مكافآت الشرائح التي يبقى رقمها). متابعة؟" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-2 text-sm">توليد</button>
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-xs" data-builder-tier-table>
                <thead><tr class="text-gray-500"><th>#</th><th>النقاط</th><th>التسمية</th><th>محطة</th><th>مجاني</th><th>كمية</th><th>مميز</th><th>كمية</th></tr></thead>
                <tbody>
                @foreach ($tiers as $i => $t)
                    <tr class="border-t border-gray-100 dark:border-white/10" wire:key="tier-{{ $i }}">
                        <td class="text-center font-bold">{{ $i + 1 }}</td>
                        <td><input type="number" min="0" wire:model="tiers.{{ $i }}.points" @disabled(! $editable) class="{{ $inputClass }} !w-24" dir="ltr"></td>
                        <td><input type="text" maxlength="60" wire:model="tiers.{{ $i }}.label" @disabled(! $editable) class="{{ $inputClass }} !w-28"></td>
                        <td class="text-center"><input type="checkbox" wire:model="tiers.{{ $i }}.milestone" @disabled(! $editable)></td>
                        <td>
                            <select wire:model="tiers.{{ $i }}.free_item" @disabled(! $editable) class="{{ $inputClass }}"><option value="">—</option>@foreach ($d['items'] as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select>
                        </td>
                        <td><input type="number" min="1" max="{{ $d['maxQty'] }}" wire:model="tiers.{{ $i }}.free_qty" @disabled(! $editable) class="{{ $inputClass }} !w-16"></td>
                        <td>
                            <select wire:model="tiers.{{ $i }}.premium_item" @disabled(! $editable) class="{{ $inputClass }}"><option value="">—</option>@foreach ($d['items'] as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select>
                        </td>
                        <td><input type="number" min="1" max="{{ $d['maxQty'] }}" wire:model="tiers.{{ $i }}.premium_qty" @disabled(! $editable) class="{{ $inputClass }} !w-16"></td>
                    </tr>
                @endforeach
                @if (count($tiers) === 0)<tr><td colspan="8" class="py-3 text-gray-500">لا شرائح بعد. ولّدها أو أضف يدويًا.</td></tr>@endif
                </tbody>
            </table>
        </div>

        @if ($editable)
            <div class="flex flex-wrap gap-2">
                @if ($d['canManage'])
                    <button type="button" wire:click="addTier" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm">+ شريحة</button>
                    <button type="button" wire:click="removeLastTier" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm">− آخر شريحة</button>
                    <button type="button" wire:click="saveTiers" class="{{ $btn }}" data-builder-save-tiers>حفظ الشرائح</button>
                @endif
                @if ($d['canRewards'])
                    <button type="button" wire:click="saveRewards" class="{{ $btn }}" data-builder-save-rewards>حفظ المكافآت</button>
                @endif
            </div>
            <p class="text-xs text-gray-500">احفظ الشرائح أولًا ثم المكافآت (تُطبَّق على الشرائح المحفوظة بترتيبها).</p>
        @endif
    </section>

    {{-- 3) المهام --}}
    <section class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-builder-missions>
        <h3 class="font-bold">مهام المسار</h3>
        <p class="text-xs text-gray-500">المهمة تعريف عادي (QuestDefinition) يقيّمه محرك المهام نفسه؛ المسار يضيف نقاطه وسقف احتسابه فقط. الشرط للأحداث الرسمية فقط (حل أحجيات/حملات/تأهلات) ولا مهام ذاتية الإعلان. الصعوبة للتنظيم والعرض.</p>

        <table class="w-full text-xs">
            <thead><tr class="text-gray-500"><th class="text-start">المهمة</th><th>الصعوبة</th><th>النقاط</th><th>أقصى احتساب</th><th>أسبوع</th><th>بارزة</th><th>ترتيب</th><th></th></tr></thead>
            <tbody>
            @forelse ($missions as $i => $m)
                <tr class="border-t border-gray-100 dark:border-white/10" wire:key="mission-{{ $m['id'] }}">
                    <td class="text-start">{{ $m['name'] }}<div class="text-gray-500">{{ $m['period'] === 'daily' ? 'يومية' : 'أسبوعية' }} · الهدف {{ $m['target'] }}</div></td>
                    <td><select wire:model="missions.{{ $i }}.difficulty" @disabled(! $editable) class="{{ $inputClass }}">@foreach ($d['difficulties'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></td>
                    <td><input type="number" min="1" wire:model="missions.{{ $i }}.points" @disabled(! $editable) class="{{ $inputClass }} !w-20"></td>
                    <td><input type="number" min="1" max="{{ (int) config('ahjiyat_pass.limits.max_completions_cap', 14) }}" wire:model="missions.{{ $i }}.max" @disabled(! $editable) class="{{ $inputClass }} !w-16"></td>
                    <td><input type="number" min="1" max="20" wire:model="missions.{{ $i }}.week" @disabled(! $editable) class="{{ $inputClass }} !w-16"></td>
                    <td class="text-center"><input type="checkbox" wire:model="missions.{{ $i }}.featured" @disabled(! $editable)></td>
                    <td><input type="number" min="0" wire:model="missions.{{ $i }}.sort" @disabled(! $editable) class="{{ $inputClass }} !w-16"></td>
                    <td>@if ($editable && $d['canMissions'])<button type="button" wire:click="detach({{ $m['id'] }})" wire:confirm="فصل المهمة عن المسار؟" class="text-red-600 font-bold">فصل</button>@endif</td>
                </tr>
            @empty
                <tr><td colspan="8" class="py-3 text-gray-500">لا مهام مرتبطة.</td></tr>
            @endforelse
            </tbody>
        </table>

        @if ($editable && $d['canMissions'])
            @if (count($missions) > 0)<button type="button" wire:click="saveMissions" class="{{ $btn }}">حفظ المهام</button>@endif

            <div class="rounded-lg border border-gray-200 dark:border-white/10 p-3 space-y-2" data-builder-attach>
                <div class="text-sm font-bold">ربط مهمة قائمة</div>
                <div class="grid gap-2 md:grid-cols-5">
                    <select wire:model="attach.quest" class="{{ $inputClass }} md:col-span-2"><option value="">اختر مهمة…</option>@foreach ($d['quests'] as $q)<option value="{{ $q->id }}">{{ $q->name }} ({{ $q->period_type === 'daily' ? 'يومية' : 'أسبوعية' }})</option>@endforeach</select>
                    <select wire:model="attach.difficulty" class="{{ $inputClass }}">@foreach ($d['difficulties'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                    <input type="number" wire:model="attach.points" min="1" placeholder="نقاط (افتراضي حسب الصعوبة)" class="{{ $inputClass }}">
                    <input type="number" wire:model="attach.max" min="1" max="{{ (int) config('ahjiyat_pass.limits.max_completions_cap', 14) }}" placeholder="أقصى احتساب" class="{{ $inputClass }}">
                </div>
                <button type="button" wire:click="attachQuest" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm">ربط</button>
            </div>

            <div class="rounded-lg border border-gray-200 dark:border-white/10 p-3 space-y-2" data-builder-new-quest>
                <div class="text-sm font-bold">إنشاء مهمة جديدة للمسار (بلا مكافأة خبرة أو عملة؛ المكافأة نقاط المسار فقط)</div>
                <div class="grid gap-2 md:grid-cols-3">
                    <input type="text" wire:model="nq.name" maxlength="120" placeholder="اسم المهمة" class="{{ $inputClass }}">
                    <input type="text" wire:model="nq.description" maxlength="500" placeholder="وصف قصير" class="{{ $inputClass }}">
                    <select wire:model="nq.period_type" class="{{ $inputClass }}"><option value="daily">يومية</option><option value="weekly">أسبوعية</option></select>
                    <select wire:model.live="nq.condition_type" class="{{ $inputClass }}">@foreach ($d['conditions'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                    <input type="number" wire:model="nq.target_value" min="1" placeholder="الهدف" class="{{ $inputClass }}">
                    @if ($nq['condition_type'] === \App\Services\Engagement\QuestEvaluatorRegistry::PUZZLES_SOLVED_IN_CATEGORY)
                        <select wire:model="nq.scope_id" class="{{ $inputClass }}"><option value="">التصنيف…</option>@foreach ($d['categories'] as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                    @endif
                    <select wire:model="nq.difficulty" class="{{ $inputClass }}">@foreach ($d['difficulties'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                    <input type="number" wire:model="nq.points" min="1" placeholder="نقاط المسار" class="{{ $inputClass }}">
                    <input type="number" wire:model="nq.max" min="1" max="{{ (int) config('ahjiyat_pass.limits.max_completions_cap', 14) }}" placeholder="أقصى احتساب" class="{{ $inputClass }}">
                </div>
                <button type="button" wire:click="createQuest" class="rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm">إنشاء وربط</button>
            </div>
        @endif
    </section>

    {{-- 4) المعاينة --}}
    <section class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-2" data-builder-preview>
        <h3 class="font-bold">معاينة المسارين</h3>
        <div class="grid grid-cols-[3rem_1fr_1fr] gap-2 text-xs">
            <div class="font-bold text-gray-500">#</div><div class="font-bold text-gray-500">المجاني</div><div class="font-bold text-gray-500">المميز</div>
            @php($itemNames = $d['items']->pluck('name', 'id'))
            @foreach ($tiers as $i => $t)
                <div class="text-center">{{ $i + 1 }}</div>
                <div>{{ $t['free_item'] !== '' ? ($itemNames[(int) $t['free_item']] ?? '…').($t['free_qty'] > 1 ? ' ×'.$t['free_qty'] : '') : '—' }}</div>
                <div>{{ $t['premium_item'] !== '' ? ($itemNames[(int) $t['premium_item']] ?? '…').($t['premium_qty'] > 1 ? ' ×'.$t['premium_qty'] : '') : '—' }}</div>
            @endforeach
        </div>
        <p class="text-xs text-gray-500">أقصى نقاط ممكنة من المهام الحالية: {{ number_format(collect($missions)->sum(fn ($m) => (int) $m['points'] * (int) $m['max'])) }} — عتبة آخر شريحة: {{ number_format((int) (count($tiers) ? end($tiers)['points'] : 0)) }}.</p>
    </section>
</x-filament-panels::page>
