@extends('layouts.app')

@section('title', 'تخصيص الهوية')

@section('content')

    <div class="max-w-4xl mx-auto">

        <a href="{{ route('profile.edit') }}" class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-white transition mb-4 anim-fade-up">
            → الملف الشخصي
        </a>

        <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-6 anim-fade-up">تخصيص الهوية</h1>

        @if (session('success'))
            <div class="rounded-xl bg-emerald/10 border border-emerald/30 text-emerald text-sm font-bold px-4 py-3 mb-5">
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="rounded-xl bg-rose/10 border border-rose/30 text-rose text-sm font-bold px-4 py-3 mb-5">
                {{ session('error') }}
            </div>
        @endif

        <div class="mb-8 anim-fade-up d-1">
            <x-player-identity
                :name="auth()->user()->name"
                :avatar="$currentLoadout[\App\Models\StoreItem::SLOT_AVATAR]"
                :frame="$currentLoadout[\App\Models\StoreItem::SLOT_FRAME]"
                :badge="$currentLoadout[\App\Models\StoreItem::SLOT_BADGE]"
                :title="$currentLoadout[\App\Models\StoreItem::SLOT_TITLE]"
                :background="$currentLoadout[\App\Models\StoreItem::SLOT_BACKGROUND]"
            />
        </div>

        @php
            $slotLabels = [
                \App\Models\StoreItem::SLOT_AVATAR => 'الصورة الرمزية',
                \App\Models\StoreItem::SLOT_FRAME => 'الإطار',
                \App\Models\StoreItem::SLOT_BADGE => 'الشارة',
                \App\Models\StoreItem::SLOT_TITLE => 'اللقب',
                \App\Models\StoreItem::SLOT_BACKGROUND => 'الخلفية',
            ];
        @endphp

        @foreach ($slots as $slot)
            <div class="mb-8 anim-fade-up d-2">
                <h2 class="font-display font-black text-lg text-white mb-3">{{ $slotLabels[$slot] }}</h2>

                @php $owned = $ownedCosmetics->get($slot, collect()); @endphp

                @if ($owned->isEmpty())
                    <div class="glass rounded-2xl px-6 py-6 text-center">
                        <p class="text-slate-400 text-sm mb-3">لا تمتلك عناصر لهذه الفتحة بعد.</p>
                        <a href="{{ route('store.index') }}" class="btn-gem !py-2 !px-5 text-sm inline-block">استكشف المتجر</a>
                    </div>
                @else
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach ($owned as $cosmetic)
                            @php $isEquipped = $currentLoadout[$slot]?->id === $cosmetic->id; @endphp
                            <div class="puzzle-card {{ $isEquipped ? '!border-amethyst' : '' }}">
                                <div class="flex items-center gap-3 mb-3">
                                    @if ($cosmetic->image_path)
                                        <img src="{{ \Illuminate\Support\Facades\Storage::url($cosmetic->image_path) }}" alt="" class="w-12 h-12 rounded-lg object-cover">
                                    @endif
                                    <div class="min-w-0">
                                        <span class="block font-bold text-white text-sm truncate">{{ $cosmetic->name }}</span>
                                        @if ($slot === \App\Models\StoreItem::SLOT_TITLE && $cosmetic->cosmetic_text)
                                            <span class="text-xs text-slate-400">"{{ $cosmetic->cosmetic_text }}"</span>
                                        @endif
                                    </div>
                                </div>

                                @if ($isEquipped)
                                    <div class="flex items-center gap-2">
                                        <span class="chip !py-1 !px-3 text-xs !text-emerald flex-1 justify-center">مجهَّز حاليًا</span>
                                        <form method="POST" action="{{ route('profile.cosmetics.unequip', $slot) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="chip !py-1 !px-3 text-xs !text-rose">إزالة</button>
                                        </form>
                                    </div>
                                @else
                                    <form method="POST" action="{{ route('profile.cosmetics.equip', $cosmetic) }}">
                                        @csrf
                                        <button type="submit" class="btn-gem w-full !py-2 text-sm">تجهيز</button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach

    </div>

@endsection