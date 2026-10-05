<?php

namespace App\Services;

use App\Constants\Status;
use App\Models\Admin;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderCommission;
use App\Models\OrderStatusLog;

/**
 * Worker commission: when it is earned, by whom, and what happens when the
 * sale comes undone.
 *
 * Two business rules are settled here, and both are settings rather than
 * assumptions baked into the code, because getting either wrong pays the wrong
 * person or pays them too early:
 *
 *  - **When.** On delivery. Dispatch is too early — the goods can still come
 *    back — and there is no later event to hang it on. `general_settings`
 *    controls only whether it happens at all, not when.
 *
 *  - **Who.** `commission_attribution` decides which entry in the order's
 *    status log names the earner: the staff member who marked it delivered
 *    (the default) or the one who first moved it to processing. It is read
 *    from `order_status_logs`, which is append-only, rather than from
 *    `orders.processed_by`, which is overwritten by whoever acted last.
 *
 * Nothing accrues while `commission_enabled` is off or the rate is 0, which is
 * how an existing installation behaves until somebody sets a rate.
 */
class CommissionService
{
    public function __construct(private readonly AuditService $audit) {}

    /** Is the company actually running a commission scheme? */
    public function enabled(): bool
    {
        return (bool) gs('commission_enabled') && $this->rate() > 0;
    }

    /** The company's current rate, as a percentage of the order subtotal. */
    public function rate(): float
    {
        return round((float) (gs('commission_rate') ?? 0), 2);
    }

    public function attribution(): string
    {
        return gs('commission_attribution') ?: 'delivered_by';
    }

    /**
     * Record the commission for an order that has just been delivered.
     *
     * Idempotent: a second delivery event — a re-delivery after a return, a
     * double-submitted request — finds the existing row and leaves it alone.
     * Returns null whenever nothing was recorded, which is the normal case for
     * a company that has not turned commission on.
     */
    public function recordForDelivery(Order $order): ?OrderCommission
    {
        if (! $this->enabled()) {
            return null;
        }

        $existing = OrderCommission::where('order_id', $order->id)->first();

        if ($existing) {
            return $existing;
        }

        $earner = $this->earnerFor($order);

        if (! $earner) {
            return null;
        }

        $rate = $this->rate();

        // The goods, not the delivery charge and not the tax: commission is
        // paid on what was sold, and shipping and VAT are not the shop's to
        // share out.
        $basis = round((float) $order->subtotal - (float) $order->discount, 2);

        if ($basis <= 0) {
            return null;
        }

        $commission = OrderCommission::create([
            'company_id' => $order->company_id ?: Company::current()->id,
            'order_id' => $order->id,
            'admin_id' => $earner->id,
            'branch_id' => $order->branch_id ?: 0,
            'rate' => $rate,
            'basis_amount' => $basis,
            'amount' => round($basis * $rate / 100, 2),
            'status' => Status::COMMISSION_PENDING,
            'earned_at' => $order->delivered_at ?? now(),
        ]);

        $this->audit->log(
            'commission.earned',
            $commission,
            description: "Commission on {$order->order_number} for {$earner->name}",
            branchId: $order->branch_id,
        );

        return $commission;
    }

    /**
     * Withdraw the entitlement when a delivered order is returned or cancelled.
     *
     * The row is marked, never deleted, and a commission already paid out is
     * left alone — clawing money back is a payroll decision, not something a
     * status change should do silently.
     */
    public function reverseFor(Order $order, string $reason): ?OrderCommission
    {
        $commission = OrderCommission::where('order_id', $order->id)->first();

        if (! $commission || (int) $commission->status === Status::COMMISSION_REVERSED) {
            return $commission;
        }

        if ((int) $commission->status === Status::COMMISSION_PAID) {
            $commission->update([
                'note' => trim("Already paid when the order was {$reason}. Recover through payroll."),
            ]);

            return $commission;
        }

        $commission->update([
            'status' => Status::COMMISSION_REVERSED,
            'note' => "Reversed: order {$reason}",
        ]);

        $this->audit->log(
            'commission.reversed',
            $commission,
            description: "Commission on {$order->order_number} reversed ({$reason})",
            branchId: $order->branch_id,
        );

        return $commission;
    }

    /**
     * The staff member the configured rule credits, read from the order's own
     * status log so the answer is auditable rather than inferred.
     */
    private function earnerFor(Order $order): ?Admin
    {
        // A counter sale is processed and handed over by one person in one
        // step, so whichever rule is configured the seller is the earner.
        if ($order->channel === Order::CHANNEL_POS && $order->sold_by) {
            return Admin::find($order->sold_by);
        }

        $status = $this->attribution() === 'processed_by'
            ? Status::ORDER_PROCESSING
            : Status::ORDER_DELIVERED;

        $log = OrderStatusLog::where('order_id', $order->id)
            ->where('to_status', $status)
            ->where('actor_type', 'admin')
            ->whereNotNull('actor_id')
            // `processed_by` credits whoever first took the order on;
            // `delivered_by` credits whoever completed it most recently.
            ->orderBy('id', $status === Status::ORDER_PROCESSING ? 'asc' : 'desc')
            ->first();

        return $log ? Admin::find($log->actor_id) : null;
    }
}
