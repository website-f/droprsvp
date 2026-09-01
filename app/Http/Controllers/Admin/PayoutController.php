<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Services\Payments\ChipSendGateway;
use App\Services\PayoutService;
use App\Support\Banks;
use Illuminate\Http\Request;

class PayoutController extends Controller
{
    public function __construct(private readonly PayoutService $payouts) {}

    /** Superadmin — all payout requests across organizers (pending first). */
    public function index(ChipSendGateway $send)
    {
        return inertia('admin/payouts/index', [
            'sendEnabled' => $send->configured(),
            'payouts' => Payout::with('user:id,name,email,payout_bank_code,payout_bank_account_number,payout_bank_account_name')
                ->orderByRaw("case status when 'pending' then 0 when 'processing' then 1 else 2 end")
                ->latest()
                ->get()
                ->map(fn (Payout $p) => [
                    'reference' => $p->reference,
                    'organizer' => $p->user?->name,
                    'email' => $p->user?->email,
                    'amount' => (float) $p->amount,
                    'currency' => $p->currency,
                    'status' => $p->status,
                    'method' => $p->method,
                    'chip_state' => $p->chip_send_state,
                    'bank' => $p->user?->payout_bank_code ? [
                        'name' => Banks::name($p->user->payout_bank_code),
                        'account' => $p->user->payout_bank_account_number,
                        'holder' => $p->user->payout_bank_account_name,
                    ] : null,
                    'requested_at' => optional($p->requested_at)->format('j M Y'),
                    'paid_at' => optional($p->paid_at)->format('j M Y'),
                ]),
        ]);
    }

    /** Manual — record that the payout was paid outside the system. */
    public function markPaid(Request $request, Payout $payout)
    {
        $data = $request->validate(['method' => ['nullable', 'string', 'max:60'], 'note' => ['nullable', 'string', 'max:255']]);
        $this->payouts->markPaid($payout, $data['method'] ?? 'Manual', $data['note'] ?? null);

        return back()->with('flash_success', "Marked {$payout->reference} paid.");
    }

    /** Automated — pay the organizer via CHIP Send. */
    public function send(Payout $payout, ChipSendGateway $send)
    {
        $this->payouts->sendViaChip($payout, $send);

        return back()->with('flash_success', $payout->fresh()->status === 'paid'
            ? "{$payout->reference} paid via CHIP Send."
            : "{$payout->reference} sent via CHIP — awaiting confirmation.");
    }

    /** Re-check an in-flight CHIP Send payout's status. */
    public function sync(Payout $payout, ChipSendGateway $send)
    {
        $this->payouts->syncChipStatus($payout, $send);

        return back()->with('flash_success', "Refreshed {$payout->reference}: {$payout->fresh()->status}.");
    }
}
