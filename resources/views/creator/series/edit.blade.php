@extends('layouts.app')

@section('title', 'تعديل السلسلة')

@section('content')
    @php
        $editable = $revision->isEditable();
        $latestFinal = in_array($revision->status, ['approved', 'rejected'], true);
        $values = ['title' => $revision->title, 'description' => $revision->description];
    @endphp
    <div class="max-w-3xl mx-auto space-y-6">
        <x-page-header :title="$revision->title" icon="pencil" :subtitle="'نسخة '.$revision->revision_number" :back="route('creator.series.index')" backLabel="سلاسلي" class="anim-fade-up !mb-0">
            <x-slot:actions>
                <x-creator-status :status="$revision->status" />
                @if ($series->isArchived()) <x-creator-status status="archived" /> @endif
                @if ($series->isHidden()) <x-creator-status status="hidden" /> @endif
            </x-slot:actions>
        </x-page-header>

        @if ($series->isHidden())
            <div class="rounded-2xl border border-rose/30 bg-rose/10 text-rose px-5 py-4 text-sm font-bold" role="status">
                أخفى فريق الإشراف النسخة المنشورة من هذه السلسلة.
                @if ($series->hidden_note) <span class="block font-normal text-slate-300 mt-1">السبب: {{ $series->hidden_note }}</span> @endif
            </div>
        @elseif ($series->isPublished() && ! $series->isArchived() && count($blockers) > 0)
            <div class="rounded-2xl border border-gold/30 bg-gold/10 text-gold px-5 py-4 text-sm font-bold" role="status" data-series-blockers>
                سلسلتك غير ظاهرة حاليًا، وتعود تلقائيًا عند زوال السبب:
                <ul class="list-disc ps-5 mt-1 font-normal text-slate-200">@foreach ($blockers as $reason) <li>{{ $reason }}</li> @endforeach</ul>
            </div>
        @endif

        @if (in_array($revision->status, ['changes_requested', 'rejected'], true) && $revision->review_note)
            <div class="rounded-2xl border border-gold/30 bg-gold/10 px-5 py-4 text-sm" role="status">
                <p class="font-black text-gold">{{ $revision->status === 'rejected' ? 'سبب الرفض' : 'التعديلات المطلوبة' }}</p>
                <p class="text-slate-200 mt-1">{{ $revision->review_note }}</p>
            </div>
        @endif

        @if ($revision->status === 'pending_review')
            <div class="rounded-2xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-300" role="status">أُرسلت للمراجعة في {{ $revision->submitted_at?->translatedFormat('j F Y H:i') }}. لا يمكن تعديلها وهي قيد المراجعة؛ اسحبها لتعديلها.</div>
        @elseif ($series->isPublished() && ! $editable && $revision->status === 'approved')
            <div class="rounded-2xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-300" role="status">هذه النسخة منشورة ولا تُعدَّل مباشرة. لتغييرها أنشئ نسخة جديدة؛ تبقى المنشورة حيّة حتى تُعتمد الجديدة.</div>
        @endif

        <form method="POST" action="{{ route('creator.series.update', $series->public_id) }}" enctype="multipart/form-data" class="glass rounded-3xl p-6 space-y-5 anim-fade-up">
            @csrf @method('PUT')
            @include('creator.series._form', ['values' => $values, 'works' => $works, 'selected' => $selected, 'current' => $current, 'limits' => $limits, 'locked' => ! $editable, 'coverUrl' => $revision->coverUrl()])
            @if ($editable)
                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="btn-gem !px-8">حفظ التعديلات</button>
                </div>
            @endif
        </form>

        <section aria-label="إجراءات" class="glass rounded-3xl p-6 space-y-4">
            <div class="flex flex-wrap gap-3">
                @if ($editable)
                    <form method="POST" action="{{ route('creator.series.submit', $series->public_id) }}">@csrf
                        <button type="submit" class="btn-gem !py-2.5 !px-6 text-sm">إرسال للمراجعة</button>
                    </form>
                @endif

                @if ($revision->status === 'pending_review')
                    <form method="POST" action="{{ route('creator.series.withdraw', $series->public_id) }}">@csrf
                        <button type="submit" class="btn-secondary">سحب من المراجعة</button>
                    </form>
                @endif

                @if ($latestFinal)
                    <form method="POST" action="{{ route('creator.series.revise', $series->public_id) }}">@csrf
                        <button type="submit" class="btn-secondary">{{ $revision->status === 'approved' ? 'إنشاء نسخة جديدة للتعديل' : 'إنشاء نسخة جديدة' }}</button>
                    </form>
                @endif

                @if ($series->isPublished() && ! $series->isArchived())
                    <form method="POST" action="{{ route('creator.series.archive', $series->public_id) }}" onsubmit="return confirm('أرشفة السلسلة؟ ستُخفى من العامة ويمكنك إلغاء الأرشفة لاحقًا.')">@csrf
                        <button type="submit" class="btn-secondary">أرشفة</button>
                    </form>
                @elseif ($series->isArchived())
                    <form method="POST" action="{{ route('creator.series.unarchive', $series->public_id) }}">@csrf
                        <button type="submit" class="btn-secondary">إلغاء الأرشفة</button>
                    </form>
                @endif

                @if ($revision->status !== 'pending_review' && (! $series->isPublished() || $editable))
                    <form method="POST" action="{{ route('creator.series.destroy', $series->public_id) }}" onsubmit="return confirm('{{ $series->isPublished() ? 'حذف مسودة التعديل؟ تبقى النسخة المنشورة كما هي.' : 'حذف هذه السلسلة نهائيًا؟' }}')">@csrf @method('DELETE')
                        <button type="submit" class="btn-danger"><x-ui-icon name="trash" class="w-4 h-4" />{{ $series->isPublished() ? 'حذف مسودة التعديل' : 'حذف' }}</button>
                    </form>
                @endif
            </div>

            @if ($publicUrl && count($blockers) === 0 && ! $series->isArchived())
                <p class="text-xs text-slate-400">المنشورة حاليًا: <a class="text-amethyst font-bold hover:underline" href="{{ $publicUrl }}">{{ $series->published_title }}</a></p>
            @endif
        </section>
    </div>
@endsection
