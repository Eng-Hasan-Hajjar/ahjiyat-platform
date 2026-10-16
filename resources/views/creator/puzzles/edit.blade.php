@extends('layouts.app')

@section('title', 'تعديل الأحجية')

@section('content')
    @php
        $editable = $revision->isEditable();
        $kinds = config('creators.kinds');
        $values = ['title' => $revision->title, 'puzzle_category_id' => $revision->puzzle_category_id, 'difficulty' => $revision->difficulty] + $content;
        $latestFinal = in_array($revision->status, ['approved', 'rejected'], true);
    @endphp
    <div class="max-w-3xl mx-auto space-y-6">
        <x-page-header :title="$revision->title" icon="pencil" :subtitle="($kinds[$revision->kind]['label'] ?? '').' · نسخة '.$revision->revision_number" :back="route('creator.puzzles.index')" backLabel="أحجياتي" class="anim-fade-up !mb-0">
            <x-slot:actions>
                <x-creator-status :status="$revision->status" />
                @if ($work->isArchived()) <x-creator-status status="archived" /> @endif
                @if ($work->isHidden()) <x-creator-status status="hidden" /> @endif
            </x-slot:actions>
        </x-page-header>

        @if ($work->isHidden() && $work->hidden_reason === \App\Models\CreatorPuzzle::HIDDEN_MODERATION)
            <div class="rounded-2xl border border-rose/30 bg-rose/10 text-rose px-5 py-4 text-sm font-bold" role="status">
                أخفى فريق الإشراف النسخة المنشورة من هذه الأحجية.
                @if ($work->hidden_note) <span class="block font-normal text-slate-300 mt-1">السبب: {{ $work->hidden_note }}</span> @endif
            </div>
        @elseif ($work->isHidden())
            <div class="rounded-2xl border border-gold/30 bg-gold/10 text-gold px-5 py-4 text-sm font-bold" role="status">منشورك مخفي مؤقتًا بسبب حالة حسابك كمبدع، ويعود تلقائيًا عند زوال السبب.</div>
        @endif

        @if (in_array($revision->status, ['changes_requested', 'rejected'], true) && $revision->review_note)
            <div class="rounded-2xl border border-gold/30 bg-gold/10 px-5 py-4 text-sm" role="status">
                <p class="font-black text-gold">{{ $revision->status === 'rejected' ? 'سبب الرفض' : 'التعديلات المطلوبة' }}</p>
                <p class="text-slate-200 mt-1">{{ $revision->review_note }}</p>
            </div>
        @endif

        @if ($revision->status === 'pending_review')
            <div class="rounded-2xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-300" role="status">أُرسلت للمراجعة في {{ $revision->submitted_at?->translatedFormat('j F Y H:i') }}. لا يمكن تعديلها وهي قيد المراجعة؛ اسحبها لتعديلها.</div>
        @elseif ($work->isPublished() && ! $editable && $revision->status === 'approved')
            <div class="rounded-2xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-300" role="status">هذه النسخة منشورة ولا تُعدَّل مباشرة. لتغييرها أنشئ نسخة جديدة؛ تبقى المنشورة حيّة حتى تُعتمد الجديدة.</div>
        @endif

        <form method="POST" action="{{ route('creator.puzzles.update', $work->public_id) }}" enctype="multipart/form-data" class="glass rounded-3xl p-6 space-y-5 anim-fade-up">
            @csrf @method('PUT')
            @include('creator.puzzles._fields', ['kind' => $revision->kind, 'values' => $values, 'categories' => $categories, 'kindConfig' => $kindConfig, 'locked' => ! $editable, 'imageUrl' => $revision->imageUrl()])
            @if ($editable)
                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="btn-gem !px-8">حفظ التعديلات</button>
                </div>
            @endif
        </form>

        @if ($editable && $revision->image_path)
            <form method="POST" action="{{ route('creator.puzzles.image.destroy', $work->public_id) }}" onsubmit="return confirm('حذف الصورة؟')">
                @csrf @method('DELETE')
                <button type="submit" class="btn-secondary text-xs"><x-ui-icon name="trash" class="w-4 h-4" />حذف الصورة الحالية</button>
            </form>
        @endif

        <section aria-label="إجراءات" class="glass rounded-3xl p-6 space-y-4">
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('creator.puzzles.preview', $work->public_id) }}" class="btn-secondary">معاينة كما سيراها اللاعب</a>

                @if ($editable)
                    <form method="POST" action="{{ route('creator.puzzles.submit', $work->public_id) }}">@csrf
                        <button type="submit" class="btn-gem !py-2.5 !px-6 text-sm">إرسال للمراجعة</button>
                    </form>
                @endif

                @if ($revision->status === 'pending_review')
                    <form method="POST" action="{{ route('creator.puzzles.withdraw', $work->public_id) }}">@csrf
                        <button type="submit" class="btn-secondary">سحب من المراجعة</button>
                    </form>
                @endif

                @if ($latestFinal)
                    <form method="POST" action="{{ route('creator.puzzles.revise', $work->public_id) }}">@csrf
                        <button type="submit" class="btn-secondary">{{ $revision->status === 'approved' ? 'إنشاء نسخة جديدة للتعديل' : 'إنشاء نسخة جديدة' }}</button>
                    </form>
                @endif

                @if ($work->isPublished() && ! $work->isArchived())
                    <form method="POST" action="{{ route('creator.puzzles.archive', $work->public_id) }}" onsubmit="return confirm('أرشفة الأحجية؟ ستُخفى من العامة ويمكنك إلغاء الأرشفة لاحقًا.')">@csrf
                        <button type="submit" class="btn-secondary">أرشفة</button>
                    </form>
                @elseif ($work->isArchived())
                    <form method="POST" action="{{ route('creator.puzzles.unarchive', $work->public_id) }}">@csrf
                        <button type="submit" class="btn-secondary">إلغاء الأرشفة</button>
                    </form>
                @endif

                @if ($revision->status !== 'pending_review' && (! $work->isPublished() || $editable))
                    <form method="POST" action="{{ route('creator.puzzles.destroy', $work->public_id) }}" onsubmit="return confirm('{{ $work->isPublished() ? 'حذف مسودة التعديل؟ تبقى النسخة المنشورة كما هي.' : 'حذف هذا العمل نهائيًا؟' }}')">@csrf @method('DELETE')
                        <button type="submit" class="btn-danger"><x-ui-icon name="trash" class="w-4 h-4" />{{ $work->isPublished() ? 'حذف مسودة التعديل' : 'حذف' }}</button>
                    </form>
                @endif
            </div>

            @if ($work->isPublished() && ! $work->isHidden() && ! $work->isArchived())
                <p class="text-xs text-slate-400">المنشورة حاليًا: <a class="text-amethyst font-bold hover:underline" href="{{ route('community.puzzles.show', $work->public_id) }}">{{ $work->published_title }}</a> ({{ number_format($work->plays_count) }} لاعب)</p>
            @endif
        </section>
    </div>
@endsection
