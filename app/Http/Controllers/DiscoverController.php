<?php

namespace App\Http\Controllers;

use App\Services\Discovery\DiscoveryService;
use Illuminate\Http\Request;

/** E27: مركز الاكتشاف (قراءة فقط). عام للضيوف؛ المسجَّل يضاف له «تابع» و«مقترحة لك». لا مكافآت ولا كتابة. */
class DiscoverController extends Controller
{
    public function __construct(protected DiscoveryService $discovery) {}

    public function index(Request $request)
    {
        return view('discover.index', $this->discovery->hub($request->user()));
    }
}
