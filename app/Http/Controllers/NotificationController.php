<?php

namespace App\Http\Controllers;

use App\Services\Notifications\NotificationCategory;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationUrlResolver;
use App\Services\PlatformSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * مركز إشعارات اللاعب. ملكية صارمة: كل استعلام يمرّ بعلاقة المستخدم الحالي نفسها (notifications())، فمعرّف
 * إشعار مستخدم آخر = 404 (لا IDOR) ولا يوجد أي مسار إداري لقراءة صناديق اللاعبين. لا مسار GET يعدّل شيئًا؛
 * القراءة/الفتح/الحذف POST/DELETE بـCSRF. لا مسار هنا يمسّ XP أو عملة أو تقدّمًا أو سلسلة أو مشتريات.
 */
class NotificationController extends Controller
{
    public function index(Request $request, NotificationUrlResolver $resolver): View
    {
        $user = $request->user();
        $filter = $request->query('filter') === 'unread' ? 'unread' : 'all';
        $category = NotificationCategory::tryFrom((string) $request->query('category'));

        $notifications = $user->notifications()
            ->when($filter === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($category !== null, fn ($q) => $q->where('category', $category->value))
            ->paginate((int) config('player_notifications.page_size'))
            ->withQueryString();

        return view('notifications.index', [
            'notifications' => $notifications,
            'unreadCount' => $user->unreadNotifications()->count(),
            'filter' => $filter,
            'category' => $category,
            'categories' => NotificationCategory::cases(),
            'resolver' => $resolver,
        ]);
    }

    /** يعلّم مقروءًا ثم يحوّل إلى وجهة **داخلية** مُتحقَّق منها (أو يعود للمركز). لا Mutation لعب. */
    public function open(Request $request, string $id, NotificationUrlResolver $resolver): RedirectResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        $url = $resolver->resolve((array) $notification->data);

        return $url !== null ? redirect($url) : redirect()->route('notifications.index');
    }

    public function read(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return back();
    }

    /** المستخدم الحالي فقط - العلاقة نفسها تقيّد الصفوف. */
    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'تم تعليم كل الإشعارات كمقروءة.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->delete();

        return back();
    }

    public function preferences(Request $request, NotificationPreferenceService $preferences, PlatformSettingsService $settings): View
    {
        return view('notifications.preferences', [
            'preference' => $preferences->forUser($request->user()),
            'categories' => NotificationCategory::cases(),
            'globalEnabled' => (bool) $settings->get('notifications', 'notifications_enabled', true),
        ]);
    }

    public function updatePreferences(Request $request, NotificationPreferenceService $preferences): RedirectResponse
    {
        $flags = [];

        foreach (NotificationCategory::optional() as $category) {
            $column = $category->preferenceColumn();

            if ($column !== null && $request->has($column)) {
                $flags[$column] = $request->boolean($column);
            }
        }

        $preferences->update($request->user(), $flags);

        return redirect()->route('notifications.preferences')->with('success', 'تم حفظ تفضيلات الإشعارات.');
    }
}
