@props(['name', 'avatar' => null, 'frame' => null, 'badge' => null, 'title' => null, 'background' => null, 'level' => null, 'team' => null, 'friendsCount' => null])

{{--
    E22: غلاف الملف الشخصي (Profile Hero). الغلاف = فتحة الخلفية (background) من الهوية المجهَّزة، والأفاتار (مع الإطار) متداخل فوقه، ثم الاسم واللقب والشارة والمستوى والفريق.
    يعيد استعمال نظام الهوية القائم (E11) فقط: لا فتحات ولا جداول ولا مصدر هوية ثانٍ. الإجراءات (فتحة actions) تأتي من الصفحة: صاحب الملف ≠ زائر.
    ألوان الغلاف من رموز الثيم (E4)؛ لون اللقب يُتحقَّق من نمطه (COLOR_PATTERN) قبل الطباعة.
--}}
@php
    $coverUrl = $background && $background->image_path ? \Illuminate\Support\Facades\Storage::url($background->image_path) : null;
    $titleColor = $title && preg_match(\App\Services\Store\StoreItemInvariantGuard::COLOR_PATTERN, (string) $title->cosmetic_color) ? $title->cosmetic_color : 'var(--color-accent-text)';
@endphp
<section {{ $attributes->class('relative rounded-3xl overflow-hidden glass') }} aria-label="الملف الشخصي: {{ $name }}">
    <div class="relative h-36 sm:h-48 bg-gradient-to-br from-amethyst/30 to-night-800" data-profile-cover
        @if ($coverUrl) style="background-image: url('{{ $coverUrl }}'); background-size: cover; background-position: center;" @endif>
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-night-950/60 via-transparent to-transparent" aria-hidden="true"></div>
    </div>

    <div class="relative px-5 sm:px-8 pb-6">
        <div class="flex flex-col sm:flex-row sm:items-end gap-4 -mt-12 sm:-mt-14">
            <x-player-avatar :avatar="$avatar" :frame="$frame" :name="$name" size="xl" class="rounded-full bg-night-900 ring-4 ring-[color:var(--color-bg)]" />

            <div class="min-w-0 flex-1 sm:pb-1">
                <div class="flex items-center gap-2 flex-wrap">
                    <h1 class="font-display font-black text-2xl sm:text-3xl text-white break-words min-w-0">{{ $name }}</h1>
                    @if ($badge && $badge->image_path)
                        <img src="{{ \Illuminate\Support\Facades\Storage::url($badge->image_path) }}" alt="{{ $badge->name }}" title="{{ $badge->name }}" class="w-7 h-7 object-contain">
                    @endif
                </div>

                @if ($title && filled($title->cosmetic_text))
                    <p class="text-sm font-bold mt-0.5" style="color: {{ $titleColor }};">{{ $title->cosmetic_text }}</p>
                @endif

                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-bold">
                    @if ($level !== null)
                        <span class="chip !py-1 !px-3 !text-amethyst">المستوى {{ $level }}</span>
                    @endif
                    @if ($team)
                        <a href="{{ route('teams.show', $team) }}" class="chip !py-1 !px-3 inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="team" class="w-3.5 h-3.5" /> {{ $team->name }}</a>
                    @endif
                    @if ($friendsCount !== null)
                        <span class="chip !py-1 !px-3 inline-flex items-center gap-1.5"><x-ui-icon name="friends" class="w-3.5 h-3.5" /> الأصدقاء {{ $friendsCount }}</span>
                    @endif
                </div>
            </div>

            @isset($actions)
                <div class="flex flex-wrap items-center gap-2 sm:pb-1">{{ $actions }}</div>
            @endisset
        </div>
    </div>
</section>
