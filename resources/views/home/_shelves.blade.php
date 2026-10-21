{{--
    E35: أرفف تحريرية بالرئيسية (موضع home). عرض فقط: البطاقات تُحَلّ حيًّا من EditorialShelves (المخفي/المحظور/المنتهي يسقط). الرف بلا بطاقات غير موجود أصلًا. نصوص مهرَّبة.
--}}
@if (! empty($shelves))
    <div class="mx-auto w-full max-w-6xl space-y-8 mt-8" data-home-shelves>
        @foreach ($shelves as $shelf)
            <section aria-label="{{ $shelf['title'] }}" data-home-shelf="{{ $shelf['key'] }}" class="anim-fade-up">
                <x-section-header :title="$shelf['title']" />
                @if (! empty($shelf['subtitle'])) <p class="-mt-2 mb-3 text-xs text-slate-400 break-words">{{ $shelf['subtitle'] }}</p> @endif
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    @foreach ($shelf['cards'] as $card)
                        @include('discover._card', ['card' => $card])
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endif
