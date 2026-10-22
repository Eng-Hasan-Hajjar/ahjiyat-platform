@php
    /**
     * E36: قسم النقاش المضمَّن في الصفحات العامة (أحجية مجتمع / سلسلة / مجموعة عامة). يُعرض فقط حين $discussion ليس null.
     * قراءة للجميع؛ الكتابة للمستخدم الموثَّق غير المجمَّد وغير المحظور على هذا المحتوى والنقاش غير مقفل. نقاش دائم غير متزامن (ليس دردشة).
     * التعليق/الرد/الإعجاب لا يمنح جواهر ولا XP ولا مهام ولا سلسلة.
     * @var array $d  (DiscussionReader::forSubject)
     */
    $bag = $errors->getBag('discussion');
    $rootErr = (! old('reply_to') && ! old('edit_id')) ? $bag->first('body') : null;
    $why = $d['can']['why'] ?? null;
    $base = $d['subject']['url'];
@endphp
<section id="discussion" tabindex="-1" aria-labelledby="discussion-title" class="glass rounded-2xl p-5 md:p-6 space-y-5 anim-fade-up focus:outline-none" data-discussion>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="discussion-title" class="font-display font-black text-lg text-white flex items-center gap-2">
            <x-ui-icon name="chat" class="w-5 h-5 text-amethyst" />النقاش
            <span class="text-sm font-bold text-slate-400 tabular-nums" data-discussion-count>({{ number_format($d['count']) }})</span>
        </h2>
        @if ($d['count'] > 1)
            <nav aria-label="ترتيب التعليقات" class="flex items-center gap-2 text-xs">
                @foreach (['newest' => 'الأحدث', 'oldest' => 'الأقدم'] as $key => $label)
                    <a href="{{ $base }}?dsort={{ $key }}#discussion" @if ($d['sort'] === $key) aria-current="true" @endif
                       class="chip !py-1 !px-3 {{ $d['sort'] === $key ? 'text-white border-amethyst/50' : 'text-slate-400' }} focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $label }}</a>
                @endforeach
            </nav>
        @endif
    </div>

    @if (session('discussion_status'))
        <p role="status" class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-2 text-sm font-bold">{{ session('discussion_status') }}</p>
    @endif
    @if (session('discussion_error'))
        <p role="alert" class="rounded-xl border border-rose/30 bg-rose/10 text-rose px-4 py-2 text-sm font-bold">{{ session('discussion_error') }}</p>
    @endif

    @if ($d['locked'])
        <p class="rounded-xl border border-gold/30 bg-gold/10 text-gold px-4 py-2 text-sm font-bold flex items-center gap-2" role="status" data-discussion-locked><x-ui-icon name="lock" class="w-4 h-4" />تم إغلاق النقاش من قبل الإدارة. يمكنك القراءة والإبلاغ فقط.</p>
    @endif

    @if ($why === 'guest')
        <p class="text-sm text-slate-300">
            <a href="{{ route('login') }}" class="font-bold text-amethyst underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">سجّل الدخول</a>
            لتشارك في النقاش.
        </p>
    @elseif ($why === 'unverified')
        <p class="text-sm text-gold font-bold">وثّق بريدك الإلكتروني لتتمكن من المشاركة في النقاش.</p>
    @elseif ($why === 'frozen')
        <p class="text-sm text-gold font-bold">حسابك مقيَّد مؤقتًا ولا يمكنه المشاركة في النقاش الآن.</p>
    @elseif ($why === 'blocked')
        <p class="text-sm text-slate-400">لا يمكنك المشاركة في هذا النقاش حاليًا.</p>
    @elseif ($d['can']['comment'])
        @include('discussions._composer', ['action' => route('discussions.comments.store', ['type' => $d['subject']['type'], 'subject' => $d['subject']['id']]), 'method' => 'POST', 'id' => 'discussion-body', 'label' => 'اكتب تعليقك', 'submit' => 'نشر التعليق', 'value' => (! old('reply_to') && ! old('edit_id')) ? old('body') : '', 'max' => $d['max'], 'error' => $rootErr, 'placeholder' => 'شارك رأيك بأدب…'])
    @endif

    @if ($d['roots']->isEmpty())
        <p class="text-sm text-slate-400 text-center py-6" data-discussion-empty>
            {{ $d['locked'] ? 'لا تعليقات في هذا النقاش.' : 'لا تعليقات بعد. كن أول من يبدأ النقاش.' }}
        </p>
    @else
        <ol class="space-y-3" aria-label="التعليقات" data-discussion-list>
            @foreach ($d['roots'] as $c)
                <li>@include('discussions._comment', ['c' => $c, 'd' => $d, 'nested' => false])</li>
            @endforeach
        </ol>
        @if ($d['roots']->hasPages())
            <div class="flex justify-center">{{ $d['roots']->links() }}</div>
        @endif
    @endif
</section>
