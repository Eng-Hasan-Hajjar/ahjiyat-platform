<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

class WalletController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $wallets = $user->wallets()->with('currency')->get();

        $transactions = $user->currencyTransactions()->with('currency:id,name,code')->latest()->paginate(20);

        return view('wallet.index', compact('wallets', 'transactions'));
    }
}