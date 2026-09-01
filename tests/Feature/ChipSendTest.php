<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\User;
use App\Services\Payments\ChipSendGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChipSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.chip.send.key', 'send_key');
        Config::set('services.chip.send.secret', 'send_secret');
        Config::set('services.chip.send.mode', 'staging');
    }

    private function organizerWithBank(): User
    {
        $user = $this->organizer();
        $user->forceFill([
            'payout_bank_code' => 'MBBEMYKL',
            'payout_bank_account_number' => '1234567890',
            'payout_bank_account_name' => 'Acme Events',
        ])->save();

        return $user;
    }

    private function pendingPayout(User $user, float $amount = 90): Payout
    {
        return Payout::create([
            'user_id' => $user->id, 'reference' => 'PO-'.strtoupper(uniqid()),
            'amount' => $amount, 'currency' => 'MYR', 'status' => 'pending', 'requested_at' => now(),
        ]);
    }

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_requests_carry_bearer_epoch_and_a_valid_hmac_sha512_checksum(): void
    {
        Http::fake(['*/send/bank_accounts' => Http::response(['id' => 77], 201), '*/send/send_instructions' => Http::response(['id' => 5, 'state' => 'received'], 201)]);

        (new ChipSendGateway())->send($this->pendingPayout($this->organizerWithBank()));

        Http::assertSent(function ($request) {
            $epoch = $request->header('epoch')[0] ?? '';
            $checksum = $request->header('checksum')[0] ?? '';

            return $request->hasHeader('Authorization', 'Bearer send_key')
                && $epoch !== ''
                && hash_equals(hash_hmac('sha512', $epoch.'send_key', 'send_secret'), $checksum);
        });
    }

    public function test_bank_account_is_registered_once_then_reused(): void
    {
        Http::fake(['*/send/bank_accounts' => Http::response(['id' => 77], 201), '*/send/send_instructions' => Http::response(['id' => 5, 'state' => 'received'], 201)]);
        $user = $this->organizerWithBank();

        $gateway = new ChipSendGateway();
        $this->assertSame(77, $gateway->ensureBankAccount($user));
        $this->assertSame(77, (int) $user->fresh()->chip_bank_account_id);
        $gateway->ensureBankAccount($user->fresh()); // cached — no second call

        Http::assertSentCount(1); // only the bank_accounts registration
    }

    public function test_send_creates_an_instruction_with_a_decimal_amount(): void
    {
        Http::fake(['*/send/bank_accounts' => Http::response(['id' => 77], 201), '*/send/send_instructions' => Http::response(['id' => 5, 'state' => 'received'], 201)]);

        $res = (new ChipSendGateway())->send($this->pendingPayout($this->organizerWithBank(), 90.5));

        $this->assertSame(5, $res['id']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/send/send_instructions') && $r->data()['amount'] === '90.50' && $r->data()['bank_account_id'] === 77);
    }

    public function test_admin_can_pay_out_automatically_via_chip(): void
    {
        Http::fake(['*/send/bank_accounts' => Http::response(['id' => 77], 201), '*/send/send_instructions' => Http::response(['id' => 5, 'state' => 'completed'], 201)]);
        $payout = $this->pendingPayout($this->organizerWithBank());

        $this->actingAs($this->superadmin())->post(route('admin.payouts.send', $payout))->assertRedirect();

        $payout->refresh();
        $this->assertSame('paid', $payout->status);
        $this->assertSame('CHIP Send', $payout->method);
        $this->assertSame(5, (int) $payout->chip_send_id);
        $this->assertNotNull($payout->paid_at);
    }

    public function test_pending_instruction_becomes_processing_then_syncs_to_paid(): void
    {
        Http::fake(['*/send/bank_accounts' => Http::response(['id' => 77], 201), '*/send/send_instructions' => Http::response(['id' => 5, 'state' => 'received'], 201)]);
        $admin = $this->superadmin();
        $payout = $this->pendingPayout($this->organizerWithBank());

        $this->actingAs($admin)->post(route('admin.payouts.send', $payout))->assertRedirect();
        $this->assertSame('processing', $payout->fresh()->status);

        // Later CHIP reports it completed.
        Http::fake(['*/send/send_instructions/5' => Http::response(['id' => 5, 'state' => 'completed'], 200)]);
        $this->actingAs($admin)->post(route('admin.payouts.sync', $payout->fresh()))->assertRedirect();
        $this->assertSame('paid', $payout->fresh()->status);
    }

    public function test_send_is_blocked_without_bank_details(): void
    {
        $payout = $this->pendingPayout($this->organizer()); // no bank details

        $this->actingAs($this->superadmin())->post(route('admin.payouts.send', $payout))->assertSessionHasErrors('payout');
        $this->assertSame('pending', $payout->fresh()->status);
    }

    public function test_send_is_blocked_when_chip_send_not_configured(): void
    {
        Config::set('services.chip.send.key', null);
        $payout = $this->pendingPayout($this->organizerWithBank());

        $this->actingAs($this->superadmin())->post(route('admin.payouts.send', $payout))->assertSessionHasErrors('payout');
        $this->assertSame('pending', $payout->fresh()->status);
    }

    public function test_organizer_can_save_bank_details(): void
    {
        $user = $this->organizer();

        $this->actingAs($user)->post(route('host.payouts.bank'), [
            'bank_code' => 'CIBBMYKL', 'account_number' => '9988776655', 'account_name' => 'Jane Doe',
        ])->assertRedirect();

        $this->assertSame('CIBBMYKL', $user->fresh()->payout_bank_code);
        $this->assertSame('9988776655', $user->fresh()->payout_bank_account_number);
    }
}
