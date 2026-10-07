@extends('layouts.app')

@section('title', 'بطولات الفرق')

@section('content')
    <div class="max-w-4xl mx-auto space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3 anim-fade-up">
            <div>
                <h1 class="font-display font-black text-2xl md:text-3xl text-white">بطولات الفرق</h1>
                <p class="text-sm text-slate-400 mt-1">نقاط البطولة بحسب <strong>مركز</strong> الفريق في كل حدث معتمَد، لا بجمع درجات أحجيات مختلفة. المجد وحده: بلا جوائز اقتصادية.</p>
            </div>
            <a href="{{ route('teams.index') }}" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">← الفرق</a>
        </div>

        <section class="space-y-3" aria-labelledby="live-title">
            <h2 id="live-title" class="font-display font-black text-lg text-white">الجارية والقادمة</h2>
            @forelse ($live as $c)
                <a href="{{ route('team-championships.show', $c) }}" class="glass rounded-2xl p-4 flex flex-wrap items-center justify-between gap-2 hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition">
                    <div><div class="font-bold text-white">{{ $c->is_featured ? '⭐ ' : '' }}{{ $c->title }}</div><div class="text-xs text-slate-400">{{ $c->starts_at->format('Y-m-d') }} → {{ $c->ends_at->format('Y-m-d') }} · {{ $c->events_count }} أحداث</div></div>
                    <span class="chip !py-0.5 !px-2 text-xs">{{ ['upcoming' => 'قادمة', 'live' => 'جارية', 'ended' => 'بانتظار الاعتماد'][$c->phase()] }}</span>
                </a>
            @empty
                <p class="glass rounded-2xl px-4 py-8 text-center text-slate-500 text-sm">لا بطولات جارية الآن.</p>
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
                <p class="glass rounded-2xl px-4 py-8 text-center text-slate-500 text-sm">لا بطولات منتهية بعد.</p>
            @endforelse
            <div>{{ $completed->links() }}</div>
        </section>
    </div>
@endsection
