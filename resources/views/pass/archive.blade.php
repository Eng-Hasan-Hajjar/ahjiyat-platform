@extends('layouts.app')

@section('title', 'أرشيف مسارات التحدي')
@section('meta_description', 'المسارات السابقة وتقدّمك فيها.')

@section('content')
    <x-page-header title="أرشيف المسارات" subtitle="المسارات السابقة وتقدّمك فيها. للعرض، مع استلام ما تبقى ضمن مهلة الاستلام." icon="calendar" class="anim-fade-up" :back="route('pass.index')" back-label="المسار الحالي" />

    @if ($passes->isEmpty())
        <x-empty-state icon="calendar" title="لا مسارات سابقة بعد" message="ستظهر هنا المسارات المنتهية." />
    @else
        <ul class="grid gap-3 md:grid-cols-2 anim-fade-up d-1" data-pass-archive>
            @foreach ($passes as $item)
                @php($mine = $progress->get($item->id))
                <li class="glass rounded-2xl p-4" style="--pass-accent: {{ \App\Services\AhjiyatPass\PassTheme::accent($item) }}">
                    <a href="{{ route('pass.archive.show', $item->public_id) }}" class="block rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                        <p class="font-display font-black text-white truncate">{{ $item->name }}</p>
                        <p class="text-xs text-slate-400 mt-0.5">{{ $item->starts_at->format('Y-m-d') }} — {{ $item->ends_at->format('Y-m-d') }}</p>
                        <p class="mt-3 text-sm text-slate-300">
                            @if ($mine)
                                بلغتَ الشريحة <span class="font-black" style="color: var(--pass-accent)">{{ $mine->highest_tier }}</span> / {{ $item->total_tiers }}
                                @if ($mine->hasPremium()) · <span class="text-gold font-bold">مميز</span> @endif
                            @else
                                لم تشارك في هذا المسار.
                            @endif
                        </p>
                    </a>
                </li>
            @endforeach
        </ul>
        <div class="mt-6">{{ $passes->links() }}</div>
    @endif
@endsection
