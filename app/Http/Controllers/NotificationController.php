<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function index()
    {
        $notifications = Auth::user()->notifications()->paginate(20);
        Auth::user()->unreadNotifications->markAsRead();

        return view('notifications.index', compact('notifications'));
    }

    public function markAllRead()
    {
        Auth::user()->unreadNotifications->markAsRead();
        return back()->with('success', 'تم تعليم جميع الإشعارات كمقروءة.');
    }

    public function destroy($id)
    {
        Auth::user()->notifications()->where('id', $id)->delete();
        return back()->with('success', 'تم حذف الإشعار.');
    }
}