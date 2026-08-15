<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Support\Carbon;

class ProjectTimelineController extends Controller
{
    /**
     * طلب الدكتور رقم 5: متابعة شاملة للمشروع
     * يجمع هذا الـ Controller كل الأحداث المرتبطة بالمشروع
     * (تحديثات، مراحل، حركات مالية، وسائط، موافقات) في خط زمني واحد موحّد
     */
    public function show(Project $project)
    {
        $this->authorize('view', $project);

        $project->load([
            'milestones.media',
            'finances' => fn ($q) => $q->verified(),
            'media',
            'approvals',
            'updates', // العلاقة الموجودة مسبقاً لتحديثات المشروع (ProjectUpdate)
            'tasks',
        ]);

        $timeline = collect();

        // 1) تحديثات المشروع العامة (الموجودة سابقاً بالنظام)
        // ملاحظة: عمود الوصف بجدول project_updates اسمه description وليس content
        foreach ($project->updates as $update) {
            $timeline->push([
                'type'  => 'update',
                'date'  => $update->created_at,
                'title' => 'تحديث: ' . ($update->title ?? 'تحديث على المشروع'),
                'body'  => $update->description ?? null,
                'icon'  => 'bi-megaphone',
                'color' => 'info',
                'ref'   => $update,
            ]);
        }

        // 2) مراحل العمل (Milestones)
        foreach ($project->milestones as $milestone) {
            $timeline->push([
                'type'  => 'milestone',
                'date'  => $milestone->completed_date ?? $milestone->updated_at,
                'title' => 'مرحلة: ' . $milestone->title . ' (' . $milestone->status_arabic . ')',
                'body'  => $milestone->description,
                'icon'  => $milestone->status === 'completed' ? 'bi-check-circle-fill' : 'bi-hourglass-split',
                'color' => $milestone->status === 'completed' ? 'success' : 'warning',
                'ref'   => $milestone,
            ]);
        }

        // 3) الحركات المالية المعتمدة فقط (للشفافية، لا نعرض ما لم يُراجَع بعد بالخط الزمني العام)
        foreach ($project->finances as $finance) {
            $timeline->push([
                'type'  => 'finance',
                'date'  => $finance->entry_date,
                'title' => ($finance->entry_type === 'donation' ? 'تبرع: ' : 'مصروف: ') . $finance->title,
                'body'  => number_format($finance->amount, 2) . ' ' . $finance->currency,
                'icon'  => $finance->entry_type === 'donation' ? 'bi-cash-coin' : 'bi-receipt',
                'color' => $finance->entry_type === 'donation' ? 'success' : 'danger',
                'ref'   => $finance,
            ]);
        }

        // 4) الوسائط الموثقة (صور/فيديوهات قبل وبعد)
        foreach ($project->media as $item) {
            $timeline->push([
                'type'  => 'media',
                'date'  => $item->created_at,
                'title' => 'توثيق ' . $item->media_type_arabic . ' (' . $item->phase_arabic . ')',
                'body'  => $item->caption,
                'icon'  => $item->media_type === 'video' ? 'bi-camera-reels' : 'bi-image',
                'color' => 'primary',
                'ref'   => $item,
            ]);
        }

        // 5) الموافقات الخطية
        foreach ($project->approvals as $approval) {
            $timeline->push([
                'type'  => 'approval',
                'date'  => $approval->reviewed_at ?? $approval->created_at,
                'title' => 'موافقة خطية: ' . $approval->status_arabic,
                'body'  => $approval->review_notes,
                'icon'  => 'bi-file-earmark-check',
                'color' => $approval->status === 'approved' ? 'success' : ($approval->status === 'rejected' ? 'danger' : 'warning'),
                'ref'   => $approval,
            ]);
        }

        // ترتيب الأحداث تنازلياً حسب التاريخ (الأحدث أولاً)
        $timeline = $timeline
            ->filter(fn ($e) => $e['date'] !== null)
            ->sortByDesc('date')
            ->values();

        // ملخص شامل للمشروع (لوحة معلومات سريعة أعلى الصفحة)
        $summary = [
            'progress_percentage' => $project->progress_percentage,
            'milestones_total'    => $project->milestones->count(),
            'milestones_done'     => $project->milestones->where('status', 'completed')->count(),
            'total_donations'     => $project->finances->where('entry_type', 'donation')->sum('amount'),
            'total_expenses'      => $project->finances->where('entry_type', 'expense')->sum('amount'),
            'media_count'         => $project->media->count(),
            'has_approved_consent'=> $project->has_approved_consent,
            'latest_approval'     => $project->approvals->sortByDesc('created_at')->first(),
        ];
        $summary['balance'] = $summary['total_donations'] - $summary['total_expenses'];

        return view('projects.timeline', compact('project', 'timeline', 'summary'));
    }
}