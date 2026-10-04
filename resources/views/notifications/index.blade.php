@extends('layouts.app')

@section('title', 'الإشعارات')

@section('content')
    <div class="max-w-3xl mx-auto space-y-5">

        <div class="flex flex-wrap items-center justify-between gap-3 anim-fade-up">
            <div>
                <h1 class="font-display font-black text-2xl text-white">الإشعارات</h1>
                <p class="text-xs text-slate-400 mt-1">
                    @if ($unreadCount > 0) لديك {{ $unreadCount }} إشعار غير مقروء. @else كل إشعاراتك مقروءة. @endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('notifications.preferences') }}" class="chip">التفضيلات</a>
                @if ($unreadCount > 0)
                    <form method="POST" action="{{ route('notifications.read-all') }}">
                        @csrf
                        <button type="submit" class="chip !text-amethyst">تعليم الكل كمقروء</button>
                    </form>
                @endif
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-xl bg-emerald/10 border border-emerald/30 text-emerald text-sm font-bold px-4 py-3">{{ session('success') }}</div>
        @endif

        <nav class="flex flex-wrap gap-2 text-xs font-bold" aria-label="تصفية الإشعارات">
            <a href="{{ route('notifications.index') }}" class="chip {{ $filter === 'all' && ! $category ? '!border-amethyst !text-white' : '' }}">الكل</a>
            <a href="{{ route('notifications.index', ['filter' => 'unread']) }}" class="chip {{ $filter === 'unread' ? '!border-amethyst !text-white' : '' }}">غير المقروء</a>
            @foreach ($categories as $c)
                <a href="{{ route('notifications.index', ['category' => $c->value]) }}"
                    class="chip {{ $category === $c ? '!border-amethyst !text-white' : '' }}">{{ $c->label() }}</a>
            @endforeach
        </nav>

        <div class="space-y-3">
            @forelse ($notifications as $n)
                @php($type = \App\Services\Notifications\NotificationType::tryFrom((string) $n->type_key))
                @php($url = $resolver->resolve((array) $n->data))
                <article class="puzzle-card !p-4 md:!p-5 flex gap-4 {{ $n->read_at === null ? 'border-amethyst/40' : 'opacity-80' }}" data-notification-id="{{ $n->id }}">
                    <span class="text-2xl leading-none mt-1" aria-hidden="true">{{ $type?->icon() ?? '🔔' }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-3">
                            <h2 class="font-black text-sm text-white">{{ $n->data['title'] ?? '' }}</h2>
                            @if ($n->read_at === null)
                                <span class="shrink-0 text-[10px] font-black text-amethyst bg-amethyst/10 rounded-full px-2 py-0.5">جديد</span>
                            @endif
                        </div>
                        <p class="text-sm text-slate-300 mt-1">{{ $n->data['body'] ?? '' }}</p>
                        <p class="text-[11px] text-slate-500 mt-2" title="{{ $n->created_at->format('Y-m-d H:i') }}">
                            {{ $n->created_at->locale('ar')->diffForHumans() }} · {{ $n->created_at->format('Y-m-d H:i') }}
                        </p>
                        <div class="flex flex-wrap items-center gap-2 mt-3">
                            @if ($url)
                                <form method="POST" action="{{ route('notifications.open', $n->id) }}">
                                    @csrf
                                    <button type="submit" class="btn-gem !py-1.5 !px-4 text-xs">فتح</button>
                                </form>
                            @endif
                            @if ($n->read_at === null)
                                <form method="POST" action="{{ route('notifications.read', $n->id) }}">
                                    @csrf
                                    <button type="submit" class="chip text-xs">تعليم كمقروء</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('notifications.destroy', $n->id) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="chip text-xs !text-rose">حذف</button>
                            </form>
                        </div>
                    </div>
                </article>
            @empty
                <div class="puzzle-card !p-10 text-center anim-fade-up">
                    <div class="text-4xl mb-3" aria-hidden="true">🔔</div>
                    <p class="font-black text-white">لا توجد إشعارات {{ $filter === 'unread' ? 'غير مقروءة' : 'هنا' }} حاليًا</p>
                    <p class="text-sm text-slate-400 mt-1">سنخبرك هنا عند فتح إنجاز جديد أو عند وجود ما يستحق انتباهك.</p>
                </div>
            @endforelse
        </div>

        <div>{{ $notifications->links() }}</div>
    </div>
@endsection
