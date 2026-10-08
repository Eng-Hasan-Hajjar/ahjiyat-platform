@extends('layouts.app')

@section('title', 'تخصيص الهوية')
@section('content_width', 'max-w-5xl')

@section('content')
    @php
        use App\Models\StoreItem;
        use Illuminate\Support\Facades\Storage;

        $slotMeta = [
            StoreItem::SLOT_AVATAR => ['الصورة الرمزية', 'وجهك في المنصة: تظهر بالتنقل والملف والدردشة والترتيب.', 'user'],
            StoreItem::SLOT_FRAME => ['الإطار', 'إطار يحيط بصورتك الرمزية.', 'sparkles'],
            StoreItem::SLOT_BADGE => ['الشارة', 'شارة صغيرة تظهر بجانب اسمك.', 'trophy'],
            StoreItem::SLOT_TITLE => ['اللقب', 'عبارة قصيرة ملوّنة تحت اسمك.', 'pencil'],
            StoreItem::SLOT_BACKGROUND => ['الغلاف', 'الصورة العريضة خلف صورتك في أعلى ملفك الشخصي (الخلفية).', 'palette'],
        ];
        $urlOf = fn ($item) => $item && $item->image_path ? Storage::url($item->image_path) : null;
        $colorOf = fn ($item) => $item && preg_match(\App\Services\Store\StoreItemInvariantGuard::COLOR_PATTERN, (string) $item->cosmetic_color) ? $item->cosmetic_color : 'var(--color-accent-text)';
        $initial = [];
        foreach ($slots as $s) {
            $cur = $currentLoadout[$s];
            $initial[$s] = ['image' => $urlOf($cur), 'text' => $cur?->cosmetic_text, 'color' => $colorOf($cur), 'name' => $cur?->name];
        }
        $user = auth()->user();
    @endphp

    <div class="space-y-6" x-data="{
            tab: @js($slots[0] ?? 'avatar'),
            eq: @js($initial),
            pv: @js($initial),
            get dirty() { return JSON.stringify(this.pv) !== JSON.stringify(this.eq); },
            reset() { this.pv = JSON.parse(JSON.stringify(this.eq)); },
            show(slot, data) { this.pv[slot] = data; },
        }">

        @include('profile.partials.settings-tabs', ['active' => 'identity'])

        <div>
            <h1 class="font-display font-black text-2xl md:text-3xl text-white">تخصيص الهوية</h1>
            <p class="text-sm text-slate-400 mt-1">اختر من مقتنياتك ما يظهر به ملفك. جرّب «معاينة» أولًا؛ ولا يُحفظ شيء حتى تضغط «تجهيز».</p>
        </div>

        {{-- المعاينة الحيّة: نفس شكل غلاف الملف الشخصي. المعاينة بصرية فقط (لا طلب ولا تغيير حالة) حتى يُضغط «تجهيز». --}}
        <section class="rounded-3xl overflow-hidden glass" aria-label="معاينة الهوية">
            <div class="relative h-32 sm:h-44 bg-gradient-to-br from-amethyst/30 to-night-800" data-profile-cover
                :style="pv.{{ StoreItem::SLOT_BACKGROUND }}.image ? { backgroundImage: 'url(' + JSON.stringify(pv.{{ StoreItem::SLOT_BACKGROUND }}.image) + ')', backgroundSize: 'cover', backgroundPosition: 'center' } : {}">
                <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-night-950/60 via-transparent to-transparent" aria-hidden="true"></div>
                <span x-show="dirty" x-cloak class="absolute top-3 start-3 chip !py-1 !px-3 text-[11px] !text-gold">معاينة غير محفوظة</span>
            </div>
            <div class="px-5 sm:px-8 pb-5">
                <div class="flex items-end gap-4 -mt-10 sm:-mt-12">
                    <span class="relative inline-grid place-items-center shrink-0 w-20 h-20 sm:w-24 sm:h-24 rounded-full bg-night-900 ring-4 ring-[color:var(--color-bg)]">
                        <img x-show="pv.avatar.image" :src="pv.avatar.image" alt="" class="w-full h-full rounded-full object-cover">
                        <span x-show="!pv.avatar.image" class="gem-facet w-full h-full grid place-items-center text-3xl font-black text-white bg-gradient-to-br from-amethyst to-gold">{{ mb_substr($user->name, 0, 1) }}</span>
                        <img x-show="pv.{{ StoreItem::SLOT_FRAME }}.image" :src="pv.{{ StoreItem::SLOT_FRAME }}.image" alt="" class="pointer-events-none absolute inset-0 w-full h-full" style="aspect-ratio: 1 / 1;">
                    </span>
                    <div class="min-w-0 pb-1">
                        <div class="flex items-center gap-2">
                            <p class="font-display font-black text-xl sm:text-2xl text-white truncate">{{ $user->name }}</p>
                            <img x-show="pv.badge.image" :src="pv.badge.image" alt="" class="w-6 h-6 object-contain">
                        </div>
                        <p x-show="pv.title.text" x-text="pv.title.text" :style="{ color: pv.title.color }" class="text-sm font-bold"></p>
                    </div>
                    <button type="button" x-show="dirty" x-cloak @click="reset()" class="ms-auto chip !py-1.5 text-xs shrink-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">العودة للمجهَّز</button>
                </div>
            </div>
        </section>

        {{-- تبويبات الفتحات --}}
        <div role="tablist" aria-label="عناصر الهوية" class="flex gap-1 overflow-x-auto rounded-2xl bg-white/5 border border-white/10 p-1">
            @foreach ($slots as $s)
                <button type="button" role="tab" id="tab-{{ $s }}" aria-controls="panel-{{ $s }}" :aria-selected="(tab === '{{ $s }}').toString()" @click="tab = '{{ $s }}'"
                    class="inline-flex items-center gap-2 shrink-0 rounded-xl px-3.5 py-2 text-sm font-bold transition motion-reduce:transition-none focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"
                    :class="tab === '{{ $s }}' ? 'bg-amethyst/20 text-white' : 'text-slate-400 hover:text-white'">
                    <x-ui-icon :name="$slotMeta[$s][2]" class="w-4 h-4" />{{ $slotMeta[$s][0] }}
                    <span class="text-[10px] rounded-full bg-white/10 px-1.5 py-0.5">{{ $ownedCosmetics->get($s, collect())->count() }}</span>
                </button>
            @endforeach
        </div>

        @foreach ($slots as $s)
            @php
                $owned = $ownedCosmetics->get($s, collect());
                $current = $currentLoadout[$s];
            @endphp
            <section x-show="tab === '{{ $s }}'" @if (! $loop->first) x-cloak @endif role="tabpanel" id="panel-{{ $s }}" aria-labelledby="tab-{{ $s }}" data-slot="{{ $s }}">
                <div class="flex flex-wrap items-end justify-between gap-2 mb-4">
                    <div class="min-w-0">
                        <h2 class="font-display font-black text-lg text-white">{{ $slotMeta[$s][0] }}</h2>
                        <p class="text-xs text-slate-400">{{ $slotMeta[$s][1] }}</p>
                    </div>
                    <p class="text-xs font-bold {{ $current ? 'text-emerald' : 'text-slate-500' }}">
                        المجهَّز حاليًا: {{ $current ? $current->name : 'لا شيء' }}
                    </p>
                </div>

                @if ($owned->isEmpty())
                    <div class="glass rounded-2xl px-6 py-8 text-center">
                        <span class="mx-auto mb-3 grid place-items-center w-12 h-12 rounded-2xl bg-white/5 text-slate-400"><x-ui-icon :name="$slotMeta[$s][2]" class="w-6 h-6" /></span>
                        <p class="font-bold text-white text-sm mb-1">لا تملك أي عنصر لـ«{{ $slotMeta[$s][0] }}» بعد</p>
                        <p class="text-slate-400 text-xs mb-4">تُكتسب العناصر من المتجر أو من جوائز المنافسات، وتظهر هنا فور امتلاكها.</p>
                        <a href="{{ route('store.index') }}" class="btn-gem !py-2 !px-5 text-sm inline-flex focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">استكشف المتجر</a>
                    </div>
                @else
                    <div class="grid grid-cols-1 min-[420px]:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach ($owned as $cosmetic)
                            @php
                                $isEquipped = $current?->id === $cosmetic->id;
                                $data = ['image' => $urlOf($cosmetic), 'text' => $cosmetic->cosmetic_text, 'color' => $colorOf($cosmetic), 'name' => $cosmetic->name];
                            @endphp
                            <article class="puzzle-card flex flex-col gap-3 {{ $isEquipped ? '!border-amethyst' : '' }}" data-cosmetic="{{ $cosmetic->id }}">
                                <div class="grid place-items-center h-24 rounded-xl bg-white/5 overflow-hidden">
                                    @if ($s === StoreItem::SLOT_TITLE)
                                        <span class="text-base font-black px-2 text-center" style="color: {{ $colorOf($cosmetic) }};">{{ $cosmetic->cosmetic_text }}</span>
                                    @elseif ($cosmetic->image_path)
                                        <img src="{{ Storage::url($cosmetic->image_path) }}" alt="" class="{{ $s === StoreItem::SLOT_BACKGROUND ? 'w-full h-full object-cover' : 'max-h-20 max-w-[5rem] object-contain' }}">
                                    @endif
                                </div>

                                <div class="min-w-0">
                                    <p class="font-bold text-white text-sm truncate">{{ $cosmetic->name }}</p>
                                    @if ($isEquipped)
                                        <p class="text-[11px] font-bold text-emerald">مجهَّز حاليًا</p>
                                    @endif
                                </div>

                                <div class="mt-auto flex items-center gap-2">
                                    <button type="button" @click="show('{{ $s }}', @js($data))" class="chip !py-1.5 !px-3 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" aria-label="معاينة {{ $cosmetic->name }}">معاينة</button>

                                    @if ($isEquipped)
                                        <form method="POST" action="{{ route('profile.cosmetics.unequip', $s) }}" class="ms-auto">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="chip !py-1.5 !px-3 text-xs !text-rose focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" aria-label="إزالة {{ $cosmetic->name }}">إزالة</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('profile.cosmetics.equip', $cosmetic) }}" class="ms-auto">
                                            @csrf
                                            <button type="submit" class="btn-gem !py-1.5 !px-4 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" aria-label="تجهيز {{ $cosmetic->name }}">تجهيز</button>
                                        </form>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach
    </div>
@endsection
