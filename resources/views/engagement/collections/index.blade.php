@extends('layouts.app')

@section('title', 'مجموعاتي')
@section('content_width', 'max-w-4xl')

{{-- E29: مجموعاتي الشخصية. خاصة بالكامل (لا رابط عام ولا مشاركة). السقف قابل للضبط ويُعرض للمستخدم. --}}
@section('content')
    <div class="space-y-6">
        <x-page-header title="مجموعاتي" icon="inventory" subtitle="رتّب أحجيات المجتمع التي تعجبك في مجموعات خاصة بك." class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('favorites.index') }}" class="chip !py-2 text-sm">مفضلتي</a>
                <a href="{{ route('collections.create') }}" class="btn-gem !py-2 !px-4 text-sm">مجموعة جديدة</a>
            </x-slot:actions>
        </x-page-header>

        @if (session('success'))
            <p role="status" class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-2 text-sm font-bold">{{ session('success') }}</p>
        @endif

        @if ($collections->isEmpty())
            <x-empty-state icon="inventory" title="لا مجموعات بعد" message="أنشئ مجموعة وأضف إليها أحجيات من صفحة أي أحجية مجتمع." :action="route('collections.create')" actionLabel="أنشئ مجموعتك الأولى" />
        @else
            <p class="text-xs text-slate-400">{{ $collections->total() }} من {{ $max }} مجموعة</p>
            <ul class="grid sm:grid-cols-2 gap-3 anim-fade-up">
                @foreach ($collections as $collection)
                    <li>
                        <a href="{{ route('collections.show', $collection->public_id) }}" class="glass rounded-2xl p-4 flex flex-col gap-1 h-full hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
                            <span class="flex items-center gap-2 font-display font-black text-white break-words"><x-engagement-icon name="folder" class="w-5 h-5 text-amethyst shrink-0" />{{ $collection->title }}</span>
                            @if ($collection->description) <span class="text-xs text-slate-400 break-words">{{ $collection->description }}</span> @endif
                            <span class="mt-auto pt-2 text-[11px] font-bold text-slate-400">{{ number_format($collection->items_count) }} أحجية</span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <div class="flex justify-center">{{ $collections->links() }}</div>
        @endif
    </div>
@endsection
