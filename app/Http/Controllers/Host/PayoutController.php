<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Services\PayoutService;
use App\Support\Banks;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayoutController extends Controller
{
    public function __construct(private readonly PayoutService $payouts) {}

    public function index(Request $request)
    {
        $user = $request->user();

        return inertia('host/payouts', [
            'balance' => $this->payouts->balanceFor($user),
            'bank' => [
                'bank_code' => $user->payout_bank_code,
                'account_number' => $user->payout_bank_account_number,
                'account_name' => $user->payout_bank_account_name,
            ],
            'banks' => Banks::options(),
            'payouts' => Payout::where('user_id', $user->id)->latest()->get()->map(fn (Payout $p) => [
                'reference' => $p->reference,
                'amount' => (float) $p->amount,
                'currency' => $p->currency,
                'status' => $p->status,
                'requested_at' => optional($p->requested_at)->format('j M Y'),
                'paid_at' => optional($p->paid_at)->format('j M Y'),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $this->payouts->request($request->user());

        return back()->with('flash_success', 'Payout requested — we’ll process it shortly.');
    }

    /** Save the organizer's bank account for automated payouts. */
    public function bank(Request $request)
    {
        $data = $request->validate([
            'bank_code' => ['required', Rule::in(array_keys(Banks::MALAYSIA))],
            'account_number' => ['required', 'string', 'max:34', 'regex:/^[0-9]{6,20}$/'],
            'account_name' => ['required', 'string', 'max:120'],
        ], [], ['account_number' => 'account number']);

        $user = $request->user();
        // Changing the account invalidates the CHIP-registered bank account id.
        $changed = $user->payout_bank_code !== $data['bank_code'] || $user->payout_bank_account_number !== $data['account_number'];

        $user->forceFill([
            'payout_bank_code' => $data['bank_code'],
            'payout_bank_account_number' => $data['account_number'],
            'payout_bank_account_name' => $data['account_name'],
            'chip_bank_account_id' => $changed ? null : $user->chip_bank_account_id,
        ])->save();

        return back()->with('flash_success', 'Bank details saved.');
    }
}
