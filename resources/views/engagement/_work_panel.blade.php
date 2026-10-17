@php
    /**
     * E29: قسم التقييم والمفضلة والمجموعات في صفحة أحجية المجتمع.
     * @var \App\Models\CreatorPuzzle $work
     * @var bool $isOwner @var bool $canInteract
     * @var array{rating: int|null, favorite: bool, played: bool}|null $viewer  (null للضيف/المالك/غير المؤهل)
     * @var \Illuminate\Support\Collection|null $collections
     */
    $count = (int) $work->ratings_count;
    $mine = $viewer['rating'] ?? null;
@endphp
<section id="engagement" tabindex="-1" aria-labelledby="engagement-title" class="glass rounded-2xl p-5 md:p-6 space-y-5 anim-fade-up focus:outline-none">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="engagement-title" class="font-display font-black text-lg text-white">رأي المجتمع</h2>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
            @include('engagement._rating_summary', ['count' => $count, 'sum' => (int) $work->ratings_sum])
            @if ((int) $work->favorites_count > 0)
                <span class="inline-flex items-center gap-1 text-[11px] font-bold text-slate-400"><x-engagement-icon name="bookmark" :filled="true" class="w-3.5 h-3.5" />{{ number_format((int) $work->favorites_count) }} حفظوها</span>
            @endif
        </div>
    </div>

    @if (session('engagement_status'))
        <p role="status" class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-2 text-sm font-bold">{{ session('engagement_status') }}</p>
    @endif

    @guest
        <p class="text-sm text-slate-300">
            <a href="{{ route('login') }}" class="font-bold text-amethyst underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">سجّل الدخول</a>
            لتقييم الأحجية وحفظها في مفضلتك أو في مجموعاتك.
        </p>
    @else
        @if ($isOwner)
            <p class="text-sm text-slate-400">هذه أحجيتك؛ لا يمكنك تقييمها أو حفظها. تراها هنا كما يراها اللاعبون.</p>
        @elseif (! $canInteract)
            <p class="text-sm text-gold font-bold">يلزم بريد إلكتروني موثَّق وحساب غير مجمَّد لتقييم الأحجية وحفظها.</p>
        @else
            {{-- التقييم: مجموعة أزرار راديو أصلية (أسهم لوحة المفاتيح تعمل بلا JS)، النجمة المعبَّأة = مختارة (الشكل + نص «n من 5»، لا اللون وحده). --}}
            @if ($viewer['played'])
                <form method="POST" action="{{ route('community.puzzles.rating.update', $work->public_id) }}" x-data="{ r: {{ (int) ($mine ?? 0) }} }" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <fieldset>
                        <legend class="text-sm font-bold text-slate-200 mb-2">{{ $mine ? 'تقييمك لهذه الأحجية' : 'قيّم هذه الأحجية' }}</legend>
                        <div class="flex items-center gap-1" role="radiogroup">
                            @foreach (range((int) config('engagement.ratings.min', 1), (int) config('engagement.ratings.max', 5)) as $i)
                                <span class="relative">
                                    <input type="radio" name="rating" id="rating-{{ $i }}" value="{{ $i }}" class="peer sr-only" required x-model.number="r" @checked($mine === $i)>
                                    <label for="rating-{{ $i }}" class="grid place-items-center w-11 h-11 rounded-xl cursor-pointer text-gold hover:bg-white/5 peer-focus-visible:ring-2 peer-focus-visible:ring-amethyst" title="{{ $i }} من 5">
                                        <x-engagement-icon name="star" :filled="$mine !== null && $i <= $mine" class="w-7 h-7" x-bind:fill="r >= {{ $i }} ? 'currentColor' : 'none'" />
                                        <span class="sr-only">{{ $i }} من 5</span>
                                    </label>
                                </span>
                            @endforeach
                        </div>
                    </fieldset>
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="btn-gem !py-2 !px-5 text-sm">{{ $mine ? 'تحديث التقييم' : 'حفظ التقييم' }}</button>
                        @if ($mine) <span class="text-xs font-bold text-slate-400">تقييمك الحالي: {{ $mine }} من 5</span> @endif
                    </div>
                </form>
                @if ($mine)
                    <form method="POST" action="{{ route('community.puzzles.rating.destroy', $work->public_id) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-xs font-bold text-slate-400 hover:text-rose underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إزالة تقييمي</button>
                    </form>
                @endif
            @else
                <p class="text-sm text-slate-400">جرّب الأحجية ولو بمحاولة واحدة ليتاح لك تقييمها.</p>
            @endif

            <div class="flex flex-wrap items-center gap-3 pt-4 border-t border-white/5">
                @if ($viewer['favorite'])
                    <form method="POST" action="{{ route('community.puzzles.favorite.destroy', $work->public_id) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-secondary !py-2 !px-4 text-sm inline-flex items-center gap-2" aria-label="إزالة من مفضلتي"><x-engagement-icon name="bookmark" :filled="true" class="w-4 h-4 text-gold" />محفوظة في مفضلتي</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('community.puzzles.favorite.store', $work->public_id) }}">
                        @csrf
                        <button type="submit" class="btn-secondary !py-2 !px-4 text-sm inline-flex items-center gap-2"><x-engagement-icon name="bookmark" class="w-4 h-4" />احفظ في المفضلة</button>
                    </form>
                @endif
                <a href="{{ route('favorites.index') }}" class="text-xs font-bold text-slate-400 hover:text-white underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">مفضلتي</a>
            </div>

            <details class="rounded-xl border border-white/10 bg-white/5 p-4">
                <summary class="cursor-pointer text-sm font-bold text-slate-200 flex items-center gap-2 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-engagement-icon name="folder" class="w-4 h-4" />أضف إلى مجموعة</summary>
                <form method="POST" action="{{ route('community.puzzles.collections.add', $work->public_id) }}" class="mt-4 space-y-3">
                    @csrf
                    @if ($collections->isNotEmpty())
                        <div>
                            <label for="collection-pick" class="block text-xs font-bold text-slate-300 mb-1">مجموعة قائمة</label>
                            <select id="collection-pick" name="collection" class="input-gem">
                                <option value="">— اختر مجموعة —</option>
                                @foreach ($collections as $collection)
                                    <option value="{{ $collection->public_id }}" @disabled($collection->has_work)>{{ $collection->title }}@if ($collection->has_work) (مضافة) @endif</option>
                                @endforeach
                            </select>
                        </div>
                        <p class="text-xs text-slate-500 text-center">أو</p>
                    @endif
                    <div>
                        <label for="collection-new" class="block text-xs font-bold text-slate-300 mb-1">مجموعة جديدة</label>
                        <input id="collection-new" type="text" name="new_title" maxlength="{{ (int) config('engagement.collections.title_max') }}" value="{{ old('new_title') }}" placeholder="عنوان المجموعة" class="input-gem">
                    </div>
                    @error('collection') <p class="text-xs font-bold text-rose" role="alert">{{ $message }}</p> @enderror
                    @error('new_title') <p class="text-xs font-bold text-rose" role="alert">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="btn-secondary !py-2 !px-5 text-sm">إضافة</button>
                        <a href="{{ route('collections.index') }}" class="text-xs font-bold text-slate-400 hover:text-white underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">مجموعاتي</a>
                    </div>
                </form>
            </details>
        @endif
    @endguest
</section>
