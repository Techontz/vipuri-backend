<?php

namespace App\Http\Controllers\Api\Admin;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\StockLog;
use App\Models\StockTransfer;
use App\Services\AuditService;
use App\Services\InventoryService;
use App\Services\NotificationService;
use App\Traits\ScopesToBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Branch inventory: stock levels, adjustments, history and transfers.
 * Every read and write is branch-scoped in the backend.
 */
class InventoryController extends Controller
{
    use ScopesToBranch;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request)
    {
        $query = BranchInventory::query()
            ->with(['product:id,name,slug,sku,product_type', 'product.media', 'branch:id,name,code', 'variation'])
            ->when($request->query('branch_id'), function ($q, $branchId) {
                $this->authorizeBranch((int) $branchId);
                $q->where('branch_id', $branchId);
            })
            ->when($request->query('product_id'), fn ($q, $id) => $q->where('product_id', $id))
            ->when($request->query('search'), fn ($q, $s) => $q->whereHas('product', fn ($p) => $p
                ->where('name', 'like', "%$s%")
                ->orWhere('sku', 'like', "%$s%")))
            ->when($request->boolean('low_stock'), fn ($q) => $q->lowStock())
            ->when($request->boolean('out_of_stock'), fn ($q) => $q->outOfStock())
            ->orderBy('stock_quantity');

        $this->scopeBranch($query);

        $rows = $query->paginate(getPaginate(25));

        return responseSuccess('inventory', 'Inventory fetched', [
            'inventory' => collect($rows->items())->map(fn (BranchInventory $row) => [
                'id' => $row->id,
                'branch_id' => $row->branch_id,
                'branch_name' => $row->branch?->name,
                'product_id' => $row->product_id,
                'product_name' => $row->product?->name,
                'product_slug' => $row->product?->slug,
                'sku' => $row->product?->sku,
                'image' => fileUrl('product', $row->product?->main_image, true),
                'variation_id' => (int) $row->variation_id,
                'variation_label' => $row->variation?->name,
                'stock_quantity' => (int) $row->stock_quantity,
                'reserved_quantity' => (int) $row->reserved_quantity,
                'available_quantity' => $row->available_quantity,
                'min_stock_quantity' => (int) $row->min_stock_quantity,
                'shelf_location' => $row->shelf_location,
                'cost_price' => (float) $row->cost_price,
                'is_low' => $row->isLow(),
                'last_counted_at' => $row->last_counted_at?->toIso8601String(),
            ])->values(),
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
            'summary' => $this->summary(),
        ]);
    }

    /** Adjust stock by a signed delta, or set an absolute count. */
    public function adjust(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variation_id' => ['nullable', 'integer'],
            'mode' => ['required', Rule::in(['delta', 'absolute'])],
            'quantity' => ['required', 'integer'],
            'remark' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'min_stock_quantity' => ['nullable', 'integer', 'min:0'],
            'shelf_location' => ['nullable', 'string', 'max:80'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->authorizeBranch((int) $data['branch_id']);

        $variationId = (int) ($data['variation_id'] ?? 0);

        $row = $data['mode'] === 'absolute'
            ? $this->inventory->setQuantity(
                $data['branch_id'],
                $data['product_id'],
                $variationId,
                max(0, (int) $data['quantity']),
                $data['description'] ?? 'Stock count',
            )
            : $this->inventory->adjust(
                $data['branch_id'],
                $data['product_id'],
                $variationId,
                (int) $data['quantity'],
                $data['remark'] ?? 'manual_adjustment',
                $data['description'] ?? null,
            );

        foreach (['min_stock_quantity', 'shelf_location', 'cost_price'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $row->{$field} = $data[$field];
            }
        }

        $row->save();

        if ($row->isLow()) {
            $this->notifications->lowStockAlert($row->load('product', 'branch'));
        }

        $this->audit->log(
            'inventory.adjusted',
            $row,
            newValues: ['stock_quantity' => $row->stock_quantity],
            description: "Stock adjusted for product #{$data['product_id']}",
            branchId: (int) $data['branch_id'],
        );

        return responseSuccess('inventory_adjusted', 'Stock updated', [
            'inventory' => $row->fresh(),
        ]);
    }

    /** Movement history for a branch/product. */
    public function history(Request $request)
    {
        $query = StockLog::query()
            ->with(['product:id,name,sku', 'branch:id,name', 'order:id,order_number'])
            ->when($request->query('product_id'), fn ($q, $id) => $q->where('product_id', $id))
            ->when($request->query('branch_id'), function ($q, $branchId) {
                $this->authorizeBranch((int) $branchId);
                $q->where('branch_id', $branchId);
            })
            ->when($request->query('remark'), fn ($q, $remark) => $q->where('remark', $remark))
            ->latest('id');

        $this->scopeBranch($query);

        $logs = $query->paginate(getPaginate(25));

        return responseSuccess('inventory_history', 'Inventory history fetched', [
            'logs' => collect($logs->items())->map(fn (StockLog $log) => [
                'id' => $log->id,
                'branch' => $log->branch?->name,
                'product' => $log->product?->name,
                'sku' => $log->product?->sku,
                'variation_id' => (int) $log->product_variation_id,
                'order_number' => $log->order?->order_number,
                'change_quantity' => (int) $log->change_quantity,
                'post_quantity' => (int) $log->post_quantity,
                'remark' => $log->remark,
                'description' => $log->description,
                'actor_name' => $log->actor_name,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ *
     | Transfers
     * ------------------------------------------------------------------ */

    public function transfers(Request $request)
    {
        $query = StockTransfer::query()
            ->with(['fromBranch:id,name', 'toBranch:id,name', 'items.product:id,name,sku', 'requester:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->integer('status')))
            ->latest('id');

        // Staff see transfers touching their branch, in either direction.
        $branchIds = $this->visibleBranchIds();

        if ($branchIds !== null) {
            $query->where(fn ($q) => $q
                ->whereIn('from_branch_id', $branchIds ?: [0])
                ->orWhereIn('to_branch_id', $branchIds ?: [0]));
        }

        $transfers = $query->paginate(getPaginate(20));

        return responseSuccess('stock_transfers', 'Transfers fetched', [
            'transfers' => collect($transfers->items())->map(fn (StockTransfer $t) => [
                'id' => $t->id,
                'reference' => $t->reference,
                'from_branch' => $t->fromBranch?->name,
                'from_branch_id' => $t->from_branch_id,
                'to_branch' => $t->toBranch?->name,
                'to_branch_id' => $t->to_branch_id,
                'status' => (int) $t->status,
                'status_label' => $t->status_label,
                'requested_by' => $t->requester?->name,
                'note' => $t->note,
                'items' => $t->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->name,
                    'sku' => $item->product?->sku,
                    'variation_id' => (int) $item->variation_id,
                    'quantity' => (int) $item->quantity,
                    'received_quantity' => (int) $item->received_quantity,
                ])->values(),
                'dispatched_at' => $t->dispatched_at?->toIso8601String(),
                'received_at' => $t->received_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
            ])->values(),
            'pagination' => [
                'current_page' => $transfers->currentPage(),
                'last_page' => $transfers->lastPage(),
                'total' => $transfers->total(),
            ],
        ]);
    }

    public function createTransfer(Request $request)
    {
        $data = $request->validate([
            'from_branch_id' => ['required', 'integer', 'exists:branches,id', 'different:to_branch_id'],
            'to_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variation_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        // Stock can only be sent out of a branch the caller belongs to.
        $this->authorizeBranch((int) $data['from_branch_id']);

        $transfer = DB::transaction(function () use ($data) {
            $transfer = StockTransfer::create([
                'reference' => 'TR-' . strtoupper(Str::random(8)),
                'from_branch_id' => $data['from_branch_id'],
                'to_branch_id' => $data['to_branch_id'],
                'status' => Status::TRANSFER_PENDING,
                'requested_by' => $this->admin()->id,
                'note' => $data['note'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $transfer->items()->create([
                    'product_id' => $item['product_id'],
                    'variation_id' => $item['variation_id'] ?? 0,
                    'quantity' => $item['quantity'],
                ]);
            }

            return $transfer;
        });

        $this->audit->log(
            'stock_transfer.created',
            $transfer,
            description: "Transfer {$transfer->reference} created",
            branchId: (int) $data['from_branch_id'],
        );

        $this->notifications->toStaff(
            "Incoming stock transfer {$transfer->reference}",
            "/admin/inventory/transfers/{$transfer->id}",
            (int) $data['to_branch_id'],
        );

        return responseSuccess('transfer_created', 'Stock transfer created', [
            'transfer' => $transfer->load('items'),
        ]);
    }

    public function dispatchTransfer(int $id)
    {
        $transfer = StockTransfer::with('items')->findOrFail($id);
        $this->authorizeBranch($transfer->from_branch_id);

        $transfer = $this->inventory->dispatchTransfer($transfer, $this->admin()->id);

        return responseSuccess('transfer_dispatched', 'Transfer dispatched', ['transfer' => $transfer]);
    }

    public function receiveTransfer(Request $request, int $id)
    {
        $data = $request->validate([
            'received' => ['nullable', 'array'],
            'received.*' => ['integer', 'min:0'],
        ]);

        $transfer = StockTransfer::with('items')->findOrFail($id);
        $this->authorizeBranch($transfer->to_branch_id);

        $transfer = $this->inventory->receiveTransfer($transfer, $this->admin()->id, $data['received'] ?? []);

        return responseSuccess('transfer_received', 'Transfer received', ['transfer' => $transfer]);
    }

    public function cancelTransfer(int $id)
    {
        $transfer = StockTransfer::findOrFail($id);
        $this->authorizeBranch($transfer->from_branch_id);

        if ((int) $transfer->status !== Status::TRANSFER_PENDING) {
            return responseError('cannot_cancel', ['Only a pending transfer can be cancelled']);
        }

        $transfer->update(['status' => Status::TRANSFER_CANCELLED]);

        $this->audit->log('stock_transfer.cancelled', $transfer, description: "Transfer {$transfer->reference} cancelled", branchId: $transfer->from_branch_id);

        return responseSuccess('transfer_cancelled', 'Transfer cancelled');
    }

    /** Products with no inventory row at a branch yet, for the "add stock" form. */
    public function assignableProducts(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'search' => ['nullable', 'string', 'max:191'],
        ]);

        $this->authorizeBranch((int) $data['branch_id']);

        $products = Product::query()
            ->with('variations:id,product_id,sku,attribute_values', 'media')
            ->when($data['search'] ?? null, fn ($q, $s) => $q
                ->where('name', 'like', "%$s%")
                ->orWhere('sku', 'like', "%$s%"))
            ->limit(25)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'image' => fileUrl('product', $p->main_image, true),
                'product_type' => $p->product_type,
                'variations' => $p->variations->map(fn ($v) => [
                    'id' => $v->id,
                    'label' => $v->name,
                    'sku' => $v->sku,
                ])->values(),
            ]);

        return responseSuccess('assignable_products', 'Products fetched', ['products' => $products]);
    }

    private function summary(): array
    {
        $query = BranchInventory::query();
        $this->scopeBranch($query);

        return [
            'items' => (clone $query)->count(),
            'total_units' => (int) (clone $query)->sum('stock_quantity'),
            'reserved_units' => (int) (clone $query)->sum('reserved_quantity'),
            'low_stock' => (clone $query)->lowStock()->count(),
            'out_of_stock' => (clone $query)->outOfStock()->count(),
            'stock_value' => (float) (clone $query)->selectRaw('SUM(stock_quantity * cost_price) as v')->value('v'),
        ];
    }
}
