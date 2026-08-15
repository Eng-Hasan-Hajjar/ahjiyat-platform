<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Fault;
use App\Models\Symptom;
use Illuminate\Http\Request;

class FaultController extends Controller
{
    public function index()
    {
        $faults = Fault::withCount('symptoms')->orderBy('category')->paginate(20);
        return view('admin.faults.index', compact('faults'));
    }

    public function create()
    {
        $symptoms = Symptom::active()->orderBy('name_ar')->get();
        $severities = Fault::SEVERITIES;
        $categories = Symptom::CATEGORIES;
        return view('admin.faults.create', compact('symptoms', 'severities', 'categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                => 'required|string|max:255',
            'name_ar'             => 'required|string|max:255',
            'description_ar'      => 'nullable|string',
            'repair_steps_ar'     => 'nullable|string',
            'severity'            => 'required|in:low,medium,high,critical',
            'category'            => 'required|string',
            'avg_repair_cost_min' => 'nullable|numeric|min:0',
            'avg_repair_cost_max' => 'nullable|numeric|min:0',
            'is_active'           => 'boolean',
            'symptoms'            => 'nullable|array',
            'symptoms.*.id'       => 'exists:symptoms,id',
            'symptoms.*.cf'       => 'numeric|min:0|max:1',
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        $fault = Fault::create($data);

        // Sync symptoms with CF weights
        if (!empty($request->symptoms)) {
            $syncData = [];
            foreach ($request->symptoms as $s) {
                $syncData[$s['id']] = ['cf' => $s['cf'] ?? 0.5];
            }
            $fault->symptoms()->sync($syncData);
        }

        return redirect()->route('admin.faults.index')->with('success', 'تمت إضافة العطل بنجاح');
    }

    public function edit(Fault $fault)
    {
        $fault->load('symptoms');
        $symptoms   = Symptom::active()->orderBy('name_ar')->get();
        $severities = Fault::SEVERITIES;
        $categories = Symptom::CATEGORIES;
        return view('admin.faults.edit', compact('fault', 'symptoms', 'severities', 'categories'));
    }

    public function update(Request $request, Fault $fault)
    {
        $data = $request->validate([
            'name'                => 'required|string|max:255',
            'name_ar'             => 'required|string|max:255',
            'description_ar'      => 'nullable|string',
            'repair_steps_ar'     => 'nullable|string',
            'severity'            => 'required|in:low,medium,high,critical',
            'category'            => 'required|string',
            'avg_repair_cost_min' => 'nullable|numeric|min:0',
            'avg_repair_cost_max' => 'nullable|numeric|min:0',
            'is_active'           => 'boolean',
            'symptoms'            => 'nullable|array',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $fault->update($data);

        if ($request->has('symptoms')) {
            $syncData = [];
            foreach ((array)$request->symptoms as $s) {
                $syncData[$s['id']] = ['cf' => $s['cf'] ?? 0.5];
            }
            $fault->symptoms()->sync($syncData);
        }

        return redirect()->route('admin.faults.index')->with('success', 'تم تعديل العطل بنجاح');
    }

    public function destroy(Fault $fault)
    {
        $fault->delete();
        return redirect()->route('admin.faults.index')->with('success', 'تم حذف العطل');
    }
}