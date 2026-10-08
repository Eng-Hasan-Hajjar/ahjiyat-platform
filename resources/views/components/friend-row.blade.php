@props(['user'])
{{-- صف لاعب: أفاتار + الاسم (رابط الملف فقط إن كان مرئيًا للمشاهد بقواعد الخصوصية الحالية). لا بريد/هاتف/معرّف داخلي. --}}
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3']) }}>
    <x-player-avatar :avatar="$user->identityAvatar ?? null" :frame="$user->identityFrame ?? null" :name="$user->name" size="md" />
    <div class="min-w-0 flex-1 basis-32">
        @if ($user->profileLinkable ?? false)
            <a href="{{ route('players.show', $user) }}" class="font-bold text-white truncate hover:text-amethyst focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded transition block">{{ $user->name }}</a>
        @else
            <span class="font-bold text-white truncate block">{{ $user->name }}</span>
        @endif
    </div>
    <div class="flex flex-wrap items-center justify-end gap-2 max-w-full">{{ $slot }}</div>
</div>
