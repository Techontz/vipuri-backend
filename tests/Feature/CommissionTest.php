<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Constants\Status;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GeneralSetting;
use App\Models\Order;
use App\Models\OrderCommission;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Worker commission.
 *
 * The rules under test are the ones that decide who gets paid what, so each is
 * pinned rather than described: nothing accrues until the business sets a rate,
 * the amount is worked out from the goods rather than the delivery charge, the
 * earner comes from the append-only status log rather than `processed_by`, and
 * an order that comes back withdraws the entitlement without erasing the
 * record of it.
 */
class CommissionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->branch = Branch::where('code', 'DSM-01')->firstOrFail();
    }

    private function scheme(array $values): void
    {
        GeneralSetting::query()->update($values);
        Cache::forget('GeneralSetting');

        // `GeneralSetting::current()` memoises for the request; tests make
        // several "requests" in one process.
        (function () {
            static::$instance = null;
        })->call(new GeneralSetting);
    }

    private function makeOrder(array $attributes = []): Order
    {
        $product = $this->makeProduct($this->branch, 50);

        $order = Order::create(array_merge([
            'company_id' => Company::current()->id,
            'branch_id' => $this->branch->id,
            'order_number' => 'VP' . strtoupper(uniqid()),
            'user_id' => 0,
            'guest_id' => 0,
            'status' => Status::ORDER_PENDING,
            'payment_status' => Status::PAYMENT_SUCCESS,
            'subtotal' => 200000,
            'shipping_charge' => 15000,
            'total_tax' => 36000,
            'discount' => 0,
            'total' => 251000,
            'shipping_address' => ['city' => 'Dar es Salaam'],
        ], $attributes));

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variation_id' => 0,
            'quantity' => 2,
            'price' => 100000,
            'subtotal' => 200000,
        ]);

        return $order;
    }

    /** Walk an order to delivered as the given staff member. */
    private function deliver(Order $order, $actor, array $via = [Status::ORDER_PROCESSING, Status::ORDER_DISPATCHED, Status::ORDER_DELIVERED]): void
    {
        foreach ($via as $status) {
            $this->withHeaders($this->adminHeaders($actor))
                ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => $status])
                ->assertOk();
        }
    }

    // ------------------------------------------------------------- off by default

    public function test_nothing_accrues_until_the_business_sets_a_rate(): void
    {
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);
        $order = $this->makeOrder();

        $this->deliver($order, $worker);

        $this->assertSame(0, OrderCommission::count(), 'a commission was recorded with no scheme configured');
    }

    public function test_enabling_the_scheme_with_a_zero_rate_still_records_nothing(): void
    {
        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 0]);

        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);
        $this->deliver($this->makeOrder(), $worker);

        $this->assertSame(0, OrderCommission::count());
    }

    // ------------------------------------------------------------- the calculation

    public function test_a_delivered_order_records_a_commission_on_the_goods_only(): void
    {
        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 2.5]);

        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);
        $order = $this->makeOrder();

        $this->deliver($order, $worker);

        $commission = OrderCommission::where('order_id', $order->id)->firstOrFail();

        // 200,000 subtotal — not the 251,000 total, which carries delivery and
        // tax that are not the shop's to share out.
        $this->assertSame(200000.0, $commission->basis_amount);
        $this->assertSame(5000.0, $commission->amount);
        $this->assertSame(2.5, $commission->rate);
        $this->assertSame($worker->id, $commission->admin_id);
        $this->assertSame(Status::COMMISSION_PENDING, (int) $commission->status);
        $this->assertNotNull($commission->earned_at);
    }

    public function test_a_discount_reduces_the_basis(): void
    {
        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 10]);

        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);
        $order = $this->makeOrder(['discount' => 50000]);

        $this->deliver($order, $worker);

        $commission = OrderCommission::where('order_id', $order->id)->firstOrFail();

        $this->assertSame(150000.0, $commission->basis_amount);
        $this->assertSame(15000.0, $commission->amount);
    }

    /** The rate is stored, so changing it later cannot restate history. */
    public function test_changing_the_rate_does_not_restate_what_was_already_earned(): void
    {
        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 2]);

        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);
        $order = $this->makeOrder();
        $this->deliver($order, $worker);

        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 20]);

        $commission = OrderCommission::where('order_id', $order->id)->firstOrFail();

        $this->assertSame(2.0, $commission->rate);
        $this->assertSame(4000.0, $commission->amount);
    }

    /**
     * Delivered is a final status, so the API will not deliver an order twice.
     * The guard still matters — a retried request or a replayed job must not
     * pay a second time — so the service is exercised directly.
     */
    public function test_an_order_earns_a_commission_only_once(): void
    {
        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 5]);

        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branch);
        $order = $this->makeOrder();

        $this->deliver($order, $manager);

        $service = app(\App\Services\CommissionService::class);
        $first = OrderCommission::where('order_id', $order->id)->firstOrFail();
        $again = $service->recordForDelivery($order->fresh());

        $this->assertSame(1, OrderCommission::where('order_id', $order->id)->count());
        $this->assertSame($first->id, $again?->id);
    }

    // ------------------------------------------------------------ who earns it

    public function test_the_earner_comes_from_the_status_log_not_from_processed_by(): void
    {
        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 5, 'commission_attribution' => 'delivered_by']);

        $picker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch, ['name' => 'Picker']);
        $driver = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch, ['name' => 'Driver']);

        $order = $this->makeOrder();

        $this->deliver($order, $picker, [Status::ORDER_PROCESSING, Status::ORDER_DISPATCHED]);
        $this->deliver($order, $driver, [Status::ORDER_DELIVERED]);

        $commission = OrderCommission::where('order_id', $order->id)->firstOrFail();

        $this->assertSame($driver->id, $commission->admin_id, 'delivered_by must credit whoever completed the order');
        $this->assertSame($driver->id, (int) $order->fresh()->processed_by, 'sanity: processed_by is the last actor');
    }

    public function test_the_processed_by_rule_credits_whoever_first_took_the_order_on(): void
    {
        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 5, 'commission_attribution' => 'processed_by']);

        $picker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch, ['name' => 'Picker']);
        $driver = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch, ['name' => 'Driver']);

        $order = $this->makeOrder();

        $this->deliver($order, $picker, [Status::ORDER_PROCESSING, Status::ORDER_DISPATCHED]);
        $this->deliver($order, $driver, [Status::ORDER_DELIVERED]);

        $this->assertSame(
            $picker->id,
            OrderCommission::where('order_id', $order->id)->firstOrFail()->admin_id,
            'processed_by must credit the staff member who started fulfilment',
        );
    }

    // --------------------------------------------------------------- reversal

    public function test_a_returned_order_reverses_the_commission_without_deleting_it(): void
    {
        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 5]);

        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branch);
        $order = $this->makeOrder();

        $this->deliver($order, $manager);

        $this->withHeaders($this->adminHeaders($manager))
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_RETURNED])
            ->assertOk();

        $commission = OrderCommission::where('order_id', $order->id)->firstOrFail();

        $this->assertSame(Status::COMMISSION_REVERSED, (int) $commission->status);
        $this->assertSame(10000.0, $commission->amount, 'the amount stays on the record for audit');
        $this->assertStringContainsString('returned', (string) $commission->note);
    }

    public function test_a_commission_already_paid_is_flagged_rather_than_silently_clawed_back(): void
    {
        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 5]);

        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branch);
        $order = $this->makeOrder();
        $this->deliver($order, $manager);

        OrderCommission::where('order_id', $order->id)->update([
            'status' => Status::COMMISSION_PAID,
            'paid_at' => now(),
        ]);

        $this->withHeaders($this->adminHeaders($manager))
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_RETURNED])
            ->assertOk();

        $commission = OrderCommission::where('order_id', $order->id)->firstOrFail();

        $this->assertSame(Status::COMMISSION_PAID, (int) $commission->status);
        $this->assertStringContainsString('payroll', (string) $commission->note);
    }

    // ------------------------------------------------------------- the API

    public function test_a_worker_sees_only_their_own_commission(): void
    {
        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 5]);

        $mine = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch, ['name' => 'Mine']);
        $theirs = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch, ['name' => 'Theirs']);

        $this->deliver($this->makeOrder(), $mine);
        $this->deliver($this->makeOrder(), $theirs);

        $response = $this->withHeaders($this->adminHeaders($mine))
            ->getJson('/api/v1/admin/commissions')
            ->assertOk();

        $names = collect($response->json('data.commissions'))->pluck('staff')->unique()->values()->all();

        $this->assertSame(['Mine'], $names);
        $this->assertFalse($response->json('data.sees_everyone'));
        $this->assertSame([], $response->json('data.by_staff'));
    }

    public function test_a_manager_sees_the_branch_and_a_breakdown_by_staff(): void
    {
        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 5]);

        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branch);
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch, ['name' => 'Worker One']);

        $this->deliver($this->makeOrder(), $worker);

        $response = $this->withHeaders($this->adminHeaders($manager))
            ->getJson('/api/v1/admin/commissions')
            ->assertOk();

        $this->assertTrue($response->json('data.sees_everyone'));
        $this->assertSame('Worker One', $response->json('data.by_staff.0.staff'));
        $this->assertEqualsWithDelta(10000, $response->json('data.totals.outstanding'), 0.001);
        $this->assertEqualsWithDelta(10000, $response->json('data.by_staff.0.outstanding'), 0.001);
    }

    public function test_the_statement_reports_the_scheme_it_was_calculated_under(): void
    {
        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 3.5]);

        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branch);

        $this->withHeaders($this->adminHeaders($manager))
            ->getJson('/api/v1/admin/commissions')
            ->assertOk()
            ->assertJsonPath('data.scheme.enabled', true)
            ->assertJsonPath('data.scheme.rate', 3.5)
            ->assertJsonPath('data.scheme.attribution', 'delivered_by');
    }

    public function test_an_empty_statement_reports_zero_rather_than_nothing(): void
    {
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);

        $this->withHeaders($this->adminHeaders($worker))
            ->getJson('/api/v1/admin/commissions')
            ->assertOk()
            ->assertJsonPath('data.totals.outstanding', 0)
            ->assertJsonPath('data.totals.orders', 0)
            ->assertJsonPath('data.commissions', []);
    }

    // ------------------------------------------------------- approve and pay

    public function test_a_super_admin_can_approve_and_then_pay_a_commission(): void
    {
        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 5]);

        $manager = $this->makeStaff(Roles::SUPER_ADMIN);
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);

        $this->deliver($this->makeOrder(), $worker);
        $commission = OrderCommission::firstOrFail();

        $this->withHeaders($this->adminHeaders($manager))
            ->postJson("/api/v1/admin/commissions/{$commission->id}/status", ['status' => Status::COMMISSION_APPROVED])
            ->assertOk();

        $this->assertSame(Status::COMMISSION_APPROVED, (int) $commission->fresh()->status);

        $this->withHeaders($this->adminHeaders($manager))
            ->postJson("/api/v1/admin/commissions/{$commission->id}/status", [
                'status' => Status::COMMISSION_PAID,
                'payout_reference' => 'PAYROLL-2026-08',
            ])
            ->assertOk();

        $fresh = $commission->fresh();

        $this->assertSame(Status::COMMISSION_PAID, (int) $fresh->status);
        $this->assertSame('PAYROLL-2026-08', $fresh->payout_reference);
        $this->assertNotNull($fresh->paid_at);
    }

    public function test_a_worker_cannot_approve_their_own_commission(): void
    {
        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 5]);

        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);
        $this->deliver($this->makeOrder(), $worker);

        $commission = OrderCommission::firstOrFail();

        $this->withHeaders($this->adminHeaders($worker))
            ->postJson("/api/v1/admin/commissions/{$commission->id}/status", ['status' => Status::COMMISSION_APPROVED])
            ->assertStatus(403);

        $this->assertSame(Status::COMMISSION_PENDING, (int) $commission->fresh()->status);
    }

    public function test_a_reversed_commission_cannot_be_paid(): void
    {
        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 5]);

        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branch);
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);

        $order = $this->makeOrder();
        $this->deliver($order, $worker);

        $this->withHeaders($this->adminHeaders($manager))
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_RETURNED])
            ->assertOk();

        $commission = OrderCommission::firstOrFail();

        $this->withHeaders($this->adminHeaders($admin))
            ->postJson("/api/v1/admin/commissions/{$commission->id}/status", ['status' => Status::COMMISSION_PAID])
            ->assertStatus(422);

        $this->assertSame(Status::COMMISSION_REVERSED, (int) $commission->fresh()->status);
    }

    /**
     * Paying out is a head-office action. A branch manager can see every
     * commission in their branch but cannot approve or pay one by default —
     * signing off your own branch's payouts is not a control anyone wants.
     */
    public function test_a_branch_manager_cannot_approve_a_payout_by_default(): void
    {
        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 5]);

        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branch);
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);

        $this->deliver($this->makeOrder(), $worker);
        $commission = OrderCommission::firstOrFail();

        $this->withHeaders($this->adminHeaders($manager))
            ->postJson("/api/v1/admin/commissions/{$commission->id}/status", ['status' => Status::COMMISSION_APPROVED])
            ->assertStatus(403);

        $this->assertSame(Status::COMMISSION_PENDING, (int) $commission->fresh()->status);
    }

    public function test_a_manager_only_sees_their_own_branchs_commissions(): void
    {
        $this->scheme(['commission_enabled' => 1, 'commission_rate' => 5]);

        $other = Branch::where('code', 'ARU-01')->firstOrFail();

        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);
        $this->deliver($this->makeOrder(), $worker);

        $outsider = $this->makeStaff(Roles::BRANCH_MANAGER, $other);

        $this->assertSame(
            [],
            $this->withHeaders($this->adminHeaders($outsider))
                ->getJson('/api/v1/admin/commissions')->assertOk()->json('data.commissions'),
            'a manager saw another branch\'s commissions',
        );
        $commission = OrderCommission::firstOrFail();

        $this->withHeaders($this->adminHeaders($outsider))
            ->postJson("/api/v1/admin/commissions/{$commission->id}/status", ['status' => Status::COMMISSION_APPROVED])
            ->assertStatus(403);
    }

    public function test_the_settings_endpoint_accepts_and_stores_a_rate(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->withHeaders($this->adminHeaders($admin))->postJson('/api/v1/admin/settings/general', [
            'site_name' => 'VIPURI',
            'cur_text' => 'TZS',
            'cur_sym' => 'TSh',
            'commission_enabled' => 1,
            'commission_rate' => 4.25,
            'commission_attribution' => 'processed_by',
        ])->assertOk();

        $settings = GeneralSetting::query()->firstOrFail();

        $this->assertTrue((bool) $settings->commission_enabled);
        $this->assertSame(4.25, (float) $settings->commission_rate);
        $this->assertSame('processed_by', $settings->commission_attribution);
    }

    public function test_a_rate_above_one_hundred_percent_is_rejected(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->withHeaders($this->adminHeaders($admin))->postJson('/api/v1/admin/settings/general', [
            'site_name' => 'VIPURI',
            'cur_text' => 'TZS',
            'cur_sym' => 'TSh',
            'commission_rate' => 150,
        ])->assertStatus(422);
    }
}
