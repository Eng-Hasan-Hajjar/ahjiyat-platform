<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DiagnosisLog;

class LogController extends Controller
{
    public function index()
    {
        $logs = DiagnosisLog::with('user')
                            ->latest()
                            ->paginate(25);

        return view('admin.logs.index', compact('logs'));
    }
}