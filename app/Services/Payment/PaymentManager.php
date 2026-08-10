<?php

namespace App\Services\Payment;

use App\Constants\Status;
use App\Models\Deposit;
use App\Models\Gateway;
use App\Models\GatewayCurrency;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Creates deposits against an order and dispatches them to the right driver.
 *
 * Manual gateways (bank transfer, mobile money on file) have no driver: the
 * customer submits proof of payment and an administrator approves it, exactly
 * as in the source system.
 */
class PaymentManager
{
    public function __construct(private readonly OrderService $orders) {}

    /** Gateways a customer can pick from at checkout. */
    public function availableMethods(float $amount)
    {
        return GatewayCurrency::query()
            ->whereHas('gateway', fn ($q) => $q->active())
            ->with('gateway')
            ->get()
            ->filter(function (GatewayCurrency $currency) use ($amount) {
                if ($currency->min_amount > 0 && $amount < $currency->min_amount) {
                    return false;
                }

                if ($currency->max_amount > 0 && $amount > $currency->max_amount) {
                    return false;
                }

                $gateway = $currency->gateway;

                if (! $gateway) {
                    return false;
                }

                // Automatic gateways are only offered when their driver has credentials.
                if ($gateway->isAutomatic()) {
                    $driver = $this->driverFor($gateway);

                    return $driver?->isConfigured() ?? false;
                }

                return true;
            })
            ->values();
    }

    /** Create the deposit record for an order and start the payment. */
    public function startPayment(Order $order, int $gatewayCurrencyId): array
    {
        $currency = GatewayCurrency::with('gateway')->findOrFail($gatewayCurrencyId);
        $gateway = $currency->gateway;

        if (! $gateway || ! $gateway->status) {
            throw new RuntimeException('This payment method is not available');
        }

        $amount = (float) $order->total;

        if ($currency->min_amount > 0 && $amount < $currency->min_amount) {
            throw new RuntimeException('Minimum amount for this method is ' . showAmount($currency->min_amount));
        }

        if ($currency->max_amount > 0 && $amount > $currency->max_amount) {
            throw new RuntimeException('Maximum amount for this method is ' . showAmount($currency->max_amount));
        }

        $charge = $currency->fixed_charge + ($amount * $currency->percent_charge / 100);
        $payable = $amount + $charge;

        $deposit = DB::transaction(fn () => Deposit::create([
            'user_id' => $order->user_id ?: 0,
            'guest_id' => $order->guest_id ?: 0,
            'order_id' => $order->id,
            'method_code' => $currency->method_code,
            'method_currency' => $currency->currency,
            'amount' => $amount,
            'charge' => $charge,
            'rate' => $currency->rate ?: 1,
            'final_amount' => $payable * ($currency->rate ?: 1),
            'trx' => getTrx(),
            'status' => Status::PAYMENT_INITIATE,
            'success_url' => config('vipuri.frontend_url') . '/order-confirmation/' . $order->order_number,
            'failed_url' => config('vipuri.frontend_url') . '/checkout/payment?order=' . $order->order_number,
        ]));

        if ($gateway->isManual()) {
            return [
                'type' => 'manual',
                'deposit' => $deposit,
                'gateway' => $gateway,
                'instructions' => $gateway->description,
                'fields' => $gateway->gateway_parameters,
            ];
        }

        $driver = $this->driverFor($gateway);

        if (! $driver) {
            throw new RuntimeException('This payment method is not implemented');
        }

        if (! $driver->isConfigured()) {
            throw new RuntimeException('This payment method has not been configured yet');
        }

        $result = $driver->initiate($deposit);
        $result['deposit'] = $deposit;

        return $result;
    }

    /** Submit a manual payment for administrator review. */
    public function submitManualPayment(Deposit $deposit, array $detail): Deposit
    {
        $deposit->detail = $detail;
        $deposit->status = Status::PAYMENT_PENDING;
        $deposit->save();

        if ($deposit->order) {
            $deposit->order->update(['payment_status' => Status::PAYMENT_PENDING]);
        }

        return $deposit;
    }

    /**
     * Mark a deposit successful and settle its order.
     *
     * `$paidAmount` is what the provider said was actually captured, in the
     * same unit as `final_amount`. A short payment is refused rather than
     * settling the order: the gateway confirming *a* payment is not the same
     * as it confirming *this* payment. Drivers that cannot report an amount
     * pass null and the check is skipped, as before.
     */
    public function markSuccess(Deposit $deposit, ?float $paidAmount = null): Deposit
    {
        if ((int) $deposit->status === Status::PAYMENT_SUCCESS) {
            return $deposit;
        }

        // One cent of tolerance absorbs the provider's own rounding.
        if ($paidAmount !== null && $paidAmount + 0.01 < (float) $deposit->final_amount) {
            Log::warning(sprintf(
                'VIPURI refused to settle deposit %s: provider reported %s against %s expected',
                $deposit->trx,
                $paidAmount,
                $deposit->final_amount,
            ));

            return $this->markRejected($deposit, 'The amount received did not match the amount due');
        }

        $deposit->status = Status::PAYMENT_SUCCESS;
        $deposit->save();

        if ($deposit->order) {
            $this->orders->markPaid($deposit->order);
        }

        return $deposit;
    }

    public function markRejected(Deposit $deposit, ?string $feedback = null): Deposit
    {
        $deposit->status = Status::PAYMENT_REJECT;
        $deposit->admin_feedback = $feedback;
        $deposit->save();

        if ($deposit->order) {
            $deposit->order->update(['payment_status' => Status::PAYMENT_REJECT]);
        }

        return $deposit;
    }

    public function driverFor(Gateway $gateway): ?PaymentDriver
    {
        $class = config('vipuri.payment.drivers.' . $gateway->alias);

        if (! $class || ! class_exists($class)) {
            return null;
        }

        return app($class);
    }

    public function driverForAlias(string $alias): ?PaymentDriver
    {
        $class = config("vipuri.payment.drivers.$alias");

        return $class && class_exists($class) ? app($class) : null;
    }
}
