<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\StockLog;
use App\Models\StockTransfer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Branch-aware inventory.
 *
 * `branch_inventories` is the source of truth per outlet;
 * `products.stock_quantity` / `product_variations.stock_quantity` hold the
 * company-wide roll-up so every storefront query inherited from the source
 * system keeps working unchanged.
 */
class InventoryService
{
    public function __construct(private readonly AuditService $audit) {}

    /* ------------------------------------------------------------------ *
     | Reads
     * ------------------------------------------------------------------ */

    public function row(int $branchId, int $productId, int $variationId = 0): BranchInventory
    {
        return BranchInventory::firstOrCreate(
            ['branch_id' => $branchId, 'product_id' => $productId, 'variation_id' => $variationId],
            ['stock_quantity' => 0, 'reserved_quantity' => 0],
        );
    }

    /** Company-wide sellable quantity (all branches, minus reservations). */
    public function sellableQuantity(int $productId, int $variationId = 0, ?int $branchId = null): int
    {
        $query = BranchInventory::query()
            ->where('product_id', $productId)
            ->where('variation_id', $variationId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $total = (int) $query->sum('stock_quantity');
        $reserved = (int) $query->sum('reserved_quantity');

        if ($total === 0 && ! BranchInventory::where('product_id', $productId)->exists()) {
            // No branch rows yet (e.g. freshly imported product) — fall back to
            // the catalogue-level figure so the product is still purchasable.
            $product = Product::find($productId);

            return (int) ($variationId
                ? ($product?->variations->firstWhere('id', $variationId)?->stock_quantity ?? 0)
                : ($product?->stock_quantity ?? 0));
        }

        return max(0, $total - $reserved);
    }

    /** Branch with the most free stock for a line item, or null if none has any. */
    public function branchWithStock(int $productId, int $variationId = 0): ?Branch
    {
        $row = BranchInventory::query()
            ->where('product_id', $productId)
            ->where('variation_id', $variationId)
            ->whereRaw('stock_quantity - reserved_quantity > 0')
            ->whereHas('branch', fn ($q) => $q->active())
            ->orderByRaw('stock_quantity - reserved_quantity DESC')
            ->first();

        return $row?->branch;
    }

    /**
     * Choose the branch that can fulfil the most lines of an order, preferring
     * a customer-selected branch when it can serve everything.
     */
    public function resolveFulfilmentBranch(array $items, ?int $preferredBranchId = null): ?Branch
    {
        if ($preferredBranchId) {
            $branch = Branch::active()->find($preferredBranchId);

            if ($branch && $this->branchCanFulfil($branch->id, $items)) {
                return $branch;
            }
        }

        $scores = [];

        foreach (Branch::active()->pluck('id') as $branchId) {
            $scores[$branchId] = 0;

            foreach ($items as $item) {
                $available = $this->sellableQuantity(
                    (int) $item['product_id'],
                    (int) ($item['variation_id'] ?? 0),
                    $branchId,
                );

                if ($available >= (int) $item['quantity']) {
                    $scores[$branchId]++;
                }
            }
        }

        arsort($scores);
        $bestId = array_key_first($scores);

        if ($bestId && $scores[$bestId] > 0) {
            return Branch::find($bestId);
        }

        return Branch::fallback();
    }

    public function branchCanFulfil(int $branchId, array $items): bool
    {
        foreach ($items as $item) {
            $available = $this->sellableQuantity(
                (int) $item['product_id'],
                (int) ($item['variation_id'] ?? 0),
                $branchId,
            );

            if ($available < (int) $item['quantity']) {
                return false;
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------ *
     | Mutations
     * ------------------------------------------------------------------ */

    /**
     * Apply a signed stock delta at a branch, write the audit trail and keep
     * the catalogue roll-up in sync.
     */
    public function adjust(
        int $branchId,
        int $productId,
        int $variationId,
        int $change,
        string $remark,
        ?string $description = null,
        ?int $orderId = null,
    ): BranchInventory {
        return DB::transaction(function () use ($branchId, $productId, $variationId, $change, $remark, $description, $orderId) {
            $row = BranchInventory::query()
                ->where('branch_id', $branchId)
                ->where('product_id', $productId)
                ->where('variation_id', $variationId)
                ->lockForUpdate()
                ->first()
                ?? $this->row($branchId, $productId, $variationId);

            $row->stock_quantity += $change;

            if ($row->stock_quantity < 0) {
                $row->stock_quantity = 0;
            }

            $row->save();

            StockLog::create([
                'branch_id' => $branchId,
                'product_id' => $productId,
                'product_variation_id' => $variationId,
                'order_id' => $orderId,
                'change_quantity' => $change,
                'post_quantity' => $row->stock_quantity,
                'remark' => $remark,
                'description' => $description,
                'actor_type' => auth('admin')->check() ? 'admin' : 'system',
                'actor_id' => auth('admin')->id(),
                'actor_name' => auth('admin')->user()?->name,
            ]);

            $this->syncCatalogueTotals($productId, $variationId);

            return $row;
        });
    }

    /** Absolute stock count (stock take) at a branch. */
    public function setQuantity(int $branchId, int $productId, int $variationId, int $quantity, ?string $note = null): BranchInventory
    {
        $row = $this->row($branchId, $productId, $variationId);
        $change = $quantity - (int) $row->stock_quantity;

        $result = $this->adjust(
            $branchId,
            $productId,
            $variationId,
            $change,
            'stock_take',
            $note ?: 'Manual stock count',
        );

        $result->last_counted_at = now();
        $result->save();

        return $result;
    }

    /**
     * Hold stock for an order that has not shipped yet.
     *
     * Availability is re-checked here rather than trusting the check made when
     * the item went into the cart, which may be hours old. The branch row is
     * locked first: `reserved_quantity` is a read-modify-write, so two
     * checkouts racing for the last unit would otherwise both read the same
     * figure, both pass, and both reserve it.
     *
     * @throws RuntimeException when the stock is no longer there.
     */
    public function reserve(Order $order): void
    {
        if (! $order->branch_id) {
            return;
        }

        DB::transaction(function () use ($order) {
            foreach ($order->orderItems as $item) {
                $row = $this->lockRow($order->branch_id, (int) $item->product_id, (int) $item->variation_id);

                $this->assertReservable((int) $item->product_id, (int) $item->variation_id, (int) $item->quantity);

                $row->reserved_quantity += $item->quantity;
                $row->save();
            }
        });
    }

    public function releaseReservation(Order $order): void
    {
        if (! $order->branch_id) {
            return;
        }

        DB::transaction(function () use ($order) {
            foreach ($order->orderItems as $item) {
                $row = $this->lockRow($order->branch_id, (int) $item->product_id, (int) $item->variation_id);
                $row->reserved_quantity = max(0, $row->reserved_quantity - $item->quantity);
                $row->save();
            }
        });
    }

    /** Deduct physical stock when an order is dispatched/fulfilled. */
    public function consumeForOrder(Order $order): void
    {
        if (! $order->branch_id) {
            return;
        }

        DB::transaction(function () use ($order) {
            foreach ($order->orderItems as $item) {
                $row = $this->lockRow($order->branch_id, (int) $item->product_id, (int) $item->variation_id);
                $row->reserved_quantity = max(0, $row->reserved_quantity - $item->quantity);
                $row->save();

                $this->adjust(
                    $order->branch_id,
                    $item->product_id,
                    (int) $item->variation_id,
                    -$item->quantity,
                    'order_fulfilled',
                    "Order {$order->order_number}",
                    $order->id,
                );
            }
        });
    }

    /**
     * The branch row for a line, locked until the surrounding transaction ends.
     * Every reservation change goes through this so they serialise.
     */
    private function lockRow(int $branchId, int $productId, int $variationId): BranchInventory
    {
        // Make sure the row exists before trying to lock it — there is nothing
        // to lock on the first sale of a product at a branch.
        $this->row($branchId, $productId, $variationId);

        return BranchInventory::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('variation_id', $variationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Refuse a reservation the stock cannot cover, using the same rule the cart
     * applied when the item was added — but read after the row lock is held, so
     * a concurrent order's reservation is already visible.
     */
    private function assertReservable(int $productId, int $variationId, int $quantity): void
    {
        $variation = $variationId ? ProductVariation::find($variationId) : null;
        $product = Product::find($productId);

        if (! $product) {
            return;
        }

        $tracks = $variation ? $variation->trackInventory() : $product->trackInventory();
        $backorder = $variation ? $variation->allow_backorder : $product->allow_backorder;

        if (! $tracks || $backorder) {
            return;
        }

        if ($this->sellableQuantity($productId, $variationId) < $quantity) {
            throw new RuntimeException(
                "\"{$product->name}\" is no longer available in the quantity you asked for"
            );
        }
    }

    /** Put stock back when an order is cancelled or returned after fulfilment. */
    public function restockForOrder(Order $order, string $remark = 'order_restocked'): void
    {
        if (! $order->branch_id) {
            return;
        }

        DB::transaction(function () use ($order, $remark) {
            foreach ($order->orderItems as $item) {
                $this->adjust(
                    $order->branch_id,
                    $item->product_id,
                    (int) $item->variation_id,
                    $item->quantity,
                    $remark,
                    "Order {$order->order_number}",
                    $order->id,
                );
            }
        });
    }

    /* ------------------------------------------------------------------ *
     | Transfers
     * ------------------------------------------------------------------ */

    public function dispatchTransfer(StockTransfer $transfer, int $adminId): StockTransfer
    {
        if ((int) $transfer->status !== \App\Constants\Status::TRANSFER_PENDING) {
            throw new RuntimeException('Only pending transfers can be dispatched');
        }

        DB::transaction(function () use ($transfer, $adminId) {
            foreach ($transfer->items as $item) {
                $available = $this->sellableQuantity($item->product_id, (int) $item->variation_id, $transfer->from_branch_id);

                if ($available < $item->quantity) {
                    throw new RuntimeException("Not enough stock at the source branch for product #{$item->product_id}");
                }

                $this->adjust(
                    $transfer->from_branch_id,
                    $item->product_id,
                    (int) $item->variation_id,
                    -$item->quantity,
                    'transfer_out',
                    "Transfer {$transfer->reference}",
                );
            }

            $transfer->status = \App\Constants\Status::TRANSFER_IN_TRANSIT;
            $transfer->approved_by = $adminId;
            $transfer->dispatched_at = now();
            $transfer->save();
        });

        $this->audit->log('stock_transfer.dispatched', $transfer, description: "Transfer {$transfer->reference} dispatched");

        return $transfer->fresh('items');
    }

    public function receiveTransfer(StockTransfer $transfer, int $adminId, array $receivedQuantities = []): StockTransfer
    {
        if ((int) $transfer->status !== \App\Constants\Status::TRANSFER_IN_TRANSIT) {
            throw new RuntimeException('Only in-transit transfers can be received');
        }

        DB::transaction(function () use ($transfer, $adminId, $receivedQuantities) {
            foreach ($transfer->items as $item) {
                $received = (int) ($receivedQuantities[$item->id] ?? $item->quantity);
                $received = max(0, min($received, $item->quantity));

                $item->received_quantity = $received;
                $item->save();

                if ($received > 0) {
                    $this->adjust(
                        $transfer->to_branch_id,
                        $item->product_id,
                        (int) $item->variation_id,
                        $received,
                        'transfer_in',
                        "Transfer {$transfer->reference}",
                    );
                }

                // Anything that did not arrive goes back to the sending branch.
                $shortfall = $item->quantity - $received;

                if ($shortfall > 0) {
                    $this->adjust(
                        $transfer->from_branch_id,
                        $item->product_id,
                        (int) $item->variation_id,
                        $shortfall,
                        'transfer_shortfall',
                        "Transfer {$transfer->reference} shortfall returned",
                    );
                }
            }

            $transfer->status = \App\Constants\Status::TRANSFER_RECEIVED;
            $transfer->received_by = $adminId;
            $transfer->received_at = now();
            $transfer->save();
        });

        $this->audit->log('stock_transfer.received', $transfer, description: "Transfer {$transfer->reference} received");

        return $transfer->fresh('items');
    }

    /* ------------------------------------------------------------------ *
     | Roll-up
     * ------------------------------------------------------------------ */

    /** Recompute catalogue-level stock from the branch rows. */
    public function syncCatalogueTotals(int $productId, int $variationId = 0): void
    {
        $total = (int) BranchInventory::where('product_id', $productId)
            ->where('variation_id', $variationId)
            ->sum('stock_quantity');

        if ($variationId) {
            DB::table('product_variations')->where('id', $variationId)->update(['stock_quantity' => $total]);

            $productTotal = (int) BranchInventory::where('product_id', $productId)->sum('stock_quantity');
            DB::table('products')->where('id', $productId)->update(['stock_quantity' => $productTotal]);

            return;
        }

        DB::table('products')->where('id', $productId)->update(['stock_quantity' => $total]);
    }
}
