<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Symptom;
use App\Models\Fault;
use App\Models\Rule;
use App\Models\DiagnosisLog;
use Illuminate\Http\Request;

// ─────────────────────────────────────────────
// SYMPTOM CONTROLLER
// ─────────────────────────────────────────────
class SymptomController extends Controller
{
    public function index()
    {
        $symptoms = Symptom::orderBy('category')->orderBy('name_ar')->paginate(20);
        return view('admin.symptoms.index', compact('symptoms'));
    }

    public function create()
    {
        $categories = Symptom::CATEGORIES;
        return view('admin.symptoms.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'name_ar'     => 'required|string|max:255',
            'description' => 'nullable|string',
            'category'    => 'required|string',
            'icon'        => 'nullable|string|max:100',
            'is_active'   => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        Symptom::create($data);
        return redirect()->route('admin.symptoms.index')->with('success', 'تمت إضافة العرض بنجاح');
    }

    public function edit(Symptom $symptom)
    {
        $categories = Symptom::CATEGORIES;
        return view('admin.symptoms.edit', compact('symptom', 'categories'));
    }

    public function update(Request $request, Symptom $symptom)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'name_ar'     => 'required|string|max:255',
            'description' => 'nullable|string',
            'category'    => 'required|string',
            'icon'        => 'nullable|string|max:100',
            'is_active'   => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $symptom->update($data);
        return redirect()->route('admin.symptoms.index')->with('success', 'تم تعديل العرض بنجاح');
    }

    public function destroy(Symptom $symptom)
    {
        $symptom->delete();
        return redirect()->route('admin.symptoms.index')->with('success', 'تم حذف العرض');
    }
}