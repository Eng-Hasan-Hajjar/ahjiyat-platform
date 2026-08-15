<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

class WalletController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $wallet = $user->wallet;

        $transactions = $user->gemTransactions()->latest()->paginate(20);

        return view('wallet.index', compact('wallet', 'transactions'));
    }
}
