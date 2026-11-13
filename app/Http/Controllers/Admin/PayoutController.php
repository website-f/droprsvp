<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Models\User;
use App\Services\Payments\ChipSendGateway;
use App\Services\PayoutService;
use App\Support\Banks;
use App\Support\Dates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PayoutController extends Controller
{
    public function __construct(private readonly PayoutService $payouts) {}

    /** Superadmin — all payout requests across organizers (pending first). */
    public function index(Request $request, ChipSendGateway $send)
    {
        return inertia('admin/payouts/index', [
            'sendEnabled' => $send->configured(),
            // Opened from an organizer's money statement: start the "Record a
            // payout" form on them.
            'prefill' => $request->filled('organizer') ? $this->organizerOption(User::find((int) $request->query('organizer'))) : null,
            'payouts' => Payout::with(['user:id,name,email,payout_bank_code,payout_bank_account_number,payout_bank_account_name', 'creator:id,name'])
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
                    'requested_at' => Dates::display($p->requested_at, 'j M Y'),
                    'paid_at' => Dates::display($p->paid_at, 'j M Y'),
                    'note' => $p->note,
                    'proof' => $p->proof,
                    // Raised by an admin rather than requested by the organizer.
                    'created_by' => $p->creator?->name,
                ]),
        ]);
    }

    /** Organizers matching a search, with what can be paid to them — for "Record a payout". */
    public function organizers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $organizers = User::query()
            ->where(fn ($w) => $w->whereHas('organizerProfile', fn ($p) => $p->where('status', 'approved'))->orWhereHas('events'))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->orWhereHas('organizerProfile', fn ($p) => $p->where('business_name', 'like', "%{$q}%"))))
            ->with('organizerProfile:id,user_id,business_name')
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json($organizers->map(fn (User $u) => $this->organizerOption($u))->values());
    }

    /** Record a payout for an organizer — optionally already paid, with proof. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'paid' => ['boolean'],
            'method' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
            'proof' => ['nullable', 'string', 'max:2048'],
        ], ['user_id.required' => 'Choose the organizer to pay.']);

        $payout = $this->payouts->recordFor(
            User::findOrFail($data['user_id']),
            (float) $data['amount'],
            $request->user(),
            (bool) ($data['paid'] ?? false),
            $data['method'] ?? null,
            $data['note'] ?? null,
            $data['proof'] ?? null,
        );

        return back()->with('flash_success', $payout->status === 'paid'
            ? "Recorded {$payout->reference} as paid — the organizer has been emailed."
            : "Created {$payout->reference}. It is waiting to be paid.");
    }

    /** @return array<string, mixed>|null */
    private function organizerOption(?User $u): ?array
    {
        if (! $u) {
            return null;
        }

        $balance = $this->payouts->balanceFor($u);

        return [
            'id' => $u->id,
            'name' => $u->organizerProfile?->business_name ?: $u->name,
            'email' => $u->email,
            'available' => $balance['available'],
            'clearing' => $balance['pending_clearance'],
            'outstanding' => $this->payouts->outstanding($u),
            'bank' => $u->payout_bank_code ? [
                'name' => Banks::name($u->payout_bank_code),
                'account' => $u->payout_bank_account_number,
                'holder' => $u->payout_bank_account_name,
            ] : null,
        ];
    }

    /** Manual — record that the payout was paid outside the system. */
    public function markPaid(Request $request, Payout $payout)
    {
        $data = $request->validate([
            'method' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
            // The transfer slip / screenshot, uploaded through /uploads.
            'proof' => ['nullable', 'string', 'max:2048'],
        ]);
        $this->payouts->markPaid($payout, $data['method'] ?? 'Manual', $data['note'] ?? null, $data['proof'] ?? null);

        return back()->with('flash_success', "Marked {$payout->reference} paid.");
    }

    /** Automated — pay the organizer via CHIP Send. */
    public function send(Payout $payout, ChipSendGateway $send)
    {
        // Real money + a live API call: a misconfiguration, missing bank details, or
        // a CHIP outage must never 500 mid-transfer — always come back with a clear,
        // actionable message (toasted) so the admin can retry or pay out manually.
        try {
            $this->payouts->sendViaChip($payout, $send);
        } catch (ValidationException $e) {
            throw $e; // config / bank-details problems surface as inline field errors
        } catch (\Throwable $e) {
            report($e);

            return back()->with('flash_error', 'CHIP Send couldn’t process this payout right now — try again, refresh its status, or pay it out manually.');
        }

        return back()->with('flash_success', $payout->fresh()->status === 'paid'
            ? "{$payout->reference} paid via CHIP Send."
            : "{$payout->reference} sent via CHIP — awaiting confirmation.");
    }

    /** Re-check an in-flight CHIP Send payout's status. */
    public function sync(Payout $payout, ChipSendGateway $send)
    {
        try {
            $this->payouts->syncChipStatus($payout, $send);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('flash_error', 'Couldn’t reach CHIP to refresh this payout’s status. Please try again shortly.');
        }

        return back()->with('flash_success', "Refreshed {$payout->reference}: {$payout->fresh()->status}.");
    }
}
