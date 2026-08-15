<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DiagnosisLog;
use App\Models\Fault;
use App\Models\Symptom;
use App\Models\Rule;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        // ── Quick stats ──────────────────────────────────────────────
        $totalDiagnoses   = DiagnosisLog::count();
        $todayDiagnoses   = DiagnosisLog::whereDate('created_at', today())->count();
        $totalFaults      = Fault::active()->count();
        $totalSymptoms    = Symptom::active()->count();
        $totalRules       = Rule::active()->count();

        // ── Top faults diagnosed (last 30 days) ─────────────────────
        $topFaults = DiagnosisLog::select('top_fault_name', DB::raw('count(*) as count'))
            ->whereNotNull('top_fault_name')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('top_fault_name')
            ->orderByDesc('count')
            ->limit(8)
            ->get();

        // ── Daily diagnoses chart (last 14 days) ────────────────────
        $dailyDiagnoses = DiagnosisLog::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('count(*) as count')
            )
            ->where('created_at', '>=', now()->subDays(14))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $chartDates  = [];
        $chartCounts = [];
        for ($i = 13; $i >= 0; $i--) {
            $date          = now()->subDays($i)->format('Y-m-d');
            $chartDates[]  = now()->subDays($i)->format('d/m');
            $chartCounts[] = $dailyDiagnoses[$date]->count ?? 0;
        }

        // ── Average CF of top diagnoses ──────────────────────────────
        $avgCF = DiagnosisLog::whereNotNull('top_cf')->avg('top_cf');

        // ── Recent logs ──────────────────────────────────────────────
        $recentLogs = DiagnosisLog::with('user')
                                  ->latest()
                                  ->limit(10)
                                  ->get();

        // ── Severity breakdown ───────────────────────────────────────
        $severityStats = Fault::select('severity', DB::raw('count(*) as count'))
                              ->groupBy('severity')
                              ->get()
                              ->keyBy('severity');

        return view('admin.dashboard', compact(
            'totalDiagnoses', 'todayDiagnoses', 'totalFaults',
            'totalSymptoms', 'totalRules', 'topFaults',
            'chartDates', 'chartCounts', 'avgCF',
            'recentLogs', 'severityStats'
        ));
    }
}