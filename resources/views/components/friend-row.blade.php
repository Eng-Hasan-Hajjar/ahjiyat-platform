@props(['user'])
{{-- صف لاعب: أفاتار + الاسم (رابط الملف فقط إن كان مرئيًا للمشاهد بقواعد الخصوصية الحالية). لا بريد/هاتف/معرّف داخلي. --}}
<div {{ $attributes->merge(['class' => 'flex items-center gap-3 px-4 py-3']) }}>
    <x-player-avatar :avatar="$user->identityAvatar ?? null" :frame="$user->identityFrame ?? null" :name="$user->name" size="md" />
    <div class="min-w-0 flex-1">
        @if ($user->profileLinkable ?? false)
            <a href="{{ route('players.show', $user) }}" class="font-bold text-white truncate hover:text-amethyst focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded transition block">{{ $user->name }}</a>
        @else
            <span class="font-bold text-white truncate block">{{ $user->name }}</span>
        @endif
    </div>
    <div class="shrink-0 flex flex-wrap items-center justify-end gap-2">{{ $slot }}</div>
</div>
