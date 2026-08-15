<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function show(Request $request)
    {
        return $request->user()->wallet;
    }

    public function transactions(Request $request)
    {
        return $request->user()->gemTransactions()->latest()->paginate(20);
    }
}
