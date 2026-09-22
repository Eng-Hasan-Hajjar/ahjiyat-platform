<?php

namespace App\Services;

use App\Models\FraudFlag;
use App\Models\GemTransaction;
use App\Models\PuzzleAttempt;
use App\Models\RedemptionRequest;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportService
{
       /**
     * تحييد CSV Formula Injection (بند 69، مُعزَّز E7.1). الحماية الأولى
     * كانت تفحص فقط أول محرف مباشرة - لا تكفي، لأن بعض محاولات الحقن تضع
     * محارف تحكّم (Tab/CR/LF/مسافات) قبل رمز الصيغة لتفادي هذا الفحص
     * البسيط. هنا نتجاهل أي محارف تحكّم بادئة عند الفحص فقط - لا نُغيِّر
     * القيمة الأصلية المخزَّنة، فقط نُسبقها بمسافة عند اكتشاف الخطر. نص
     * عربي طبيعي لا يبدأ بأي من هذه المحارف أصلاً فلن يتأثر إطلاقاً.
     */
    protected function sanitize($value): string
    {
        $value = (string) $value;

        $significant = ltrim($value, "\t\n\r\x0B\x0C ");

        if ($significant !== '' && preg_match('/^[=+\-@]/', $significant)) {
            return ' '.$value;
        }

        return $value;
    }

    protected function stream(string $filename, array $headers, callable $rowGenerator): StreamedResponse
    {
        return Response::streamDownload(function () use ($headers, $rowGenerator) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);

            $rowGenerator($handle);

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function writeRow($handle, array $row): void
    {
        fputcsv($handle, array_map(fn ($v) => $this->sanitize($v), $row));
    }

    public function usersReport(AnalyticsPeriod $period): StreamedResponse
    {
        return $this->stream('users-report.csv', ['الاسم', 'البريد', 'موثَّق', 'مجمَّد', 'تاريخ التسجيل'], function ($handle) use ($period) {
            User::whereBetween('created_at', [$period->start, $period->end])
                ->orderBy('created_at')
                ->chunk(500, function ($users) use ($handle) {
                    foreach ($users as $u) {
                        $this->writeRow($handle, [
                            $u->name, $u->email, $u->email_verified_at ? 'نعم' : 'لا',
                            $u->is_frozen ? 'نعم' : 'لا', $u->created_at->format('Y-m-d H:i'),
                        ]);
                    }
                });
        });
    }

    public function puzzleActivityReport(AnalyticsPeriod $period): StreamedResponse
    {
        return $this->stream('puzzle-activity-report.csv', ['الأحجية', 'صحيحة', 'استُخدم تلميح', 'الزمن (ث)', 'التاريخ'], function ($handle) use ($period) {
            PuzzleAttempt::with('puzzle:id,title')
                ->whereBetween('created_at', [$period->start, $period->end])
                ->orderBy('created_at')
                ->chunk(500, function ($attempts) use ($handle) {
                    foreach ($attempts as $a) {
                        $this->writeRow($handle, [
                            $a->puzzle?->title ?? '—', $a->is_correct ? 'نعم' : 'لا',
                            $a->used_hint ? 'نعم' : 'لا', $a->time_taken_seconds, $a->created_at->format('Y-m-d H:i'),
                        ]);
                    }
                });
        });
    }

    public function campaignProgressReport(AnalyticsPeriod $period): StreamedResponse
    {
        return $this->stream('campaign-progress-report.csv', ['الحملة', 'المرحلة', 'البوابة', 'الخطوة', 'مكتملة', 'التاريخ'], function ($handle) use ($period) {
            UserCampaignProgress::with('step.gate.stage.campaign')
                ->whereBetween('created_at', [$period->start, $period->end])
                ->orderBy('created_at')
                ->chunk(500, function ($rows) use ($handle) {
                    foreach ($rows as $r) {
                        $this->writeRow($handle, [
                            $r->step?->gate?->stage?->campaign?->title ?? '—',
                            $r->step?->gate?->stage?->title ?? '—',
                            $r->step?->gate?->title ?? '—',
                            $r->step?->title ?? '—',
                            $r->completed_at ? 'نعم' : 'لا',
                            $r->created_at->format('Y-m-d H:i'),
                        ]);
                    }
                });
        });
    }

    public function gemsReport(AnalyticsPeriod $period): StreamedResponse
    {
        return $this->stream('gems-report.csv', ['المستخدم', 'النوع', 'القيمة', 'السبب', 'التاريخ'], function ($handle) use ($period) {
            GemTransaction::with('user:id,name')
                ->whereBetween('created_at', [$period->start, $period->end])
                ->orderBy('created_at')
                ->chunk(500, function ($transactions) use ($handle) {
                    foreach ($transactions as $t) {
                        $this->writeRow($handle, [
                            $t->user?->name ?? '—', $t->type, $t->amount, $t->reason, $t->created_at->format('Y-m-d H:i'),
                        ]);
                    }
                });
        });
    }

    public function redemptionsReport(AnalyticsPeriod $period): StreamedResponse
    {
        return $this->stream('redemptions-report.csv', ['المستخدم', 'الجواهر', 'المكافأة', 'الحالة', 'تاريخ الطلب', 'تاريخ المراجعة'], function ($handle) use ($period) {
            RedemptionRequest::with('user:id,name')
                ->whereBetween('created_at', [$period->start, $period->end])
                ->orderBy('created_at')
                ->chunk(500, function ($requests) use ($handle) {
                    foreach ($requests as $r) {
                        $this->writeRow($handle, [
                            $r->user?->name ?? '—', $r->gems_amount, $r->reward_description, $r->status,
                            $r->created_at->format('Y-m-d H:i'), $r->reviewed_at?->format('Y-m-d H:i') ?? '—',
                        ]);
                    }
                });
        });
    }

    public function securityReport(AnalyticsPeriod $period): StreamedResponse
    {
        return $this->stream('security-report.csv', ['المستخدم', 'الخطورة', 'السبب', 'مُعالَجة', 'التاريخ'], function ($handle) use ($period) {
            FraudFlag::with('user:id,name')
                ->whereBetween('created_at', [$period->start, $period->end])
                ->orderBy('created_at')
                ->chunk(500, function ($flags) use ($handle) {
                    foreach ($flags as $f) {
                        $this->writeRow($handle, [
                            $f->user?->name ?? '—', $f->severity, $f->reason, $f->resolved ? 'نعم' : 'لا', $f->created_at->format('Y-m-d H:i'),
                        ]);
                    }
                });
        });
    }
}