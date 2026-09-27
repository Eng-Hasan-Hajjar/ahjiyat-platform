@props(['name', 'avatar' => null, 'frame' => null, 'badge' => null, 'title' => null, 'background' => null])

<div class="relative rounded-2xl overflow-hidden puzzle-card !p-0">
    <div class="relative h-32 md:h-40 bg-gradient-to-br from-amethyst/30 to-night-800"
         @if ($background && $background->image_path)
             style="background-image: url('{{ \Illuminate\Support\Facades\Storage::url($background->image_path) }}'); background-size: cover; background-position: center;"
         @endif
    >
        <div class="absolute inset-0 bg-gradient-to-t from-night-950/90 via-night-950/40 to-transparent"></div>
    </div>

    <div class="relative px-5 md:px-8 pb-6 -mt-10 flex flex-col items-center text-center">
        <x-player-avatar :avatar="$avatar" :frame="$frame" :name="$name" size="lg" class="ring-4 ring-night-900" />

        <div class="mt-3 flex items-center gap-2">
            <h1 class="font-display font-black text-xl md:text-2xl text-white">{{ $name }}</h1>
            @if ($badge && $badge->image_path)
                <img src="{{ \Illuminate\Support\Facades\Storage::url($badge->image_path) }}" alt="{{ $badge->name }}" class="w-6 h-6 object-contain" title="{{ $badge->name }}">
            @endif
        </div>

        @if ($title && filled($title->cosmetic_text))
            <span class="text-sm font-bold mt-1"
                  style="color: {{ preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $title->cosmetic_color) ? $title->cosmetic_color : '#a78bfa' }};">
                {{ $title->cosmetic_text }}
            </span>
        @endif
    </div>
</div>