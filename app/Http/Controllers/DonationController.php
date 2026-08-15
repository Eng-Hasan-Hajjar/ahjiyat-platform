<?php

namespace App\Http\Controllers;

use App\Models\{Donation, Project};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DonationController extends Controller
{
    public function store(Request $request, Project $project)
    {
        $data = $request->validate([
            'type'        => 'required|in:money,materials,tools,food,other',
            'description' => 'nullable|string|max:500',
            'amount'      => 'nullable|numeric|min:0',
        ]);

        $data['project_id'] = $project->id;
        $data['donor_id']   = Auth::id();

        Donation::create($data);

        return back()->with('success', 'شكراً جزيلاً! تم تسجيل تبرعك بنجاح.');
    }
}