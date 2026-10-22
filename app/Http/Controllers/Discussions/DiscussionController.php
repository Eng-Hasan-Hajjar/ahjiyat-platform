<?php

namespace App\Http\Controllers\Discussions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Discussions\DiscussionCommentRequest;
use App\Http\Requests\Discussions\DiscussionReportRequest;
use App\Models\DiscussionComment;
use App\Services\Discussions\DiscussionCommentService;
use App\Services\Discussions\DiscussionLikeService;
use App\Services\Discussions\DiscussionReader;
use App\Services\Discussions\DiscussionReportService;
use App\Services\Discussions\DiscussionSubjectRegistry;
use App\Services\Discussions\DiscussionThreadService;
use App\Services\Engagement\EngagementException;
use App\Services\Social\BlockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * E36 - نقاش الكيانات العامة: كتابة (تعليق/رد/تعديل/حذف/إعجاب/بلاغ) + قراءة (رابط ثابت + صفحة ردود).
 *
 *  - الكتابة POST/PATCH/DELETE فقط خلف auth + verified + account.active + محدّدات معدّل مسمّاة؛ لا GET يكتب. كل منطق في الخدمات (لا قاعدة هنا).
 *  - الطلب يحمل النص ومعرّفات المسار فقط. الموضوع يُحلّ بالسجل لهذا المشاهد (غير ظاهر/محظور = 404 موحّد بلا كشف السبب)، والتعليق بمعرّف ULID عبر DiscussionThreadService::contextFor.
 *  - الرابط الثابت يعيد التوجيه إلى الصفحة العامة الصحيحة (رقم الصفحة والمرساة) **لتعليق قابل للعرض لهذا المشاهد فقط**؛ غيره 404 فلا يُسرَّب وجود تعليق محذوف/مخفي/لمحظور.
 *  - رسائل الأخطاء من الخدمات عامة ولا تكشف اتجاه الحظر ولا هوية أحد.
 *  - لا أثر اقتصادي/تقدّمي في أي مسار هنا.
 */
class DiscussionController extends Controller
{
    public function __construct(
        protected DiscussionSubjectRegistry $registry,
        protected DiscussionThreadService $threads,
        protected DiscussionCommentService $comments,
        protected DiscussionLikeService $likes,
        protected DiscussionReportService $reports,
        protected DiscussionReader $reader,
        protected BlockService $blocks,
    ) {}

    public function store(DiscussionCommentRequest $request, string $type, string $subject): RedirectResponse
    {
        $user = $request->user();
        $resolved = $this->registry->resolve($type, $subject, $user) ?? abort(404);

        try {
            $comment = $this->comments->create($user, $resolved, (string) $request->validated('body'));
        } catch (EngagementException $e) {
            return redirect($resolved->url.'#discussion')->withInput($request->only('body'))->with('discussion_error', $e->getMessage());
        }

        return redirect()->route('discussions.permalink', $comment->public_id)->with('discussion_status', 'نُشر تعليقك.');
    }

    public function reply(DiscussionCommentRequest $request, string $comment): RedirectResponse
    {
        $user = $request->user();
        $context = $this->threads->contextFor($comment, $user) ?? abort(404);

        try {
            $reply = $this->comments->create($user, $context->subject, (string) $request->validated('body'), $context->comment->public_id);
        } catch (EngagementException $e) {
            return redirect($context->subject->url.'#c-'.$context->comment->public_id)
                ->withInput(['body' => $request->input('body'), 'reply_to' => $context->comment->public_id])->with('discussion_error', $e->getMessage());
        }

        return redirect()->route('discussions.permalink', $reply->public_id)->with('discussion_status', 'نُشر ردّك.');
    }

    public function update(DiscussionCommentRequest $request, string $comment): RedirectResponse
    {
        $context = $this->threads->contextFor($comment, $request->user()) ?? abort(404);

        try {
            $this->comments->edit($request->user(), $comment, (string) $request->validated('body'));
        } catch (EngagementException $e) {
            return redirect($context->subject->url.'#c-'.$context->comment->public_id)
                ->withInput(['body' => $request->input('body'), 'edit_id' => $context->comment->public_id])->with('discussion_error', $e->getMessage());
        }

        return redirect()->route('discussions.permalink', $context->comment->public_id)->with('discussion_status', 'عُدّل التعليق.');
    }

