@extends('layouts.app')

@section('title', 'مفضلتي')
@section('content_width', 'max-w-6xl')

{{-- E29: قائمة المفضلة الخاصة بصاحبها (الأحدث حفظًا أولًا). العمل غير الظاهر يبقى بمكان بديل «غير متاح حاليًا» بلا عنوان ولا سبب، ويمكن إزالته. --}}
@section('content')
    <div class="space-y-6">
        <x-page-header title="مفضلتي" icon="sparkles" subtitle="أحجيات المجتمع التي حفظتها. قائمتك خاصة بك؛ يظهر للآخرين عدد الحفظ فقط." class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('collections.index') }}" class="chip !py-2 text-sm">مجموعاتي</a>
                <a href="{{ route('community.puzzles.index') }}" class="chip !py-2 text-sm">أحجيات المجتمع</a>
            </x-slot:actions>
        </x-page-header>

        @if (session('engagement_status'))
            <p role="status" class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-2 text-sm font-bold">{{ session('engagement_status') }}</p>
        @endif

        @if ($works->isEmpty())
            <x-empty-state icon="puzzle" title="لم تحفظ أي أحجية بعد" message="اضغط «احفظ في المفضلة» في أي أحجية مجتمع لتجدها هنا لاحقًا." :action="route('community.puzzles.index')" actionLabel="تصفّح أحجيات المجتمع" />
        @else
            <section aria-label="الأحجيات المحفوظة" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 anim-fade-up">
                @foreach ($works as $work)
                    <div class="flex flex-col gap-2">
                        <div class="flex-1">
                            @if ($work->is_visible)
                                @include('community._card', ['work' => $work, 'showCreator' => true])
                            @else
                                @include('engagement._unavailable')
                            @endif
                        </div>
                        <form method="POST" action="{{ route('community.puzzles.favorite.destroy', $work->public_id) }}" class="text-center">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="from" value="favorites">
                            <button type="submit" class="text-xs font-bold text-slate-400 hover:text-rose underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إزالة من المفضلة</button>
                        </form>
                    </div>
                @endforeach
            </section>

            <div class="flex justify-center">{{ $works->links() }}</div>
        @endif
    </div>
@endsection
