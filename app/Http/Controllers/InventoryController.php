<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

class InventoryController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $inventoryItems = $user->inventoryItems()->with('item')->where('quantity', '>', 0)->get();
        $entitlements = $user->entitlements()->with('item')->orderByDesc('created_at')->get();
        $purchases = $user->storePurchases()->with('item')->latest()->paginate(15);

        return view('inventory.index', compact('inventoryItems', 'entitlements', 'purchases'));
    }
}