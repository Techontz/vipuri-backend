<?php

namespace App\Http\Controllers\Api\Shop;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Address;
use App\Models\Deposit;
use App\Models\Guest;
use App\Models\Order;
use App\Services\CartIdentity;
use App\Services\CartService;
use App\Services\NotificationService;
use App\Services\OrderService;
use App\Services\Payment\PaymentManager;
use Illuminate\Http\Request;
use RuntimeException;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly CartIdentity $identity,
        private readonly OrderService $orders,
        private readonly PaymentManager $payments,
        private readonly NotificationService $notifications,
    ) {}

    /** Everything the checkout screen needs in one call. */
    public function index()
    {
        if ($this->cart->isEmpty()) {
            return responseError('empty_cart', ['Your cart is empty']);
        }

        $user = auth('user')->user();
        $summary = $this->cart->summary();

        return responseSuccess('checkout', 'Checkout data fetched', [
            'items' => $this->cart->items(),
            'summary' => $summary,
            'addresses' => $user
                ? Address::where('user_id', $user->id)->orderByDesc('is_default')->get()
                : [],
            'shipping_zones' => \App\Models\ShippingZone::active()->get(['id', 'name']),
            'branches' => \App\Models\Branch::active()->orderByDesc('is_default')->get()
                ->map(fn ($b) => [
                    'id' => $b->id, 'name' => $b->name, 'city' => $b->city,
                    'address' => $b->address, 'phone' => $b->phone,
                    'is_pickup_point' => (bool) $b->is_pickup_point,
                ])->values(),
            'has_cod' => (bool) gs('has_cod'),
        ]);
    }

    /** Place the order. Payment happens in the next step. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'firstname' => ['required', 'string', 'max:80'],
            'lastname' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:191'],
            'dial_code' => ['nullable', 'string', 'max:10'],
            'mobile' => ['required', 'string', 'max:40'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:191'],
            'state' => ['nullable', 'string', 'max:191'],
            'zip' => ['nullable', 'string', 'max:40'],
            'country_name' => ['nullable', 'string', 'max:191'],
            'country_code' => ['nullable', 'string', 'max:10'],
            'note' => ['nullable', 'string', 'max:1000'],
            'cod' => ['nullable', 'boolean'],
            'save_address' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        if ($this->cart->isEmpty()) {
            throw new RuntimeException('Your cart is empty');
        }

        $cod = (bool) ($data['cod'] ?? false);

        if ($cod && ! gs('has_cod')) {
            throw new RuntimeException('Cash on delivery is not available');
        }

        if (array_key_exists('branch_id', $data)) {
            $this->cart->chooseBranch($data['branch_id'] ?? null);
        }

        $user = auth('user')->user();
        $guest = null;

        if (! $user) {
            $guest = Guest::create([
                'firstname' => $data['firstname'],
                'lastname' => $data['lastname'],
                'email' => $data['email'],
                'dial_code' => $data['dial_code'] ?? null,
                'mobile' => $data['mobile'],
                'address' => $data['address'],
                'city' => $data['city'],
                'state' => $data['state'] ?? null,
                'zip' => $data['zip'] ?? null,
                'country_name' => $data['country_name'] ?? 'Tanzania',
                'country_code' => $data['country_code'] ?? 'TZ',
                'session_id' => $this->identity->sessionId(),
            ]);
        } elseif (! empty($data['save_address'])) {
            Address::create([
                'user_id' => $user->id,
                'title' => 'Delivery address',
                'firstname' => $data['firstname'],
                'lastname' => $data['lastname'],
                'dial_code' => $data['dial_code'] ?? null,
                'mobile' => $data['mobile'],
                'email' => $data['email'],
                'address' => $data['address'],
                'city' => $data['city'],
                'state' => $data['state'] ?? null,
                'zip' => $data['zip'] ?? null,
                'country_name' => $data['country_name'] ?? 'Tanzania',
                'country_code' => $data['country_code'] ?? 'TZ',
            ]);
        }

        $order = $this->orders->create($data, $user, $guest, $cod);

        if ($cod) {
            $order->update(['payment_status' => Status::PAYMENT_PENDING]);
        }

        return responseSuccess('order_placed', 'Your order has been placed', [
            'order' => new OrderResource(
                $order->load('orderItems.product.media', 'orderItems.variation', 'branch')
            ),
            'requires_payment' => ! $cod,
        ]);
    }

    /** Payment methods for a placed but unpaid order. */
    public function paymentMethods(string $orderNumber)
    {
        $order = $this->findOrder($orderNumber);

        return responseSuccess('payment_methods', 'Payment methods fetched', [
            'order' => new OrderResource($order),
            'methods' => $this->payments->availableMethods((float) $order->total)
                ->map(fn ($currency) => [
                    'id' => $currency->id,
                    'name' => $currency->name,
                    'gateway_alias' => $currency->gateway_alias,
                    'currency' => $currency->currency,
                    'symbol' => $currency->symbol,
                    'image' => fileUrl('gateway', $currency->gateway?->image),
                    'is_manual' => $currency->gateway?->isManual(),
                    'description' => $currency->gateway?->description,
                    'min_amount' => (float) $currency->min_amount,
                    'max_amount' => (float) $currency->max_amount,
                    'percent_charge' => (float) $currency->percent_charge,
                    'fixed_charge' => (float) $currency->fixed_charge,
                    'rate' => (float) $currency->rate,
                ])->values(),
        ]);
    }

    /** Start payment for an order with the chosen gateway. */
    public function pay(Request $request, string $orderNumber)
    {
        $data = $request->validate([
            'gateway_currency_id' => ['required', 'integer', 'exists:gateway_currencies,id'],
        ]);

        $order = $this->findOrder($orderNumber);

        if ((int) $order->payment_status === Status::PAYMENT_SUCCESS) {
            throw new RuntimeException('This order has already been paid');
        }

        $result = $this->payments->startPayment($order, $data['gateway_currency_id']);

        if (($result['type'] ?? null) === 'error') {
            return responseError('payment_failed', [$result['message'] ?? 'Payment could not be started']);
        }

        return responseSuccess('payment_started', 'Payment initiated', [
            'type' => $result['type'],
            'redirect_url' => $result['url'] ?? null,
            'fields' => $result['fields'] ?? null,
            'instructions' => $result['instructions'] ?? null,
            'trx' => $result['deposit']->trx,
            'amount' => (float) $result['deposit']->amount,
            'charge' => (float) $result['deposit']->charge,
            'final_amount' => (float) $result['deposit']->final_amount,
        ]);
    }

    /** Submit proof of payment for a manual gateway. */
    public function submitManualPayment(Request $request, string $trx)
    {
        $deposit = Deposit::where('trx', $trx)->firstOrFail();

        if ((int) $deposit->status !== Status::PAYMENT_INITIATE) {
            throw new RuntimeException('This payment has already been submitted');
        }

        $this->assertOwnsDeposit($deposit);

        $data = $request->validate([
            'detail' => ['required', 'array'],
            'detail.*' => ['nullable'],
        ]);

        $this->payments->submitManualPayment($deposit, $data['detail']);
        $this->notifications->depositRequested($deposit->fresh(['order', 'gateway']));

        return responseSuccess('payment_submitted', 'Your payment is under review');
    }

    /** Public order confirmation / tracking. */
    public function orderConfirmation(string $orderNumber)
    {
        $order = Order::where('order_number', $orderNumber)
            ->with(['orderItems.product.media', 'orderItems.variation', 'branch', 'shippingMethod', 'user', 'guest', 'deposits'])
            ->firstOrFail();

        $this->assertCanViewOrder($order);

        return responseSuccess('order_confirmation', 'Order fetched', [
            'order' => new OrderResource($order),
        ]);
    }

    public function track(string $orderNumber)
    {
        $order = Order::where('order_number', $orderNumber)
            ->with(['statusLogs', 'branch'])
            ->first();

        if (! $order) {
            return responseError('order_not_found', ['No order found with this number']);
        }

        return responseSuccess('track_order', 'Order tracking fetched', [
            'order_number' => $order->order_number,
            'status' => (int) $order->status,
            'status_label' => $order->status_label,
            'payment_status' => (int) $order->payment_status,
            'payment_status_label' => $order->payment_status_label,
            'branch' => $order->branch?->name,
            'placed_at' => $order->created_at?->toIso8601String(),
            'dispatched_at' => $order->dispatched_at?->toIso8601String(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'timeline' => $order->statusLogs->map(fn ($log) => [
                'status' => (int) $log->to_status,
                'label' => $log->to_status_label,
                'remark' => $log->remark,
                'at' => $log->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    private function findOrder(string $orderNumber): Order
    {
        $order = Order::where('order_number', $orderNumber)
            ->with(['orderItems', 'user', 'guest'])
            ->firstOrFail();

        $this->assertCanViewOrder($order);

        return $order;
    }

    /**
     * A signed-in customer may only see their own orders. Guests are matched by
     * the cart token that was used to place the order, so an order number alone
     * is never enough to read somebody else's details.
     */
    private function assertCanViewOrder(Order $order): void
    {
        $userId = auth('user')->id();

        if ($userId && (int) $order->user_id === (int) $userId) {
            return;
        }

        if (! $order->user_id && $order->guest_id) {
            $guest = $order->guest;
            $token = $this->identity->sessionId();

            if ($guest && $token && hash_equals((string) $guest->session_id, (string) $token)) {
                return;
            }
        }

        abort(403, 'You are not allowed to view this order');
    }

    private function assertOwnsDeposit(Deposit $deposit): void
    {
        $userId = auth('user')->id();

        if ($userId && (int) $deposit->user_id === (int) $userId) {
            return;
        }

        if ($deposit->order) {
            $this->assertCanViewOrder($deposit->order);

            return;
        }

        abort(403, 'You are not allowed to act on this payment');
    }
}
