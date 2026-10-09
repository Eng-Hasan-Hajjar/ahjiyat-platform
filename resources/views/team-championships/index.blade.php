@extends('layouts.app')

@section('title', 'بطولات الفرق')

@section('content')
    <div class="max-w-4xl mx-auto space-y-6">
        <x-page-header title="بطولات الفرق" subtitle="نقاط البطولة بحسب مركز الفريق في كل حدث معتمَد، لا بجمع درجات أحجيات مختلفة. المجد وحده: بلا جوائز اقتصادية." icon="trophy" :back="route('teams.index')" backLabel="الفرق" class="!mb-0 anim-fade-up" />

        <section class="space-y-3" aria-labelledby="live-title">
            <h2 id="live-title" class="font-display font-black text-lg text-white">الجارية والقادمة</h2>
            @forelse ($live as $c)
                <a href="{{ route('team-championships.show', $c) }}" class="glass rounded-2xl p-4 flex flex-wrap items-center justify-between gap-2 hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition">
                    <div><div class="font-bold text-white">{{ $c->is_featured ? '⭐ ' : '' }}{{ $c->title }}</div><div class="text-xs text-slate-400">{{ $c->starts_at->format('Y-m-d') }} → {{ $c->ends_at->format('Y-m-d') }} · {{ $c->events_count }} أحداث</div></div>
                    <x-status-badge :tone="['upcoming' => 'warning', 'live' => 'success', 'ended' => 'neutral'][$c->phase()] ?? 'neutral'" small>{{ ['upcoming' => 'قادمة', 'live' => 'جارية', 'ended' => 'بانتظار الاعتماد'][$c->phase()] }}</x-status-badge>
                </a>
            @empty
                <x-empty-state icon="trophy" title="لا بطولات جارية الآن" message="ستظهر البطولات القادمة هنا فور الإعلان عنها." class="!py-8" />
            @endforelse
        </section>

        <section class="space-y-3" aria-labelledby="done-title">
            <h2 id="done-title" class="font-display font-black text-lg text-white">المنتهية</h2>
            @forelse ($completed as $c)
                <a href="{{ route('team-championships.show', $c) }}" class="glass rounded-2xl p-4 flex flex-wrap items-center justify-between gap-2 hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition">
                    <div><div class="font-bold text-white">{{ $c->title }}</div><div class="text-xs text-slate-400">{{ $c->finalized_at->format('Y-m-d') }}</div></div>
                    <span class="text-sm text-gold">🏆 {{ $c->champion?->name }}</span>
                </a>
            @empty
                <x-empty-state icon="trophy" title="لا بطولات منتهية بعد" message="سيُسجَّل أبطال البطولات هنا بعد اعتماد النتائج." class="!py-8" />
            @endforelse
            <div>{{ $completed->links() }}</div>
        </section>
    </div>
@endsection
