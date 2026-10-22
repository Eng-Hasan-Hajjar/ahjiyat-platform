@php
    /**
     * E36: تعليق أو ردّ. **كل النصوص مهرَّبة** ({{ }}) ولا HTML خام في أي مكان؛ الروابط داخل النص لا تُحوَّل إلى روابط.
     * القارئ (DiscussionReader) لا يحمّل نصًّا لتعليق محذوف أو مخفي أصلًا (body = null)، فلا يمكن للقالب تسريبه.
     * @var array $c  @var array $d (subject/can/locked/max/minutes)  @var bool $nested  (الرد لا يحمل ردودًا ولا يقبل ردًّا: مستوى واحد فقط)
     */
    $nested = (bool) ($nested ?? false);
    $id = $c['id'];
    $subject = $d['subject'];
    $max = (int) ($d['max'] ?? config('discussions.body.max'));
    $replyErr = old('reply_to') === $id ? $errors->getBag('discussion')->first('body') : null;
    $editErr = old('edit_id') === $id ? $errors->getBag('discussion')->first('body') : null;
@endphp
<article id="c-{{ $id }}" data-comment="{{ $id }}" data-state="{{ $c['state'] }}" @class(['rounded-xl border border-white/10 p-3 md:p-4 space-y-2 scroll-mt-24', 'bg-white/[0.03]' => ! $nested, 'bg-white/[0.02]' => $nested]) aria-label="{{ $nested ? 'رد' : 'تعليق' }}">
    @if ($c['state'] === 'live')
        <header class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
            @if ($c['author'])
                @if ($c['author']['url'])
                    <a href="{{ $c['author']['url'] }}" class="font-black text-slate-100 hover:text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst break-words">{{ $c['author']['name'] }}</a>
                @else
                    <span class="font-black text-slate-100 break-words">{{ $c['author']['name'] }}</span>
                @endif
            @endif
            @if ($c['mine']) <x-status-badge tone="primary" :small="true">تعليقك</x-status-badge> @endif
            <time datetime="{{ $c['created_at']?->toIso8601String() }}" class="text-slate-400">{{ $c['created_at']?->diffForHumans() }}</time>
            @if ($c['edited']) <span class="text-slate-500">· تم التعديل</span> @endif
        </header>

        <p class="text-sm text-slate-100 whitespace-pre-line break-words leading-relaxed" dir="auto" data-comment-body>{{ $c['body'] }}</p>

        <div class="flex flex-wrap items-center gap-2 pt-1">
            @if ($c['can_like'])
                <form method="POST" action="{{ route('discussions.likes.'.($c['liked'] ? 'destroy' : 'store'), $id) }}">
                    @csrf
                    @if ($c['liked']) @method('DELETE') @endif
                    <button type="submit" class="chip !py-1.5 !px-3 text-xs {{ $c['liked'] ? 'text-gold' : 'text-slate-300' }} focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" aria-pressed="{{ $c['liked'] ? 'true' : 'false' }}" data-comment-like>
                        <span aria-hidden="true">{{ $c['liked'] ? '♥' : '♡' }}</span>
                        <span>{{ $c['liked'] ? 'أعجبني' : 'إعجاب' }}</span>
                        <span class="tabular-nums" aria-label="{{ $c['likes'] }} إعجاب">{{ number_format($c['likes']) }}</span>
                    </button>
                </form>
            @elseif ($c['likes'] > 0)
                <span class="chip !py-1.5 !px-3 text-xs text-slate-300" aria-label="{{ $c['likes'] }} إعجاب"><span aria-hidden="true">♥</span> <span class="tabular-nums">{{ number_format($c['likes']) }}</span></span>
            @endif
        </div>

        <div class="flex flex-wrap items-start gap-x-4 gap-y-2 text-xs">
            @if (! $nested && $c['can_reply'])
                <details class="w-full md:w-auto md:min-w-[18rem]" @if (old('reply_to') === $id) open @endif>
                    <summary class="cursor-pointer font-bold text-slate-300 hover:text-white rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst inline-flex items-center gap-1"><x-ui-icon name="chat" class="w-3.5 h-3.5" />رد</summary>
                    <div class="mt-2">
                        @include('discussions._composer', ['action' => route('discussions.replies.store', $id), 'method' => 'POST', 'id' => 'reply-'.$id, 'label' => 'اكتب ردّك', 'submit' => 'نشر الرد', 'value' => old('reply_to') === $id ? old('body') : '', 'max' => $max, 'error' => $replyErr, 'placeholder' => 'اكتب ردّك…', 'marker' => ['reply_to', $id]])
                    </div>
                </details>
            @endif

            @if ($c['can_edit'])
                <details class="w-full md:w-auto md:min-w-[18rem]" @if (old('edit_id') === $id) open @endif>
                    <summary class="cursor-pointer font-bold text-slate-300 hover:text-white rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst inline-flex items-center gap-1"><x-ui-icon name="pencil" class="w-3.5 h-3.5" />تعديل</summary>
                    <div class="mt-2">
                        <p class="text-[11px] text-slate-400 mb-1">التعديل متاح خلال {{ (int) ($d['minutes'] ?? config('discussions.edit_window_minutes')) }} دقيقة من النشر.</p>
                        @include('discussions._composer', ['action' => route('discussions.comments.update', $id), 'method' => 'PATCH', 'id' => 'edit-'.$id, 'label' => 'تعديل التعليق', 'submit' => 'حفظ التعديل', 'value' => old('edit_id') === $id ? old('body') : $c['body'], 'max' => $max, 'error' => $editErr, 'marker' => ['edit_id', $id]])
                    </div>
                </details>
            @endif

            @if ($c['can_delete'])
                <form method="POST" action="{{ route('discussions.comments.destroy', $id) }}" onsubmit="return confirm('حذف تعليقك؟ لا يمكن التراجع.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="font-bold text-slate-400 hover:text-rose rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst inline-flex items-center gap-1" data-comment-delete><x-ui-icon name="trash" class="w-3.5 h-3.5" />حذف</button>
                </form>
            @endif

            @if ($c['can_report'])
                @if ($c['reported'])
                    <span class="text-slate-500 inline-flex items-center gap-1" role="status"><x-ui-icon name="flag" class="w-3.5 h-3.5" />تم الإبلاغ</span>
                @else
                    <details class="w-full md:w-auto">
                        <summary class="cursor-pointer font-bold text-slate-400 hover:text-white rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst inline-flex items-center gap-1"><x-ui-icon name="flag" class="w-3.5 h-3.5" />إبلاغ</summary>
                        <form method="POST" action="{{ route('discussions.reports.store', $id) }}" class="mt-2 space-y-2">
                            @csrf
                            <label for="report-{{ $id }}" class="block text-[11px] font-bold text-slate-300">سبب البلاغ</label>
                            <select id="report-{{ $id }}" name="reason" required class="input-gem">
                                @foreach (\App\Models\DiscussionCommentReport::REASONS as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn-secondary !py-1.5 !px-4 text-xs">إرسال البلاغ</button>
                        </form>
                    </details>
                @endif
            @endif
        </div>
    @elseif ($c['state'] === 'deleted')
        <p class="text-sm italic text-slate-500" data-comment-tombstone>تم حذف هذا التعليق</p>
    @else
        {{-- مخفي إشرافيًا: لا نص هنا أبدًا. كاتبه يعرف أن الإدارة أخفته وسببه؛ المشرف يعرف أنه مخفي؛ غيرهما يرى لحدًا محايدًا. --}}
        @if ($c['mine'])
            <p class="text-sm text-slate-400" data-comment-hidden>أخفت الإدارة تعليقك{{ $c['hidden_reason'] ? ' (السبب: '.$c['hidden_reason'].')' : '' }}.</p>
        @elseif ($d['moderator'] ?? false)
            <p class="text-sm text-slate-400" data-comment-hidden>تعليق مخفي إشرافيًا (يمكنك استعادته من لوحة الإدارة).</p>
        @else
            <p class="text-sm italic text-slate-500" data-comment-hidden>تم إخفاء هذا التعليق من قبل الإدارة</p>
        @endif
    @endif

    @if (! $nested && ! empty($c['replies']))
        <ol class="mt-2 space-y-2 border-s-2 border-white/10 ps-3 md:ps-4" aria-label="الردود" data-replies>
            @foreach ($c['replies'] as $reply)
                <li>@include('discussions._comment', ['c' => $reply, 'd' => $d, 'nested' => true])</li>
            @endforeach
        </ol>
    @endif

    @if (! $nested && ($c['more_replies'] ?? 0) > 0)
        <a href="{{ route('discussions.replies', ['type' => $subject['type'], 'subject' => $subject['id'], 'root' => $id]) }}"
           class="inline-flex items-center gap-1 text-xs font-bold text-amethyst hover:underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-more-replies>
            عرض كل الردود ({{ number_format($c['reply_count']) }})<x-ui-icon name="chevron-left" class="w-3.5 h-3.5" />
        </a>
    @endif
</article>