    public function destroy(Request $request, string $comment): RedirectResponse
    {
        $context = $this->threads->contextFor($comment, $request->user()) ?? abort(404);

        try {
            $this->comments->delete($request->user(), $comment);
        } catch (EngagementException $e) {
            return redirect($context->subject->url.'#c-'.$context->comment->public_id)->with('discussion_error', $e->getMessage());
        }

        // بعد الحذف لا رابط ثابت للتعليق (لحد محايد أو يختفي): نعود إلى قسم النقاش.
        return redirect($context->subject->url.'#discussion')->with('discussion_status', 'حُذف تعليقك.');
    }

    public function like(Request $request, string $comment): RedirectResponse
    {
        try {
            $this->likes->like($request->user(), $comment);
        } catch (EngagementException $e) {
            return $this->toComment($comment)->with('discussion_error', $e->getMessage());
        }

        return $this->toComment($comment);
    }

    public function unlike(Request $request, string $comment): RedirectResponse
    {
        try {
            $this->likes->unlike($request->user(), $comment);
        } catch (EngagementException $e) {
            return $this->toComment($comment)->with('discussion_error', $e->getMessage());
        }

        return $this->toComment($comment);
    }

    public function report(DiscussionReportRequest $request, string $comment): RedirectResponse
    {
        try {
            $this->reports->report($request->user(), $comment, (string) $request->validated('reason'));
        } catch (EngagementException $e) {
            return $this->toComment($comment)->with('discussion_error', $e->getMessage());
        }

        return $this->toComment($comment)->with('discussion_status', 'وصلنا بلاغك وسيراجعه فريق الإشراف. شكرًا لك.');
    }

    /** رابط ثابت لتعليق: يعيد التوجيه إلى صفحته العامة (والصفحة/المرساة الصحيحة) أو 404 إن لم يكن قابلًا للعرض لهذا المشاهد. */
    public function permalink(Request $request, string $comment): RedirectResponse
    {
        abort_unless((bool) config('discussions.enabled', true), 404);

        $viewer = $request->user();
        $context = $this->threads->contextFor($comment, $viewer) ?? abort(404);
        $sort = in_array($request->query('dsort'), DiscussionReader::SORTS, true) ? (string) $request->query('dsort') : 'newest';
        $where = $this->reader->locate($context->comment, $context->thread, $viewer, $sort) ?? abort(404);

        // رسائل نجاح/خطأ الإجراء السابق تبقى عبر هذا التحويل الإضافي.
        $request->session()->reflash();
        $anchor = '#c-'.$context->comment->public_id;

        if ($where['replies_page'] !== null) {
            $query = $where['replies_page'] > 1 ? ['page' => $where['replies_page']] : [];

            return redirect()->to(route('discussions.replies', ['type' => $context->thread->subject_type, 'subject' => $context->subject->publicId, 'root' => $where['root']] + $query).$anchor);
        }

        $query = array_filter(['dpage' => $where['root_page'] > 1 ? $where['root_page'] : null, 'dsort' => $sort !== 'newest' ? $sort : null]);

        return redirect()->to($context->subject->url.($query === [] ? '' : '?'.http_build_query($query)).$anchor);
    }

    /** صفحة ردود جذر واحد (مرقَّمة، الأقدم أولًا). عامة للقراءة؛ التفاعل يتطلب حسابًا موثَّقًا. */
    public function replies(Request $request, string $type, string $subject, string $root)
    {
        abort_unless((bool) config('discussions.enabled', true), 404);

        $viewer = $request->user();
        $resolved = $this->registry->resolve($type, $subject, $viewer) ?? abort(404);
        $thread = $this->threads->findFor($resolved) ?? abort(404);
        $rootModel = DiscussionComment::query()->where('public_id', Str::upper($root))->where('discussion_thread_id', $thread->id)->whereNull('parent_id')->first() ?? abort(404);

        if ($viewer !== null && $rootModel->user_id !== null && in_array((int) $rootModel->user_id, $this->blocks->hiddenIds($viewer), true)) {
            abort(404);
        }

        $data = $this->reader->repliesPage($rootModel, $thread, $resolved, $viewer, max(1, (int) $request->query('page', 1))) ?? abort(404);

        return view('discussions.replies', ['d' => $data]);
    }

    protected function toComment(string $comment): RedirectResponse
    {
        return redirect()->route('discussions.permalink', $comment);
    }
}
