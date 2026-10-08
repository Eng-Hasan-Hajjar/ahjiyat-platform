{{--
    E22: تنقل إعدادات الملف الشخصي (الحساب | الهوية | الخصوصية): يجمع مسارات قائمة فقط (لا نظام إعدادات جديد). الحساب = البريد والاسم، الهوية = الأفاتار والغلاف...، الخصوصية = من يرى ملفي.
    $active: account | identity | privacy. الهوية لا تظهر لغير الموثَّق (المسار نفسه خلف verified).
--}}
@php
    $tabs = array_filter([
        ['key' => 'account', 'label' => 'الحساب', 'icon' => 'settings', 'url' => route('profile.edit')],
        auth()->user()?->hasVerifiedEmail() ? ['key' => 'identity', 'label' => 'الهوية', 'icon' => 'palette', 'url' => route('profile.customize')] : null,
        ['key' => 'privacy', 'label' => 'الخصوصية', 'icon' => 'lock', 'url' => route('profile.edit').'#privacy'],
    ]);
@endphp
<div class="flex items-center justify-between gap-3 flex-wrap mb-5">
    <nav aria-label="إعدادات الملف الشخصي" class="flex items-center gap-1 rounded-2xl bg-white/5 border border-white/10 p-1 max-w-full">
        @foreach ($tabs as $tab)
            <a href="{{ $tab['url'] }}" @if (($active ?? 'account') === $tab['key']) aria-current="page" @endif
                class="inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-bold transition motion-reduce:transition-none focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst {{ ($active ?? 'account') === $tab['key'] ? 'bg-amethyst/20 text-white' : 'text-slate-400 hover:text-white' }}">
                <x-ui-icon :name="$tab['icon']" class="w-4 h-4" />{{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
    <a href="{{ route('players.show', auth()->user()) }}" class="inline-flex items-center gap-1.5 text-xs font-bold text-amethyst hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded-lg">
        عرض ملفي العام <x-ui-icon name="chevron-left" class="w-3.5 h-3.5" />
    </a>
</div>
