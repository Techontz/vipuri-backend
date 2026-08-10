<?php

namespace App\Services;

use App\Constants\Status;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\Guest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderService
{
    public function __construct(
        private readonly CartService $cart,
        private readonly CartIdentity $identity,
        private readonly CouponService $coupons,
        private readonly InventoryService $inventory,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly CommissionService $commissions,
    ) {}

    /**
     * Turn the current cart into a persisted order.
     *
     * @param  array  $shippingData  Normalised shipping address payload.
     */
    public function create(array $shippingData, ?User $user = null, ?Guest $guest = null, bool $cod = false): Order
    {
        $items = $this->cart->items();

        if (empty($items)) {
            throw new RuntimeException('Your cart is empty');
        }

        $summary = $this->cart->summary();

        if (! $summary['shipping_rate_id']) {
            throw new RuntimeException('Please choose a delivery option');
        }

        $context = $this->identity->context();
        $branch = $this->inventory->resolveFulfilmentBranch($items, $context->branch_id);

        $appliedCoupon = $this->coupons->appliedCoupon($items);

        return DB::transaction(function () use ($items, $summary, $shippingData, $user, $guest, $cod, $branch, $appliedCoupon) {
            $order = Order::create([
                'company_id' => Company::current()->id,
                'branch_id' => $branch?->id,
                'shipping_method_id' => $summary['shipping_method_id'],
                'order_number' => $this->nextOrderNumber(),
                'user_id' => $user?->id ?? 0,
                'guest_id' => $guest?->id ?? 0,
                'status' => Status::ORDER_PENDING,
                'payment_status' => Status::PAYMENT_INITIATE,
                'subtotal' => $summary['subtotal'],
                'shipping_charge' => $summary['shipping_charge'],
                'total_tax' => $summary['total_tax'],
                'discount' => $summary['discount'],
                'total' => $summary['payable'],
                'cod' => $cod,
                'coupon_id' => $appliedCoupon['coupon']->id ?? 0,
                'shipping_address' => $shippingData,
                'note' => $shippingData['note'] ?? null,
            ]);

            foreach ($items as $item) {
                $product = Product::find($item['product_id']);

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'variation_id' => $item['variation_id'] ?? 0,
                    'attribute_values' => $item['variation_attributes'],
                    'product_name' => $item['product_name'],
                    'sku' => $product?->sku,
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'subtotal' => $item['subtotal'],
                    'total_tax' => $item['tax_amount'] * $item['quantity'],
                ]);
            }

            $order->load('orderItems');

            // Hold the stock at the fulfilling branch until dispatch.
            $this->inventory->reserve($order);

            if ($appliedCoupon) {
                $this->coupons->recordUsage($appliedCoupon['coupon'], $user?->id, $order->id);
            }

            $this->logStatus($order, null, Status::ORDER_PENDING, 'Order placed');

            // Clear the shopper's cart + checkout state.
            $this->cart->clear($user?->id, $user ? null : $this->identity->sessionId());
            $context = $this->identity->context();
            $context->update(['coupon_id' => null, 'coupon_discount' => 0, 'shipping_rate_id' => null]);

            $this->audit->log('order.created', $order, description: "Order {$order->order_number} placed", branchId: $branch?->id);
            $this->notifications->orderPlaced($order);

            return $order->fresh(['orderItems', 'branch']);
        });
    }

    /**
     * Move an order to a new status, applying the stock side effects and
     * writing the status log. Throws on invalid transitions.
     */
    public function changeStatus(Order $order, int $newStatus, ?string $remark = null): Order
    {
        if (! array_key_exists($newStatus, Status::ORDER_STATUS_LABELS)) {
            throw new RuntimeException('Unknown order status');
        }

        if ($order->status === $newStatus) {
            return $order;
        }

        if ($order->isFinal() && $newStatus !== Status::ORDER_RETURNED) {
            throw new RuntimeException('This order has already been closed');
        }

        $previous = (int) $order->status;

        DB::transaction(function () use ($order, $newStatus, $previous, $remark) {
            match ($newStatus) {
                Status::ORDER_DISPATCHED => $this->inventory->consumeForOrder($order),
                Status::ORDER_CANCELLED => $this->onCancel($order, $previous),
                Status::ORDER_RETURNED => $this->onReturn($order, $previous),
                default => null,
            };

            $order->status = $newStatus;
            $order->processed_by = auth('admin')->id();

            if ($newStatus === Status::ORDER_DISPATCHED) {
                $order->dispatched_at = now();
            }

            if ($newStatus === Status::ORDER_DELIVERED) {
                $order->delivered_at = now();

                if ($order->cod) {
                    $order->payment_status = Status::PAYMENT_SUCCESS;
                }
            }

            if ($newStatus === Status::ORDER_CANCELLED) {
                $order->cancelled_at = now();
                $order->cancel_reason = $remark;
            }

            $order->save();

            $this->logStatus($order, $previous, $newStatus, $remark);
        });

        $this->audit->log(
            'order.status_changed',
            $order,
            ['status' => $previous],
            ['status' => $newStatus],
            "Order {$order->order_number}: " . (Status::ORDER_STATUS_LABELS[$previous] ?? '?') .
                ' → ' . Status::ORDER_STATUS_LABELS[$newStatus],
            $order->branch_id,
        );

        // Commission follows the sale: earned on delivery, withdrawn if the
        // sale comes undone. Deliberately outside the transaction above — a
        // commission is a consequence of the status change, and a problem
        // recording one must not roll back the fulfilment itself. It reads the
        // status log this method has just written, so it runs after it.
        match ($newStatus) {
            Status::ORDER_DELIVERED => $this->commissions->recordForDelivery($order->fresh()),
            Status::ORDER_RETURNED => $this->commissions->reverseFor($order, 'returned'),
            Status::ORDER_CANCELLED => $this->commissions->reverseFor($order, 'cancelled'),
            default => null,
        };

        $this->notifications->orderStatusChanged($order->fresh());

        return $order->fresh(['orderItems', 'branch']);
    }

    public function markPaid(Order $order): Order
    {
        $order->payment_status = Status::PAYMENT_SUCCESS;

        if ((int) $order->status === Status::ORDER_PENDING) {
            $order->status = Status::ORDER_PAID;
        }

        $order->save();

        $this->logStatus($order, Status::ORDER_PENDING, (int) $order->status, 'Payment received');
        $this->audit->log('order.paid', $order, description: "Payment received for {$order->order_number}", branchId: $order->branch_id);
        $this->notifications->orderPaid($order);

        return $order;
    }

    /** Reassign fulfilment to another branch, moving any reservation with it. */
    public function assignBranch(Order $order, int $branchId): Order
    {
        $previousBranch = $order->branch_id;

        if ($previousBranch === $branchId) {
            return $order;
        }

        DB::transaction(function () use ($order, $branchId, $previousBranch) {
            if ($previousBranch && ! in_array($order->status, [Status::ORDER_DISPATCHED, Status::ORDER_DELIVERED], true)) {
                $this->inventory->releaseReservation($order);
            }

            $order->branch_id = $branchId;
            $order->save();

            if (! in_array($order->status, [Status::ORDER_DISPATCHED, Status::ORDER_DELIVERED], true)) {
                $this->inventory->reserve($order->fresh('orderItems'));
            }
        });

        $this->audit->log(
            'order.branch_reassigned',
            $order,
            ['branch_id' => $previousBranch],
            ['branch_id' => $branchId],
            "Order {$order->order_number} reassigned",
            $branchId,
        );

        return $order->fresh(['branch']);
    }

    public function counterWidgets(?int $userId = null, ?array $branchIds = null): array
    {
        $base = fn () => Order::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds ?: [0]));

        return [
            'order_total' => $base()->count(),
            'order_pending' => $base()->pending()->count(),
            'order_processing' => $base()->processing()->count(),
            'order_dispatched' => $base()->dispatched()->count(),
            'order_delivered' => $base()->delivered()->count(),
            'order_returned' => $base()->returned()->count(),
            'order_cancelled' => $base()->cancelled()->count(),
            'order_paid' => $base()->paid()->count(),
            'order_unpaid' => $base()->unpaid()->count(),
            'order_cod' => $base()->cod()->count(),
        ];
    }

    public function nextOrderNumber(): string
    {
        $prefix = gs('order_number_prefix') ?: config('vipuri.order.number_prefix');

        do {
            $number = $prefix . now()->format('ymd') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        } while (Order::where('order_number', $number)->exists());

        return $number;
    }

    private function onCancel(Order $order, int $previous): void
    {
        if (in_array($previous, [Status::ORDER_DISPATCHED, Status::ORDER_DELIVERED], true)) {
            $this->inventory->restockForOrder($order, 'order_cancelled');
        } else {
            $this->inventory->releaseReservation($order);
        }

        if ($order->coupon_id) {
            Coupon::where('id', $order->coupon_id)->where('total_uses', '>', 0)->decrement('total_uses');
        }
    }

    private function onReturn(Order $order, int $previous): void
    {
        if (in_array($previous, [Status::ORDER_DISPATCHED, Status::ORDER_DELIVERED], true)) {
            $this->inventory->restockForOrder($order, 'order_returned');
        } else {
            $this->inventory->releaseReservation($order);
        }
    }

    private function logStatus(Order $order, ?int $from, int $to, ?string $remark): void
    {
        $admin = auth('admin')->user();
        $user = auth('user')->user();

        OrderStatusLog::create([
            'order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $admin ? 'admin' : ($user ? 'user' : 'system'),
            'actor_id' => $admin?->id ?? $user?->id,
            'actor_name' => $admin?->name ?? ($user ? trim($user->firstname . ' ' . $user->lastname) : 'System'),
            'remark' => $remark,
        ]);
    }
}
