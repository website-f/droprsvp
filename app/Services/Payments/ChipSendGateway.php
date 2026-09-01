<?php

namespace App\Services\Payments;

use App\Models\Payout;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * CHIP Send — automated bank payouts to organizers (https://docs.chip-in.asia,
 * "CHIP Send"). Separate product/keys from CHIP Collect, with its own auth:
 * Authorization: Bearer <key> + `epoch` (unix seconds) + `checksum`
 * (HMAC-SHA512 hex of "<epoch><key>" keyed by the API secret).
 *
 * Flow: register the organizer's bank account once → create a send instruction
 * (payout). A state of "completed" means the money reached the recipient.
 */
class ChipSendGateway
{
    /** Terminal success/failure states of a send instruction. */
    public const DONE = 'completed';
    public const FAILED = ['rejected', 'deleted'];

    public function configured(): bool
    {
        return (bool) config('services.chip.send.key') && (bool) config('services.chip.send.secret');
    }

    private function base(): string
    {
        return config('services.chip.send.mode') === 'live'
            ? 'https://api.chip-in.asia/api'
            : 'https://staging-api.chip-in.asia/api';
    }

    private function api(): PendingRequest
    {
        $key = (string) config('services.chip.send.key');
        $epoch = (string) time();
        $checksum = hash_hmac('sha512', $epoch.$key, (string) config('services.chip.send.secret'));

        return Http::withToken($key)
            ->withHeaders(['epoch' => $epoch, 'checksum' => $checksum])
            ->acceptJson()
            ->baseUrl($this->base());
    }

    /** Register (or reuse) the organizer's bank account at CHIP; returns its id. */
    public function ensureBankAccount(User $organizer): int
    {
        if ($organizer->chip_bank_account_id) {
            return (int) $organizer->chip_bank_account_id;
        }

        $res = $this->api()->post('/send/bank_accounts', [
            'bank_code' => $organizer->payout_bank_code,
            'account_number' => $organizer->payout_bank_account_number,
            'name' => $organizer->payout_bank_account_name,
            'reference' => 'user-'.$organizer->id,
        ])->throw()->json();

        $organizer->forceFill(['chip_bank_account_id' => $res['id']])->save();

        return (int) $res['id'];
    }

    /**
     * Create a payout send instruction for a payout request.
     *
     * @return array{id:int,state:string}
     */
    public function send(Payout $payout): array
    {
        $bankAccountId = $this->ensureBankAccount($payout->user);

        $res = $this->api()->post('/send/send_instructions', [
            'bank_account_id' => $bankAccountId,
            'amount' => number_format((float) $payout->amount, 2, '.', ''), // decimal string, not cents
            'email' => $payout->user->email,
            'description' => 'DropRSVP payout '.$payout->reference,
            'reference' => $payout->reference,
        ])->throw()->json();

        return ['id' => (int) $res['id'], 'state' => (string) ($res['state'] ?? 'received')];
    }

    /** Current state of a send instruction, or null if it can't be fetched. */
    public function state(int $sendId): ?string
    {
        $res = $this->api()->get('/send/send_instructions/'.$sendId);

        return $res->successful() ? (string) $res->json('state') : null;
    }
}
