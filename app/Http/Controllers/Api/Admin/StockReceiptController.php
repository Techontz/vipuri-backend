<?php

namespace App\Http\Controllers\Api\Admin;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use App\Services\AuditService;
use App\Services\InventoryService;
use App\Traits\ScopesToBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Receive stock (goods received notes).
 *
 * Staff book a supplier delivery into one branch. Branch staff can only ever
 * receive into their own branch; company-wide staff must say which branch the
 * delivery belongs to. Stock moves through InventoryService::adjust(), so each
 * line leaves a `stock_receipt` movement in the ledger and the catalogue total
 * is re-synced exactly as for any other movement.
 */
class StockReceiptController extends Controller
{
    use ScopesToBranch;

    /** Stock-log remark for a goods-received movement. */
    public const REMARK = 'stock_receipt';

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request)
    {
        $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:191'],
        ]);

        $query = StockReceipt::query()
            ->with(['branch:id,name,code', 'receiver:id,name'])
            ->withCount('items')
            ->withSum('items', 'quantity')
            ->when($request->query('branch_id'), function ($q, $branchId) {
                $this->authorizeBranch((int) $branchId);
                $q->where('branch_id', $branchId);
            })
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('reference', 'like', "%$s%")
                ->orWhere('supplier_name', 'like', "%$s%")
                ->orWhere('supplier_reference', 'like', "%$s%")))
            ->latest('received_at')
            ->latest('id');

        $this->scopeBranch($query);

        $receipts = $query->paginate(getPaginate(20));

        return responseSuccess('stock_receipts', 'Stock receipts fetched', [
            'receipts' => collect($receipts->items())->map(fn (StockReceipt $r) => $this->summaryRow($r))->values(),
            'pagination' => [
                'current_page' => $receipts->currentPage(),
                'last_page' => $receipts->lastPage(),
                'total' => $receipts->total(),
            ],
        ]);
    }

    public function show(int $id)
    {
        return responseSuccess('stock_receipt', 'Stock receipt fetched', [
            'receipt' => $this->detail($id),
        ]);
    }

    /** A receipt with its lines, after checking the caller may see its branch. */
    private function detail(int $id): array
    {
        $receipt = StockReceipt::with([
            'branch:id,name,code',
            'receiver:id,name',
            'items.product:id,name,sku,product_type',
            'items.product.media',
            'items.variation',
        ])
            ->withCount('items')
            ->withSum('items', 'quantity')
            ->findOrFail($id);

        $this->authorizeBranch($receipt->branch_id);

        $onHand = BranchInventory::query()
            ->where('branch_id', $receipt->branch_id)
            ->whereIn('product_id', $receipt->items->pluck('product_id'))
            ->get(['product_id', 'variation_id', 'stock_quantity'])
            ->keyBy(fn ($row) => $row->product_id . ':' . (int) $row->variation_id);

        return array_merge($this->summaryRow($receipt), [
            'items' => $receipt->items->map(fn (StockReceiptItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'sku' => ($item->variation_id ? $item->variation?->sku : null) ?: $item->product?->sku,
                'image' => fileUrl('product', $item->product?->main_image, true),
                'variation_id' => (int) $item->variation_id,
                'variation_label' => $item->variation_id ? $item->variation?->name : null,
                'quantity' => (int) $item->quantity,
                'unit_cost' => $item->unit_cost,
                'line_cost' => (float) $item->line_cost,
                'current_stock' => (int) ($onHand[$item->product_id . ':' . (int) $item->variation_id]->stock_quantity ?? 0),
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'supplier_name' => ['nullable', 'string', 'max:191'],
            'supplier_reference' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:2000'],
            'received_at' => ['nullable', 'date', 'before_or_equal:' . now()->addDay()->toDateTimeString()],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variation_id' => ['nullable', 'integer', 'min:0'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ], [
            'items.required' => 'Add at least one product to receive',
            'items.*.quantity.min' => 'Quantity must be at least 1',
            'items.*.product_id.exists' => 'One of the products no longer exists',
        ]);

        $branchId = $this->targetBranch($data['branch_id'] ?? null);
        $branch = Branch::findOrFail($branchId);
        $lines = $this->validatedLines($data['items']);
        $receivedAt = $this->receivedAt($data['received_at'] ?? null);
        $supplier = trim((string) ($data['supplier_name'] ?? '')) ?: null;

        $receipt = DB::transaction(function () use ($data, $branch, $lines, $receivedAt, $supplier) {
            $receipt = StockReceipt::create([
                'company_id' => $branch->company_id ?? Company::current()->id,
                'branch_id' => $branch->id,
                'supplier_name' => $supplier,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'note' => $data['note'] ?? null,
                'received_by' => $this->admin()->id,
                'received_at' => $receivedAt,
                'total_cost' => 0,
            ]);

            $receipt->reference = StockReceipt::makeReference($receipt->id, $branch->code);

            $description = Str::limit(
                "Received {$receipt->reference}" . ($supplier ? " from {$supplier}" : '')
                . (! empty($data['supplier_reference']) ? " (inv. {$data['supplier_reference']})" : ''),
                250,
            );

            $total = 0.0;

            foreach ($lines as $line) {
                $lineCost = $line['unit_cost'] !== null ? round($line['unit_cost'] * $line['quantity'], 2) : 0.0;
                $total += $lineCost;

                $receipt->items()->create([
                    'product_id' => $line['product_id'],
                    'variation_id' => $line['variation_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'line_cost' => $lineCost,
                ]);

                // adjust() creates the branch row on first receipt, writes the
                // ledger entry and re-syncs the catalogue total.
                $row = $this->inventory->adjust(
                    $branch->id,
                    $line['product_id'],
                    $line['variation_id'],
                    $line['quantity'],
                    self::REMARK,
                    $description,
                );

                if ($line['unit_cost'] !== null) {
                    $row->cost_price = $line['unit_cost'];
                    $row->save();
                }
            }

            $receipt->total_cost = $total;
            $receipt->save();

            return $receipt;
        });

        $this->audit->log(
            'stock_receipt.created',
            $receipt,
            newValues: [
                'reference' => $receipt->reference,
                'lines' => count($lines),
                'units' => array_sum(array_column($lines, 'quantity')),
                'total_cost' => $receipt->total_cost,
            ],
            description: "Stock received {$receipt->reference} into {$branch->name}" . ($supplier ? " from {$supplier}" : ''),
            branchId: $branch->id,
        );

        return responseSuccess('stock_received', "Stock received — {$receipt->reference}", [
            'receipt' => $this->detail($receipt->id),
        ], 201);
    }

    /* ------------------------------------------------------------------ *
     | Helpers
     * ------------------------------------------------------------------ */

    /**
     * Branch staff always receive into their own branch (a different branch
     * in the request is refused, not silently swapped). Company-wide staff
     * must name one.
     */
    private function targetBranch(?int $requested): int
    {
        $admin = $this->admin();

        if (! $admin->isCompanyWide()) {
            if ($requested !== null) {
                $this->authorizeBranch($requested);
            }

            $branchId = $this->resolveBranchId($requested);

            if (! $branchId) {
                abort(403, 'Your account is not assigned to a branch');
            }

            return $branchId;
        }

        if (! $requested) {
            throw ValidationException::withMessages([
                'branch_id' => 'Choose the branch this stock is being received into',
            ]);
        }

        return $requested;
    }

    /**
     * Products must be physical stock: not grouped bundles or external
     * listings. A variable product needs one of its own variations; a simple
     * product takes none.
     *
     * @return array<int, array{product_id:int, variation_id:int, quantity:int, unit_cost:?float}>
     */
    private function validatedLines(array $items): array
    {
        $products = Product::query()
            ->whereIn('id', collect($items)->pluck('product_id')->unique())
            ->get(['id', 'name', 'product_type'])
            ->keyBy('id');

        $variations = ProductVariation::query()
            ->whereIn('id', collect($items)->pluck('variation_id')->filter()->unique())
            ->get(['id', 'product_id'])
            ->keyBy('id');

        $errors = [];
        $lines = [];

        foreach ($items as $index => $item) {
            $product = $products[(int) $item['product_id']] ?? null;
            $variationId = (int) ($item['variation_id'] ?? 0);

            if (! $product) {
                $errors["items.$index.product_id"] = 'One of the products no longer exists';
                continue;
            }

            if (in_array($product->product_type, [Status::PRODUCT_GROUPED, Status::PRODUCT_EXTERNAL], true)) {
                $errors["items.$index.product_id"] = "\"{$product->name}\" is not a stocked item and cannot be received";
                continue;
            }

            if ($variationId) {
                $variation = $variations[$variationId] ?? null;

                if (! $variation || (int) $variation->product_id !== (int) $product->id) {
                    $errors["items.$index.variation_id"] = "That variation does not belong to \"{$product->name}\"";
                    continue;
                }
            } elseif ($product->product_type === Status::PRODUCT_VARIABLE) {
                $errors["items.$index.variation_id"] = "Choose which variation of \"{$product->name}\" was received";
                continue;
            }

            $lines[] = [
                'product_id' => (int) $product->id,
                'variation_id' => $variationId,
                'quantity' => (int) $item['quantity'],
                'unit_cost' => isset($item['unit_cost']) && $item['unit_cost'] !== '' ? (float) $item['unit_cost'] : null,
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }

    /** A bare date keeps the current time of day, so same-day receipts still sort in order. */
    private function receivedAt(?string $value): Carbon
    {
        if (! $value) {
            return now();
        }

        $parsed = Carbon::parse($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            $parsed = $parsed->isToday() ? now() : $parsed->setTimeFrom(now());
        }

        return $parsed;
    }

    private function summaryRow(StockReceipt $r): array
    {
        return [
            'id' => $r->id,
            'reference' => $r->reference,
            'branch_id' => $r->branch_id,
            'branch_name' => $r->branch?->name,
            'branch_code' => $r->branch?->code,
            'supplier_name' => $r->supplier_name,
            'supplier_reference' => $r->supplier_reference,
            'note' => $r->note,
            'received_by' => $r->receiver?->name,
            'received_at' => $r->received_at?->toIso8601String(),
            'item_count' => (int) ($r->items_count ?? 0),
            'total_quantity' => (int) ($r->items_sum_quantity ?? 0),
            'total_cost' => (float) $r->total_cost,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
