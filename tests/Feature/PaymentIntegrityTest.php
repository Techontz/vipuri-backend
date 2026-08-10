<?php

namespace Tests\Feature;

use App\Constants\Status;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Deposit;
use App\Models\Gateway;
use App\Models\Order;
use App\Services\Payment\Drivers\FlutterwaveDriver;
use App\Services\Payment\Drivers\StripeDriver;
use App\Services\Payment\PaymentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A gateway confirming *a* payment is not the same as it confirming *this*
 * payment. These cover the two ways that distinction can be lost: the callback
 * naming a different transaction than the one the provider verified, and the
 * amount captured falling short of the amount due.
 */
class PaymentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->seedGateways();
        $this->branch = Branch::where('code', 'DSM-01')->firstOrFail();
        Http::preventStrayRequests();
    }

    private function makeOrder(float $total = 400000): Order
    {
        return Order::create([
            'company_id' => Company::current()->id,
            'branch_id' => $this->branch->id,
            'order_number' => 'VP-PAY-' . strtoupper(uniqid()),
            'user_id' => 0,
            'guest_id' => 0,
            'status' => Status::ORDER_PENDING,
            'payment_status' => Status::PAYMENT_INITIATE,
            'subtotal' => $total,
            'total' => $total,
            'shipping_address' => ['name' => 'Asha Mwinyi'],
        ]);
    }

    private function makeDeposit(Order $order, string $trx, float $finalAmount): Deposit
    {
        return Deposit::create([
            'user_id' => 0,
            'guest_id' => 0,
            'order_id' => $order->id,
            'gateway_id' => Gateway::where('alias', 'mpesa-manual')->value('id'),
            'method_code' => 0,
            'method_currency' => 'TZS',
            'amount' => $finalAmount,
            'charge' => 0,
            'rate' => 1,
            'final_amount' => $finalAmount,
            'trx' => $trx,
            'status' => Status::PAYMENT_INITIATE,
        ]);
    }

    /* ------------------------------------------------------------------ *
     | Which transaction was verified
     * ------------------------------------------------------------------ */

    public function test_stripe_reports_the_reference_its_own_session_carries_not_the_callback_body(): void
    {
        config(['services.stripe.secret' => 'sk_test_dummy']);

        Http::fake([
            'api.stripe.com/*' => Http::response([
                'id' => 'cs_attacker_session',
                'payment_status' => 'paid',
                'amount_total' => 1000,
                'client_reference_id' => 'ATTACKER-TRX',
                'metadata' => ['trx' => 'ATTACKER-TRX'],
            ]),
        ]);

        // The webhook body names the victim's transaction; Stripe names its own.
        $result = app(StripeDriver::class)->verify([
            'data' => ['object' => [
                'id' => 'cs_attacker_session',
                'client_reference_id' => 'VICTIM-TRX',
                'metadata' => ['trx' => 'VICTIM-TRX'],
            ]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('ATTACKER-TRX', $result['trx'], 'The trx must come from the verified session');
    }

    public function test_flutterwave_reports_the_reference_its_own_verification_carries(): void
    {
        config(['services.flutterwave.secret' => 'FLWSECK_TEST']);

        Http::fake([
            'api.flutterwave.com/*' => Http::response([
                'data' => [
                    'id' => 99,
                    'status' => 'successful',
                    'tx_ref' => 'ATTACKER-TRX',
                    'amount' => 1000,
                ],
            ]),
        ]);

        $result = app(FlutterwaveDriver::class)->verify([
            'transaction_id' => 99,
            'tx_ref' => 'VICTIM-TRX',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('ATTACKER-TRX', $result['trx']);
    }

    public function test_the_return_url_cannot_settle_a_deposit_the_provider_did_not_name(): void
    {
        config(['services.flutterwave.secret' => 'FLWSECK_TEST']);

        $victimOrder = $this->makeOrder(400000);
        $victim = $this->makeDeposit($victimOrder, 'VICTIM-TRX', 400000);

        $gateway = Gateway::firstWhere('alias', 'Flutterwave')
            ?? Gateway::create([
                'name' => 'Flutterwave',
                'alias' => 'Flutterwave',
                'code' => 900,
                'status' => 1,
                'gateway_parameters' => [],
            ]);

        $victim->update(['gateway_id' => $gateway->id]);

        // The provider confirms the attacker's own small, real transaction.
        Http::fake([
            'api.flutterwave.com/*' => Http::response([
                'data' => [
                    'id' => 42,
                    'status' => 'successful',
                    'tx_ref' => 'ATTACKER-TRX',
                    'amount' => 1000,
                ],
            ]),
        ]);

        $this->getJson('/api/v1/payment/confirm/VICTIM-TRX?transaction_id=42')
            ->assertOk()
            ->assertJsonPath('data.paid', false);

        $this->assertSame(Status::PAYMENT_INITIATE, (int) $victim->fresh()->status);
        $this->assertSame(Status::PAYMENT_INITIATE, (int) $victimOrder->fresh()->payment_status);
    }

    /* ------------------------------------------------------------------ *
     | How much was actually captured
     * ------------------------------------------------------------------ */

    public function test_a_short_payment_does_not_settle_the_order(): void
    {
        $order = $this->makeOrder(400000);
        $deposit = $this->makeDeposit($order, 'SHORT-TRX', 400000);

        app(PaymentManager::class)->markSuccess($deposit, 1000.0);

        $this->assertSame(Status::PAYMENT_REJECT, (int) $deposit->fresh()->status);
        $this->assertSame(Status::PAYMENT_REJECT, (int) $order->fresh()->payment_status);
        $this->assertSame(Status::ORDER_PENDING, (int) $order->fresh()->status);
    }

    public function test_the_full_amount_settles_the_order(): void
    {
        $order = $this->makeOrder(400000);
        $deposit = $this->makeDeposit($order, 'FULL-TRX', 400000);

        app(PaymentManager::class)->markSuccess($deposit, 400000.0);

        $this->assertSame(Status::PAYMENT_SUCCESS, (int) $deposit->fresh()->status);
        $this->assertSame(Status::PAYMENT_SUCCESS, (int) $order->fresh()->payment_status);
        $this->assertSame(Status::ORDER_PAID, (int) $order->fresh()->status);
    }

    public function test_a_driver_that_cannot_report_an_amount_still_settles(): void
    {
        $order = $this->makeOrder(400000);
        $deposit = $this->makeDeposit($order, 'NOAMOUNT-TRX', 400000);

        app(PaymentManager::class)->markSuccess($deposit, null);

        $this->assertSame(Status::PAYMENT_SUCCESS, (int) $deposit->fresh()->status);
    }

    public function test_settling_twice_does_not_double_apply(): void
    {
        $order = $this->makeOrder(400000);
        $deposit = $this->makeDeposit($order, 'IDEMPOTENT-TRX', 400000);

        $payments = app(PaymentManager::class);
        $payments->markSuccess($deposit, 400000.0);
        // A retried webhook must not be able to reject an already-settled order.
        $payments->markSuccess($deposit->fresh(), 1.0);

        $this->assertSame(Status::PAYMENT_SUCCESS, (int) $deposit->fresh()->status);
        $this->assertSame(Status::PAYMENT_SUCCESS, (int) $order->fresh()->payment_status);
    }
}
