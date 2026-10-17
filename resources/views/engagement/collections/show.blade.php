@extends('layouts.app')

@section('title', $collection->title.' - مجموعتي')
@section('content_width', 'max-w-6xl')

{{-- E29: محتوى مجموعة (خاصة بمالكها؛ غير المالك 404 من الخدمة). العنصر غير الظاهر يبقى بمكان بديل بلا عنوان ولا سبب، ويمكن إزالته. --}}
@section('content')
    <div class="space-y-6">
        <x-page-header :title="$collection->title" icon="inventory" :subtitle="$collection->description" :back="route('collections.index')" backLabel="مجموعاتي" class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('collections.edit', $collection->public_id) }}" class="chip !py-2 text-sm">تعديل</a>
            </x-slot:actions>
        </x-page-header>

        @if (session('success'))
            <p role="status" class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-2 text-sm font-bold">{{ session('success') }}</p>
        @endif

        @if ($works->isEmpty())
            <x-empty-state icon="puzzle" title="المجموعة فارغة" message="افتح أي أحجية مجتمع واختر «أضف إلى مجموعة» لتظهر هنا." :action="route('community.puzzles.index')" actionLabel="تصفّح أحجيات المجتمع" />
        @else
            <section aria-label="أحجيات المجموعة" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 anim-fade-up">
                @foreach ($works as $work)
                    <div class="flex flex-col gap-2">
                        <div class="flex-1">
                            @if ($work->is_visible)
                                @include('community._card', ['work' => $work, 'showCreator' => true])
                            @else
                                @include('engagement._unavailable')
                            @endif
                        </div>
                        <form method="POST" action="{{ route('collections.items.destroy', [$collection->public_id, $work->public_id]) }}" class="text-center">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs font-bold text-slate-400 hover:text-rose underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إزالة من المجموعة</button>
                        </form>
                    </div>
                @endforeach
            </section>
            <div class="flex justify-center">{{ $works->links() }}</div>
        @endif

        <details class="glass rounded-2xl p-5 anim-fade-up">
            <summary class="cursor-pointer text-sm font-bold text-rose flex items-center gap-2 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="trash" class="w-4 h-4" />حذف المجموعة</summary>
            <form method="POST" action="{{ route('collections.destroy', $collection->public_id) }}" class="mt-4 space-y-3">
                @csrf
                @method('DELETE')
                <p class="text-sm text-slate-300">سيُحذف تجميع هذه المجموعة فقط؛ الأحجيات نفسها ومفضلتك وتقييماتك لا تتأثر.</p>
                <button type="submit" class="btn-secondary !py-2 !px-5 text-sm !text-rose">تأكيد حذف المجموعة</button>
            </form>
        </details>
    </div>
@endsection
