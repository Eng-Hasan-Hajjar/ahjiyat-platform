<?php

namespace App\Http\Controllers;

use App\Support\WalletMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * «محفظتي» (قراءة فقط). E46: فلتر بسيط بالعملة ونوع الحركة عبر GET.
 *
 * - لا يكتب شيئًا ولا يحرّر جواهر ولا يمسّ شروط الاستبدال: عرض واستعلامات قراءة فقط.
 * - كل الحركات من علاقة المستخدم الحالي نفسه (user_id)، فلا يرى اللاعب حركات غيره مهما كان الفلتر.
 * - قيمة currency تُقبل فقط إن كانت عملة من محافظ اللاعب نفسه، وkind فقط من القائمة البيضاء؛ أي قيمة أخرى تُتجاهل بصمت (يُعرض الكل).
 * - الترقيم 20 في الصفحة ثابت (لا يتحكم به الطلب) مع withQueryString كي يبقى الفلتر عبر الصفحات.
 */
class WalletController extends Controller
{
    public const PER_PAGE = 20;

    public function index(Request $request)
    {
        $user = Auth::user();
        $wallets = $user->wallets()->with('currency')->get();

        $ownCurrencyIds = $wallets->pluck('currency_id')->map(fn ($id) => (int) $id)->all();
        $currencyId = $request->query('currency');
        $currencyId = is_scalar($currencyId) && ctype_digit((string) $currencyId) && in_array((int) $currencyId, $ownCurrencyIds, true)
            ? (int) $currencyId
            : null;

        $kind = $request->query('kind');
        $kind = is_string($kind) && WalletMovement::isKind($kind) ? $kind : null;

        $query = $user->currencyTransactions()
            ->with('currency:id,name,code')
            ->when($currencyId !== null, fn ($q) => $q->where('currency_id', $currencyId))
            ->when($kind !== null, fn ($q) => WalletMovement::scopeKind($q, $kind))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $transactions = $query->paginate(self::PER_PAGE)->withQueryString();

        $filters = ['currency' => $currencyId, 'kind' => $kind];
        $hasFilters = $currencyId !== null || $kind !== null;
        $kinds = WalletMovement::kinds();

        return view('wallet.index', compact('wallets', 'transactions', 'filters', 'hasFilters', 'kinds'));
    }
}
