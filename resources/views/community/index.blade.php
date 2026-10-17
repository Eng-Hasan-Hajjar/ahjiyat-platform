@extends('layouts.app')

@section('title', 'أحجيات المجتمع')
@section('content_width', 'max-w-6xl')

{{-- E28: قائمة أحجيات المجتمع المنشورة (الظاهرة فقط). مفصولة عن الرسمي بمسار ووسم؛ بلا جوائز اقتصادية. --}}
@section('content')
    <div class="space-y-6">
        <x-page-header title="أحجيات المجتمع" icon="friends" subtitle="أحجيات صنعها لاعبون مبدعون وراجعها فريق الإشراف. بلا جواهر أو نقاط خبرة؛ للمتعة والتحدي فقط." class="anim-fade-up !mb-0">
            <x-slot:actions>
                @if (auth()->user()?->email_verified_at !== null)
                    <a href="{{ route('creator.apply') }}" class="btn-secondary !py-2 !px-4 text-sm">كن مبدعًا</a>
                @endif
                @if (auth()->user()?->email_verified_at !== null)
                    <a href="{{ route('favorites.index') }}" class="chip !py-2 text-sm">مفضلتي</a>
                    <a href="{{ route('following.index') }}" class="chip !py-2 text-sm">من أتابعهم</a>
                    <a href="{{ route('collections.index') }}" class="chip !py-2 text-sm">مجموعاتي</a>
                @endif
                <a href="{{ route('puzzles.index') }}" class="chip !py-2 text-sm">الأحجيات الرسمية</a>
            </x-slot:actions>
        </x-page-header>

        <nav aria-label="تصفية حسب التصنيف" class="flex flex-wrap gap-2 anim-fade-up">
            <a href="{{ route('community.puzzles.index') }}" @if (! $category) aria-current="page" @endif class="chip !py-1.5 !px-3 text-xs {{ $category ? '' : 'chip-active' }}">الكل</a>
            @foreach ($categories as $cat)
                <a href="{{ route('community.puzzles.index', ['category' => $cat->slug]) }}" @if ($category && $category->id === $cat->id) aria-current="page" @endif
                   class="chip !py-1.5 !px-3 text-xs {{ $category && $category->id === $cat->id ? 'chip-active' : '' }}">{{ $cat->name }}</a>
            @endforeach
        </nav>

        @if ($works->isEmpty())
            <x-empty-state icon="puzzle" title="لا أحجيات مجتمع هنا بعد" message="كن أول من ينشر أحجية، أو جرّب تصنيفًا آخر." :action="route('puzzles.index')" actionLabel="الأحجيات الرسمية" />
        @else
            <section aria-label="أحجيات المجتمع" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 anim-fade-up">
                @foreach ($works as $work)
                    @include('community._card', ['work' => $work, 'showCreator' => true, 'savedIds' => $savedIds ?? []])
                @endforeach
            </section>

            <div class="flex justify-center">{{ $works->links() }}</div>
        @endif
    </div>
@endsection
