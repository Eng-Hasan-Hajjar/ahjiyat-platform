<?php

namespace App\Http\Controllers;

use App\Models\CurrencyPack;

class StoreController extends Controller
{
    public function index()
    {
        $packs = CurrencyPack::with('currency')
            ->whereHas('currency', fn ($q) => $q->where('is_active', true))
            ->get()
            ->filter(fn (CurrencyPack $pack) => $pack->isCurrentlyAvailable())
            ->sortBy('sort_order');

        return view('store.index', compact('packs'));
    }
}