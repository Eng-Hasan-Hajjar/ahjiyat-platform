@extends('layouts.app')

@section('title', $collection->title.' - مجموعتي')
@section('content_width', 'max-w-6xl')

{{--
    E29/E35: صفحة المالك للمجموعة (خاصة بمالكها؛ غير المالك 404 من الخدمة). العنصر غير الظاهر يبقى بمكان بديل بلا عنوان ولا سبب، ويمكن إزالته.
    E35: الظهور (خاصة/غير مدرجة/عامة) + ترتيب العناصر (أزرار PATCH أصلية بلا سكربت) + لافتة الإخفاء الإشرافي (للمالك فقط، السبب نص عادي) + رابط عام + مشاركة.
--}}
@php
    $visibility = (string) $collection->visibility;
    $hidden = (bool) $collection->is_moderation_hidden;
    $shared = $visibility !== \App\Models\UserCollection::VISIBILITY_PRIVATE;
@endphp
@section('content')
    <div class="space-y-6">
        <x-page-header :title="$collection->title" icon="inventory" :subtitle="$collection->description" :back="route('collections.index')" backLabel="مجموعاتي" class="anim-fade-up !mb-0">
            <x-slot:actions>
                <x-status-badge :tone="$hidden ? 'danger' : ($visibility === 'public' ? 'success' : ($visibility === 'unlisted' ? 'warning' : 'neutral'))" data-collection-visibility>{{ \App\Models\UserCollection::visibilityLabel($visibility) }}</x-status-badge>
                <a href="{{ route('collections.edit', $collection->public_id) }}" class="chip !py-2 text-sm">تعديل</a>
            </x-slot:actions>
        </x-page-header>

        @if (session('success')) <p role="status" class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-2 text-sm font-bold">{{ session('success') }}</p> @endif
        @if (session('error')) <p role="alert" class="rounded-xl border border-rose/30 bg-rose/10 text-rose px-4 py-2 text-sm font-bold">{{ session('error') }}</p> @endif

        @if ($hidden)
            <section class="rounded-2xl border border-rose/30 bg-rose/10 p-4 text-sm text-rose" role="alert" data-collection-hidden-banner>
                <p class="font-black">أخفى فريق الإشراف هذه المجموعة عن العامة.</p>
                @if ($collection->moderation_reason) <p class="mt-1 text-slate-200 break-words">السبب: {{ $collection->moderation_reason }}</p> @endif
                <p class="mt-1 text-slate-300">يمكنك تعديلها أو جعلها خاصة أو حذفها، لكن لا يمكن نشرها حتى يعيدها الفريق.</p>
            </section>
        @endif

        <section class="glass rounded-2xl p-5 anim-fade-up" aria-labelledby="visibility-heading" x-data="{ choice: @js($visibility), ack: false }" data-collection-visibility-panel>
            <h2 id="visibility-heading" class="font-display font-black text-white mb-1">ظهور المجموعة</h2>
            <p class="text-xs text-slate-400 mb-4">{{ number_format($visibleCount) }} أحجية ظاهرة · {{ number_format((int) $collection->saves_count) }} حفظ</p>
            <form method="POST" action="{{ route('collections.visibility', $collection->public_id) }}" class="space-y-3">
                @csrf
                @method('PATCH')
                <fieldset class="grid sm:grid-cols-3 gap-2">
                    <legend class="sr-only">اختر ظهور المجموعة</legend>
                    @foreach (\App\Models\UserCollection::VISIBILITY_LABELS as $key => $meta)
                        <label class="glass rounded-xl p-3 cursor-pointer flex items-start gap-2 has-[:checked]:border-amethyst/60 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-amethyst">
                            <input type="radio" name="visibility" value="{{ $key }}" x-model="choice" @checked($visibility === $key) @disabled($hidden && $key !== 'private') class="mt-1 accent-amethyst">
                            <span><span class="block text-sm font-black text-white">{{ $meta['label'] }}</span><span class="block text-[11px] text-slate-400">{{ $key === 'private' ? 'خاص: ' : ($key === 'unlisted' ? 'غير مدرج: ' : 'عام: ') }}{{ $meta['hint'] }}</span></span>
                        </label>
                    @endforeach
                </fieldset>
                @error('visibility') <p class="text-xs font-bold text-rose" role="alert">{{ $message }}</p> @enderror

                <div x-show="choice === 'public' && choice !== @js($visibility)" x-cloak class="rounded-xl border border-gold/30 bg-gold/10 p-3 text-xs text-slate-200 space-y-2">
                    <p class="font-bold text-gold">تنبيه خصوصية قبل النشر</p>
                    <p>المجموعة العامة يراها كل الزوار وقد تظهر في البحث والاكتشاف باسمك وبنسبة ملفك الشخصي، ويمكن لأي عضو حفظها والإبلاغ عنها. تشترط المجموعة العامة ملفًا شخصيًا عامًا{{ ! $profilePublic ? ' (ملفك الحالي ليس عامًا، فاجعله عامًا من إعدادات الخصوصية أولًا)' : '' }} و{{ $minPublicItems }} أحجية ظاهرة على الأقل. عناوين ووصف المجموعة نص عادي ولا تُنشر أي بيانات حساب.</p>
                    <label class="flex items-start gap-2 font-bold">
                        <input type="checkbox" name="acknowledge" value="1" x-model="ack" class="mt-0.5 accent-amethyst">
                        <span>أفهم أن المجموعة ستصبح عامة ويمكن لأي شخص رؤيتها.</span>
                    </label>
                </div>
                <div x-show="choice === 'unlisted' && choice !== @js($visibility)" x-cloak class="rounded-xl border border-white/10 bg-white/5 p-3 text-xs text-slate-300">
                    غير المدرجة تعمل لمن لديه الرابط فقط، ولا تظهر في البحث أو الاكتشاف أو ملفك الشخصي، ولا تُفهرس في محركات البحث.
                </div>

                <button type="submit" class="btn-gem !py-2 !px-5 text-sm" x-bind:disabled="choice === 'public' && choice !== @js($visibility) && ! ack">حفظ الظهور</button>
            </form>
        </section>

        @if ($shared && ! $hidden)
            <x-share-button kind="collection" :param="$collection->public_id" :title="$collection->title" />
            <p class="text-xs text-slate-400">الرابط العام: <a href="{{ route('collections.public.show', $collection->public_id) }}" class="font-bold text-amethyst underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-collection-public-link>عرض كما يراها الزوار</a></p>
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
                        <div class="flex items-center justify-center gap-3">
                            <form method="POST" action="{{ route('collections.reorder', $collection->public_id) }}">
                                @csrf @method('PATCH')
                                <input type="hidden" name="work" value="{{ $work->public_id }}">
                                <input type="hidden" name="direction" value="up">
                                <button type="submit" @disabled($loop->first && $works->onFirstPage()) class="text-xs font-bold text-slate-400 hover:text-white disabled:opacity-40 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" aria-label="نقل الأحجية للأعلى">▲ أعلى</button>
                            </form>
                            <form method="POST" action="{{ route('collections.reorder', $collection->public_id) }}">
                                @csrf @method('PATCH')
                                <input type="hidden" name="work" value="{{ $work->public_id }}">
                                <input type="hidden" name="direction" value="down">
                                <button type="submit" @disabled($loop->last && ! $works->hasMorePages()) class="text-xs font-bold text-slate-400 hover:text-white disabled:opacity-40 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" aria-label="نقل الأحجية للأسفل">▼ أسفل</button>
                            </form>
                            <form method="POST" action="{{ route('collections.items.destroy', [$collection->public_id, $work->public_id]) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs font-bold text-slate-400 hover:text-rose underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إزالة من المجموعة</button>
                            </form>
                        </div>
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
                <p class="text-sm text-slate-300">سيُحذف تجميع هذه المجموعة فقط{{ $shared ? ' وتختفي من العامة ومن محفوظات من حفظها' : '' }}؛ الأحجيات نفسها ومفضلتك وتقييماتك لا تتأثر.</p>
                <button type="submit" class="btn-secondary !py-2 !px-5 text-sm !text-rose">تأكيد حذف المجموعة</button>
            </form>
        </details>
    </div>
@endsection
