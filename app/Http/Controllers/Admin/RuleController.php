<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Rule;
use App\Models\Fault;
use App\Models\Symptom;
use Illuminate\Http\Request;

class RuleController extends Controller
{
    public function index()
    {
        $rules = Rule::with('fault')->orderByDesc('cf')->paginate(20);
        return view('admin.rules.index', compact('rules'));
    }

    public function create()
    {
        $faults   = Fault::active()->orderBy('name_ar')->get();
        $symptoms = Symptom::active()->orderBy('name_ar')->get();
        return view('admin.rules.create', compact('faults', 'symptoms'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                   => 'nullable|string|max:255',
            'condition_symptom_ids'  => 'required|array|min:1',
            'condition_symptom_ids.*'=> 'integer|exists:symptoms,id',
            'fault_id'               => 'required|exists:faults,id',
            'cf'                     => 'required|numeric|min:0|max:1',
            'logic'                  => 'required|in:AND,OR',
            'notes'                  => 'nullable|string',
            'is_active'              => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        Rule::create($data);
        return redirect()->route('admin.rules.index')->with('success', 'تمت إضافة القاعدة بنجاح');
    }

    public function edit(Rule $rule)
    {
        $faults   = Fault::active()->orderBy('name_ar')->get();
        $symptoms = Symptom::active()->orderBy('name_ar')->get();
        return view('admin.rules.edit', compact('rule', 'faults', 'symptoms'));
    }

    public function update(Request $request, Rule $rule)
    {
        $data = $request->validate([
            'name'                   => 'nullable|string|max:255',
            'condition_symptom_ids'  => 'required|array|min:1',
            'condition_symptom_ids.*'=> 'integer|exists:symptoms,id',
            'fault_id'               => 'required|exists:faults,id',
            'cf'                     => 'required|numeric|min:0|max:1',
            'logic'                  => 'required|in:AND,OR',
            'notes'                  => 'nullable|string',
            'is_active'              => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $rule->update($data);
        return redirect()->route('admin.rules.index')->with('success', 'تم تعديل القاعدة بنجاح');
    }

    public function destroy(Rule $rule)
    {
        $rule->delete();
        return redirect()->route('admin.rules.index')->with('success', 'تم حذف القاعدة');
    }
}