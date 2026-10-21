@extends('layouts.app')

@section('title', 'اكتشف')
@section('meta_description', 'الرائج والجديد من الأحجيات والحملات والمنافسات والفرق، ومقترحات تناسبك.')

@section('content')
    <div class="space-y-10">

        <x-page-header title="اكتشف" :subtitle="$personalized ? 'ما يناسبك الآن، والرائج والجديد في المنصة.' : 'الرائج والجديد في المنصة. سجّل دخولك لتحصل على مقترحات تناسبك.'" icon="sparkles" class="anim-fade-up !mb-0" />

        <form method="GET" action="{{ route('search.index') }}" role="search" class="glass rounded-3xl p-4 flex flex-wrap items-center gap-3 anim-fade-up">
            <label for="discover-q" class="sr-only">ابحث في المنصة</label>
            <input id="discover-q" type="search" name="q" maxlength="64" autocomplete="off" enterkeyhint="search" placeholder="ابحث عن أحجية أو فريق أو لاعب…"
                class="flex-1 min-w-[12rem] rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white placeholder:text-slate-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
            <button type="submit" class="btn-gem !py-2 !px-5 text-sm">بحث</button>
        </form>

        @if ($categories->isNotEmpty())
            <nav aria-label="تصنيفات الأحجيات" class="flex flex-wrap gap-2 anim-fade-up">
                @foreach ($categories as $category)
                    <a href="{{ route('puzzles.category', $category) }}" class="chip !py-1.5 !px-3 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $category->name }}</a>
                @endforeach
            </nav>
        @endif

        {{-- E29: مدخل صغير لقوائم المستخدم الخاصة (مفضلة/متابَعون/مجموعات) للموثَّق فقط؛ بلا تغيير تصميم الصفحة. --}}
        @if (auth()->user()?->email_verified_at !== null)
            <nav aria-label="قوائمي" class="flex flex-wrap gap-2 anim-fade-up">
                <a href="{{ route('following.index') }}" class="chip !py-1.5 !px-3 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">من أتابعهم</a>
                <a href="{{ route('favorites.index') }}" class="chip !py-1.5 !px-3 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">مفضلتي</a>
                <a href="{{ route('collections.index') }}" class="chip !py-1.5 !px-3 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">مجموعاتي</a>
                <a href="{{ route('collections.saved') }}" class="chip !py-1.5 !px-3 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">مجموعات محفوظة</a>
            </nav>
        @endif

        @forelse ($sections as $section)
            <section aria-label="{{ $section['title'] }}" data-discover-section="{{ $section['key'] }}" class="anim-fade-up">
                <x-section-header :title="$section['title']" :href="$section['href']" :linkLabel="$section['linkLabel'] ?? 'عرض الكل'" />
                @if (! empty($section['subtitle'])) <p class="-mt-2 mb-3 text-xs text-slate-400 break-words">{{ $section['subtitle'] }}</p> @endif
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    @foreach ($section['cards'] as $card)
                        @include('discover._card', ['card' => $card])
                    @endforeach
                </div>
            </section>
        @empty
            <x-empty-state icon="sparkles" title="لا محتوى للاكتشاف بعد" message="عند نشر أحجيات ومنافسات جديدة ستظهر هنا." :action="route('puzzles.index')" actionLabel="تصفّح الأحجيات" />
        @endforelse
    </div>
@endsection
