<?php

namespace App\Http\Controllers;

use App\Models\{ProjectUpdate, Project};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProjectUpdateController extends Controller
{
    public function store(Request $request, Project $project)
    {
        $data = $request->validate([
            'title'               => 'required|string|max:255',
            'description'         => 'required|string|min:10',
            'progress_percentage' => 'required|integer|min:0|max:100',
            'images.*'            => 'nullable|image|max:5120',
        ]);

        $data['project_id'] = $project->id;
        $data['user_id']    = Auth::id();

        if ($request->hasFile('images')) {
            $paths = [];
            foreach ($request->file('images') as $img) {
                $paths[] = $img->store('projects/updates', 'public');
            }
            $data['images'] = $paths;
        }

        ProjectUpdate::create($data);
        $project->update(['progress_percentage' => $data['progress_percentage']]);

        return back()->with('success', 'تم نشر تحديث المشروع بنجاح.');
    }

    public function destroy(ProjectUpdate $update)
    {
        $this->authorize('update', $update->project);
        $update->delete();
        return back()->with('success', 'تم حذف التحديث.');
    }
}