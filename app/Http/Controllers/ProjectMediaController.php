<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProjectMediaController extends Controller
{
    /**
     * عرض معرض الصور والفيديوهات الكامل للمشروع، مع فلترة قبل/بعد
     */
    public function index(Project $project, Request $request)
    {
        $phase = $request->query('phase'); // before | during | after | null = all

        $query = $project->media()->with(['milestone', 'uploadedBy'])->latest();

        if ($phase && in_array($phase, ['before', 'during', 'after'])) {
            $query->where('phase', $phase);
        }

        $media = $query->paginate(24);
        $milestones = $project->milestones; // لعرض فلتر اختياري حسب المرحلة

        return view('media.index', compact('project', 'media', 'milestones', 'phase'));
    }

    /**
     * رفع صورة أو فيديو جديد
     */
    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'files'          => 'required|array|min:1|max:10',
            'files.*'        => 'file|mimes:jpg,jpeg,png,webp,mp4,mov,avi|max:51200', // 50MB لكل ملف
            'phase'          => 'required|in:before,during,after',
            'milestone_id'   => 'nullable|exists:project_milestones,id',
            'caption'        => 'nullable|string|max:255',
        ]);

        // تأكيد إضافي أن المرحلة المختارة تخص هذا المشروع فعلاً
        if (!empty($validated['milestone_id'])) {
            $belongs = $project->milestones()->where('id', $validated['milestone_id'])->exists();
            if (!$belongs) {
                return back()->with('error', 'المرحلة المحددة لا تنتمي لهذا المشروع.');
            }
        }

        $videoExtensions = ['mp4', 'mov', 'avi'];

        foreach ($request->file('files') as $file) {
            $extension = strtolower($file->getClientOriginalExtension());
            $mediaType = in_array($extension, $videoExtensions) ? 'video' : 'image';

            $path = $file->store('project-media/' . $project->id, 'public');

            ProjectMedia::create([
                'project_id'   => $project->id,
                'milestone_id' => $validated['milestone_id'] ?? null,
                'uploaded_by'  => auth()->id(),
                'media_type'   => $mediaType,
                'phase'        => $validated['phase'],
                'file_path'    => $path,
                'caption'      => $validated['caption'] ?? null,
            ]);
        }

        return back()->with('success', 'تم رفع الملفات بنجاح.');
    }

    /**
     * حذف ملف وسائط
     */
    public function destroy(ProjectMedia $media)
    {
        $this->authorize('update', $media->project);

        Storage::disk('public')->delete($media->file_path);
        $media->delete();

        return back()->with('success', 'تم حذف الملف.');
    }

    /**
     * عرض مقارنة "قبل / بعد" لمرحلة معينة بشكل مرئي جنباً إلى جنب
     */
    public function compare(Project $project, $milestoneId)
    {
        $milestone = $project->milestones()->findOrFail($milestoneId);

        $before = $milestone->beforeMedia()->get();
        $after  = $milestone->afterMedia()->get();

        return view('media.compare', compact('project', 'milestone', 'before', 'after'));
    }
}