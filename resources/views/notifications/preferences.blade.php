@extends('layouts.app')

@section('title', 'تفضيلات الإشعارات')

@section('content')
    <div class="max-w-xl mx-auto space-y-5">
        <x-page-header title="تفضيلات الإشعارات" icon="settings" :back="route('notifications.index')" backLabel="الإشعارات" class="anim-fade-up !mb-0" />

        @if (session('success'))
            <div class="rounded-xl bg-emerald/10 border border-emerald/30 text-emerald text-sm font-bold px-4 py-3">{{ session('success') }}</div>
        @endif

        @unless ($globalEnabled)
            <div class="rounded-xl bg-amber-400/10 border border-amber-400/30 text-amber-300 text-sm font-bold px-4 py-3">
                الإشعارات التفاعلية معطَّلة حاليًا من إدارة المنصة. يبقى صندوقك القائم مقروءًا، وتبقى تنبيهات الأمان فعّالة.
            </div>
        @endunless

        <form method="POST" action="{{ route('notifications.preferences.update') }}" class="glass rounded-3xl p-6 space-y-5">
            @csrf
            @method('PUT')

            @foreach ($categories as $c)
                @if ($c->isMandatory())
                    <div class="flex items-start justify-between gap-4 opacity-90">
                        <div>
                            <div class="font-black text-white text-sm">{{ $c->label() }}</div>
                            <div class="text-xs text-slate-400 mt-0.5">{{ $c->description() }}</div>
                        </div>
                        <span class="shrink-0 text-[11px] font-black text-slate-300 bg-white/10 rounded-full px-3 py-1">إلزامي</span>
                    </div>
                @else
                    @php($column = $c->preferenceColumn())
                    <label class="flex items-start justify-between gap-4 cursor-pointer">
                        <span>
                            <span class="block font-black text-white text-sm">{{ $c->label() }}</span>
                            <span class="block text-xs text-slate-400 mt-0.5">{{ $c->description() }}</span>
                        </span>
                        <input type="hidden" name="{{ $column }}" value="0">
                        <input type="checkbox" name="{{ $column }}" value="1" @checked($preference->{$column})
                            class="mt-1 w-5 h-5 rounded accent-violet-500 focus:ring-2 focus:ring-amethyst">
                    </label>
                @endif
            @endforeach

            <button type="submit" class="btn-gem w-full justify-center !py-2.5 text-sm">حفظ التفضيلات</button>
        </form>

        <p class="text-xs text-slate-500">الإشعارات معلوماتية فقط؛ فتحها أو قراءتها لا يمنح أي نقاط أو مكافآت ولا يؤثر على تقدّمك.</p>
    </div>
@endsection
