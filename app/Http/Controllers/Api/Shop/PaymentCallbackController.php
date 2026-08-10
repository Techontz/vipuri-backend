<?php

namespace App\Http\Controllers\Api\Shop;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Services\Payment\PaymentManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Gateway callbacks (IPN / webhooks).
 *
 * Payloads are never trusted: each driver re-reads the transaction from the
 * provider before a deposit is marked successful.
 */
class PaymentCallbackController extends Controller
{
    public function __construct(private readonly PaymentManager $payments) {}

    public function handle(Request $request, string $alias)
    {
        $driver = $this->payments->driverForAlias($alias);

        if (! $driver) {
            return response()->json(['status' => 'unknown_gateway'], 404);
        }

        $result = $driver->verify($request->all());

        if (empty($result['trx'])) {
            Log::warning("VIPURI {$alias} callback without a transaction reference");

            return response()->json(['status' => 'ignored'], 202);
        }

        $deposit = Deposit::where('trx', $result['trx'])->first();

        if (! $deposit) {
            Log::warning("VIPURI {$alias} callback for unknown trx {$result['trx']}");

            return response()->json(['status' => 'unknown_transaction'], 404);
        }

        if ($result['success']) {
            $this->payments->markSuccess($deposit, $result['amount'] ?? null);

            return response()->json(['status' => 'success']);
        }

        $this->payments->markRejected($deposit, $result['message'] ?? null);

        return response()->json(['status' => 'failed']);
    }

    /** Called by the storefront when the shopper returns from a gateway. */
    public function confirm(Request $request, string $trx)
    {
        $deposit = Deposit::with('order')->where('trx', $trx)->firstOrFail();

        $gateway = $deposit->gateway;
        $driver = $gateway ? $this->payments->driverFor($gateway) : null;

        // Re-verify with the provider; the redirect itself proves nothing.
        if ($driver && (int) $deposit->status !== \App\Constants\Status::PAYMENT_SUCCESS) {
            $result = $driver->verify($request->all() + ['trx' => $trx, 'reference' => $trx]);

            // The provider's own reference must match the deposit we are about
            // to settle. Without this, a shopper could return from their own
            // successful payment carrying somebody else's trx in the URL.
            if (! empty($result['success']) && ($result['trx'] ?? null) === $deposit->trx) {
                $this->payments->markSuccess($deposit, $result['amount'] ?? null);
            }
        }

        $deposit->refresh();

        return responseSuccess('payment_status', 'Payment status fetched', [
            'status' => (int) $deposit->status,
            'order_number' => $deposit->order?->order_number,
            'paid' => (int) $deposit->status === \App\Constants\Status::PAYMENT_SUCCESS,
        ]);
    }
}
