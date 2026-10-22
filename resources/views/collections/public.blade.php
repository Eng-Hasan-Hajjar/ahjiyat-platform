@extends('layouts.app')

@section('title', $collection->title.' - مجموعة من المجتمع')
@section('meta_description', $collection->description ? \Illuminate\Support\Str::limit($collection->description, 150) : 'مجموعة أحجيات من المجتمع بعنوان «'.$collection->title.'». أحجيات المجتمع بلا جوائز اقتصادية.')
@section('canonical', route('collections.public.show', $collection->public_id))
@if ($coverUrl)
    @section('og_image', $coverUrl)
@endif
@section('content_width', 'max-w-5xl')

{{-- غير المدرجة: لا فهرسة ولا تتبّع روابط (رابطها لمن يشاركه المالك فقط). العامة تتبع إعداد فهرسة الموقع العام. --}}
@push('head')
    @if ($collection->isUnlisted())
        <meta name="robots" content="noindex, nofollow">
    @endif
@endpush

{{--
    E35: الصفحة العامة للمجموعة. نصوص الأمين مهرَّبة دائمًا (Blade العادي فقط). الأعمال غير الظاهرة محذوفة من العرض والعدّ (لا تسريب).
    نسبة الأمين تتبع خصوصية ملفه (تسمية محايدة بدل الاسم). لا جوائز ولا اقتصاد: تصريح ظاهر. RTL. بلا تتبّع مشاهدات.
--}}
@section('content')
    <div class="space-y-5">
        <a href="{{ route('discover.index') }}" class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-400 hover:text-white transition motion-reduce:transition-none rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst anim-fade-up">
            <x-ui-icon name="chevron-right" class="w-4 h-4" />اكتشف
        </a>

        @if (session('success')) <p role="status" class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-2 text-sm font-bold">{{ session('success') }}</p> @endif
        @if (session('error')) <p role="alert" class="rounded-xl border border-rose/30 bg-rose/10 text-rose px-4 py-2 text-sm font-bold">{{ session('error') }}</p> @endif

        <x-share-button kind="collection" :param="$collection->public_id" :title="$collection->title" />

        <header class="puzzle-card !p-6 md:!p-8 anim-fade-up d-1" data-collection-header>
            @if ($coverUrl)
                <div class="rounded-2xl overflow-hidden border border-white/10 mb-5">
                    <img src="{{ $coverUrl }}" alt="" class="w-full h-auto max-h-72 object-cover" loading="lazy">
                </div>
            @else
                <div class="rounded-2xl border border-white/10 bg-amethyst/10 text-amethyst grid place-items-center h-28 mb-5" aria-hidden="true"><x-ui-icon name="inventory" class="w-12 h-12" /></div>
            @endif
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <x-status-badge tone="primary" icon="friends">مجموعة من المجتمع</x-status-badge>
                <x-status-badge tone="neutral" :small="true">بلا جوائز</x-status-badge>
                <span class="chip !py-1 !px-3" data-collection-count>{{ number_format($works->total()) }} أحجية</span>
                <span class="chip !py-1 !px-3" data-collection-saves>{{ number_format((int) $collection->saves_count) }} حفظ</span>
            </div>
            <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-2 leading-tight break-words">{{ $collection->title }}</h1>
            <p class="text-xs text-slate-400 mb-3" data-collection-curator>
                بقلم
                @if ($attribution)
                    <a href="{{ $attribution['url'] }}" class="font-bold text-slate-200 hover:text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $attribution['name'] }}</a>
                @else
                    <span class="font-bold text-slate-200">أحد أعضاء المنصة</span>
                @endif
                @if ($collection->first_published_at) · نُشرت {{ $collection->first_published_at->translatedFormat('j F Y') }} @endif
            </p>
            @if ($collection->description)
                <p class="text-sm text-slate-200 break-words whitespace-pre-line">{{ $collection->description }}</p>
            @endif

            <div class="mt-5 flex flex-wrap items-center gap-3">
                @if ($isOwner)
                    <a href="{{ route('collections.show', $collection->public_id) }}" class="btn-gem !py-2 !px-5 text-sm">إدارة المجموعة</a>
                    <span class="text-xs font-bold text-slate-400">هذه مجموعتك. لا حفظ ولا بلاغ على مجموعتك.</span>
                @elseif (! auth()->check())
                    <a href="{{ route('login') }}" class="btn-gem !py-2 !px-5 text-sm">سجّل الدخول لحفظ المجموعة</a>
                @elseif (! $canInteract)
                    <p class="text-xs font-bold text-gold" role="status">يلزم بريد إلكتروني موثَّق وحساب غير مقيَّد لحفظ المجموعات أو الإبلاغ عنها.</p>
                @else
                    @if ($isSaved)
                        <form method="POST" action="{{ route('collections.public.unsave', $collection->public_id) }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="chip !py-2 !px-4 text-sm text-gold focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-collection-unsave><x-engagement-icon name="bookmark" :filled="true" class="w-4 h-4" /> محفوظة · إزالة</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('collections.public.save', $collection->public_id) }}">
                            @csrf
                            <button type="submit" class="btn-gem !py-2 !px-5 text-sm" data-collection-save><x-engagement-icon name="bookmark" class="w-4 h-4" /> احفظ المجموعة</button>
                        </form>
                    @endif
                @endif
            </div>
        </header>

        @if ($works->isEmpty())
            <x-empty-state icon="puzzle" title="لا أحجيات متاحة حاليًا" message="لا توجد أحجيات ظاهرة في هذه المجموعة الآن." />
        @else
            <section aria-label="أحجيات المجموعة" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 anim-fade-up">
                @foreach ($works as $work)
                    @include('community._card', ['work' => $work, 'showCreator' => true])
                @endforeach
            </section>
            <div class="flex justify-center">{{ $works->links() }}</div>
        @endif

        {{-- E36: نقاش المجموعات العامة فقط (غير المدرجة لا نقاش لها: لا يصل إلى هنا شيء). --}}
        @if (! empty($discussion))
            @include('discussions._section', ['d' => $discussion])
        @endif

        @auth
            @if (! $isOwner && $canInteract)
                <details class="glass rounded-2xl p-5 anim-fade-up" data-collection-report>
                    <summary class="cursor-pointer text-sm font-bold text-slate-300 flex items-center gap-2 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="flag" class="w-4 h-4" />الإبلاغ عن هذه المجموعة</summary>
                    @if ($hasReported)
                        <p class="mt-4 text-sm text-slate-300" role="status">لديك بلاغ مفتوح على هذه المجموعة. سيراجعه فريق الإشراف.</p>
                    @else
                        <form method="POST" action="{{ route('collections.public.report', $collection->public_id) }}" class="mt-4 space-y-3">
                            @csrf
                            <label for="report-reason" class="block text-xs font-bold text-slate-300">سبب البلاغ</label>
                            <select id="report-reason" name="reason" required class="input-gem">
                                @foreach (\App\Models\UserCollectionReport::REASONS as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('reason') <p class="text-xs font-bold text-rose" role="alert">{{ $message }}</p> @enderror
                            <button type="submit" class="btn-secondary !py-2 !px-5 text-sm">إرسال البلاغ</button>
                        </form>
                    @endif
                </details>
            @endif
        @endauth
    </div>
@endsection
