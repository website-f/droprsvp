<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    private function seedMoney(): void
    {
        $host = User::factory()->create();
        $event = Event::create(['user_id' => $host->id, 'title' => 'Gig', 'slug' => 'gig-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);
        Order::create(['reference' => 'DRSVP-T1', 'event_id' => $event->id, 'status' => 'paid', 'total' => 100, 'currency' => 'MYR', 'buyer_name' => 'Jo', 'paid_at' => now()]);
        Payout::create(['user_id' => $host->id, 'reference' => 'PO-1', 'amount' => 60, 'currency' => 'MYR', 'status' => 'paid', 'paid_at' => now()]);
    }

    public function test_finance_page_shows_kpis_and_ledger(): void
    {
        $this->seedMoney();

        $this->actingAs($this->superadmin())->get('/admin/finance')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/finance')
                ->where('kpis.ticket_sales', 100)
                ->where('kpis.payouts', 60)
                ->has('transactions.data', 2)
                ->has('trend', 30));
    }

    public function test_finance_ledger_can_be_filtered_by_type(): void
    {
        $this->seedMoney();

        $this->actingAs($this->superadmin())->get('/admin/finance?type=payout')
            ->assertInertia(fn (Assert $p) => $p->has('transactions.data', 1)
                ->where('transactions.data.0.type', 'payout'));
    }

    public function test_finance_exports_csv_and_is_superadmin_only(): void
    {
        $this->seedMoney();

        $res = $this->actingAs($this->superadmin())->get('/admin/finance/export');
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('content-type'));

        $this->actingAs(User::factory()->create())->get('/admin/finance')->assertForbidden();
    }
}
