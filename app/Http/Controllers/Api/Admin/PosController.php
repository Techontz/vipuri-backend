<?php

namespace App\Http\Controllers\Api\Admin;

use App\Constants\Status;
use App\Exceptions\PosPermissionException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use App\Services\PosService;
use App\Traits\ScopesToBranch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Point of sale: counter sales from a branch's stock.
 *
 * Branch staff always sell from their own branch; company-wide staff choose
 * one. Every endpoint checks that on the server — the till's branch picker is
 * a convenience, not the control.
 */
class PosController extends Controller
{
    use ScopesToBranch;

    public function __construct(private readonly PosService $pos) {}

    /** Search the catalogue for the till, with free stock at the branch. */
    public function products(Request $request)
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $branch = $this->sellingBranch($request->integer('branch_id') ?: null, required: false);
        $search = trim((string) $request->query('search', ''));

        $query = Product::active()
            ->whereNotIn('product_type', [Status::PRODUCT_GROUPED, Status::PRODUCT_EXTERNAL])
            ->with(['tax', 'media', 'categories:id', 'offers', 'campaigns', 'variations', 'stockUnit'])
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';

                $q->where(function ($inner) use ($like, $search) {
                    $inner->where('products.name', 'like', $like)
                        ->orWhere('products.sku', 'like', $like)
                        ->orWhere('products.gtin', $search)
                        ->orWhereHas('variations', fn ($v) => $v->where('sku', 'like', $like));
                });
            });

        if ($branch) {
            // Parts on the shelf at this branch first, then the rest.
            $query->withSum(
                ['branchInventories as branch_stock' => fn ($q) => $q->where('branch_id', $branch->id)],
                'stock_quantity',
            )->orderByDesc('branch_stock');
        }

        $products = $query->orderBy('products.name')->paginate(min(getPaginate(20), 50));

        $free = $branch ? $this->freeStock($branch->id, collect($products->items())->pluck('id')->all()) : [];

        return responseSuccess('pos_products', 'Products fetched', [
            'products' => collect($products->items())->map(fn (Product $product) => $this->productRow($product, $free))->values(),
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ],
            'context' => $this->context($branch),
        ]);
    }

    /** Registered customers, for attaching a counter sale to an account. */
    public function customers(Request $request)
    {
        $request->validate(['search' => ['nullable', 'string', 'max:120']]);
        $search = trim((string) $request->query('search', ''));

        $customers = User::query()
            ->active()
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';

                $q->where(fn ($inner) => $inner->where('firstname', 'like', $like)
                    ->orWhere('lastname', 'like', $like)
                    ->orWhereRaw("CONCAT(COALESCE(firstname, ''), ' ', COALESCE(lastname, '')) like ?", [$like])
                    ->orWhere('email', 'like', $like)
                    ->orWhere('mobile', 'like', $like)
                    ->orWhere('username', 'like', $like));
            })
            ->orderBy('firstname')
            ->limit(10)
            ->get(['id', 'firstname', 'lastname', 'email', 'dial_code', 'mobile']);

        return responseSuccess('pos_customers', 'Customers fetched', [
            'customers' => $customers->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->fullname,
                'email' => $user->email,
                'mobile' => $user->mobile,
            ])->values(),
        ]);
    }

    /** Ring up a counter sale. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'customer' => ['nullable', 'array'],
            'customer.user_id' => ['nullable', 'integer'],
            'customer.name' => ['nullable', 'string', 'max:120'],
            'customer.mobile' => ['nullable', 'string', 'max:40'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variation_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', 'string', Rule::in(array_keys(Order::PAYMENT_METHODS))],
            'amount_received' => ['nullable', 'numeric', 'min:0', 'required_if:payment_method,cash'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'amount_received.required_if' => 'Enter the cash received from the customer',
            'items.required' => 'Add at least one item to the sale',
        ]);

        $branch = $this->sellingBranch(isset($data['branch_id']) ? (int) $data['branch_id'] : null, required: true);

        try {
            $order = $this->pos->sell($this->admin(), $branch, $data);
        } catch (PosPermissionException $e) {
            return responseError('forbidden', [$e->getMessage()], code: 403);
        } catch (RuntimeException $e) {
            return responseError('sale_failed', [$e->getMessage()]);
        }

        return responseSuccess('pos_sale_completed', "Sale {$order->order_number} completed", [
            'order' => ['id' => $order->id, 'order_number' => $order->order_number],
            'receipt' => $this->pos->receipt($order),
        ], 201);
    }

    /** Counter sales, newest first, with the day's takings. */
    public function index(Request $request)
    {
        $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:120'],
            'mine' => ['nullable', 'boolean'],
        ]);

        $branchId = $request->integer('branch_id') ?: null;

        if ($branchId) {
            $this->authorizeBranch($branchId);
        }

        $base = fn () => $this->scopeBranch(Order::query()->pos())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->boolean('mine'), fn ($q) => $q->where('sold_by', $this->admin()->id));

        $day = $request->date('date') ?? now();

        $sales = $base()
            ->with(['branch:id,name,code', 'soldBy:id,name', 'user:id,firstname,lastname,mobile'])
            ->withCount('orderItems')
            ->when($request->date('date'), fn ($q, $date) => $q->whereDate('created_at', $date))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($inner) => $inner
                ->where('order_number', 'like', "%$s%")
                ->orWhere('payment_reference', 'like', "%$s%")
                ->orWhere('shipping_address', 'like', "%$s%")))
            ->latest('id')
            ->paginate(getPaginate(20));

        // Takings: paid sales still standing — a returned sale is money given back.
        $takings = $base()
            ->whereDate('created_at', $day)
            ->where('payment_status', Status::PAYMENT_SUCCESS)
            ->whereNotIn('status', [Status::ORDER_RETURNED, Status::ORDER_CANCELLED]);

        $byMethod = (clone $takings)
            ->selectRaw('payment_method, COUNT(*) as sales, SUM(total) as revenue')
            ->groupBy('payment_method')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->payment_method => [
                'label' => Order::PAYMENT_METHODS[$row->payment_method] ?? $row->payment_method,
                'count' => (int) $row->sales,
                'total' => round((float) $row->revenue, 2),
            ]]);

        return responseSuccess('pos_sales', 'Counter sales fetched', [
            'sales' => collect($sales->items())->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'created_at' => $order->created_at?->toIso8601String(),
                'branch' => $order->branch?->name,
                'seller' => $order->soldBy?->name,
                'customer' => $order->user?->fullname ?? ($order->shipping_address->name ?? 'Walk-in customer'),
                'items_count' => (int) $order->order_items_count,
                'total' => (float) $order->total,
                'payment_method' => $order->payment_method,
                'payment_method_label' => $order->payment_method_label,
                'status' => (int) $order->status,
                'status_label' => $order->status_label,
            ])->values(),
            'pagination' => [
                'current_page' => $sales->currentPage(),
                'last_page' => $sales->lastPage(),
                'total' => $sales->total(),
            ],
            'summary' => [
                'date' => $day->toDateString(),
                'count' => (clone $takings)->count(),
                'revenue' => round((float) (clone $takings)->sum('total'), 2),
                'tax' => round((float) (clone $takings)->sum('total_tax'), 2),
                'by_payment_method' => $byMethod,
            ],
        ]);
    }

    /** A counter sale's receipt. */
    public function show(int $id)
    {
        $order = Order::pos()->findOrFail($id);

        $this->authorizeBranch($order->branch_id);

        return responseSuccess('pos_sale', 'Counter sale fetched', [
            'receipt' => $this->pos->receipt($order),
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * The branch a till sells from: the seller's own, or — for company-wide
     * staff — the one they picked (their own / the default when browsing).
     */
    private function sellingBranch(?int $requested, bool $required): ?Branch
    {
        $admin = $this->admin();

        if (! $admin->isCompanyWide()) {
            if (! $admin->branch_id) {
                abort(response()->json([
                    'remark' => 'no_branch', 'status' => 'error',
                    'message' => ['error' => ['Your account is not assigned to a branch, so you cannot sell at the counter']],
                    'data' => [],
                ], 422));
            }

            if ($requested && $requested !== (int) $admin->branch_id) {
                abort(403, 'You can only sell from your own branch');
            }

            return Branch::findOrFail($admin->branch_id);
        }

        if ($requested) {
            return Branch::active()->find($requested) ?? abort(response()->json([
                'remark' => 'invalid_branch', 'status' => 'error',
                'message' => ['error' => ['That branch is not available']],
                'data' => [],
            ], 422));
        }

        if ($required) {
            abort(response()->json([
                'remark' => 'branch_required', 'status' => 'error',
                'message' => ['error' => ['Choose the branch this sale is made from']],
                'data' => ['errors' => ['branch_id' => ['Choose the branch this sale is made from']]],
            ], 422));
        }

        return ($admin->branch_id ? Branch::active()->find($admin->branch_id) : null) ?? Branch::fallback();
    }

    /** What the till needs to know about who is selling and where. */
    private function context(?Branch $branch): array
    {
        $admin = $this->admin();

        return [
            'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name, 'code' => $branch->code] : null,
            'company_wide' => $admin->isCompanyWide(),
            'can_discount' => $admin->can('pos.discount'),
            'branches' => $admin->isCompanyWide()
                ? Branch::active()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'code'])
                : [],
        ];
    }

    /**
     * Free stock per "productId:variationId" at a branch for a page of products.
     *
     * @return array<string, int>
     */
    private function freeStock(int $branchId, array $productIds): array
    {
        return BranchInventory::query()
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $productIds ?: [0])
            ->get(['product_id', 'variation_id', 'stock_quantity', 'reserved_quantity'])
            ->mapWithKeys(fn ($row) => [
                $row->product_id . ':' . (int) $row->variation_id => max(0, (int) $row->stock_quantity - (int) $row->reserved_quantity),
            ])
            ->all();
    }

    private function productRow(Product $product, array $free): array
    {
        $quote = $this->pos->quote($product);
        $isVariable = $product->isVariable();

        $variations = $isVariable
            ? $product->variations->map(function (ProductVariation $variation) use ($product, $free) {
                $q = $this->pos->quote($product, $variation);

                return [
                    'id' => $variation->id,
                    'name' => $variation->name ?: ('Option #' . $variation->id),
                    'sku' => $variation->sku,
                    'price' => $q['price'],
                    'original_price' => $q['original_price'],
                    'tax_amount' => $q['tax_amount'],
                    'price_with_tax' => $q['price_with_tax'],
                    'track_inventory' => $variation->trackInventory(),
                    'available' => $free[$product->id . ':' . $variation->id] ?? 0,
                ];
            })->values()->all()
            : [];

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'gtin' => $product->gtin,
            'image' => fileUrl('product', $product->main_image, true),
            'product_type' => $product->product_type,
            'unit' => $product->stockUnit?->name,
            'price' => $quote['price'],
            'original_price' => $quote['original_price'],
            'tax_name' => $quote['tax_name'],
            'tax_rate' => $quote['tax_rate'],
            'tax_amount' => $quote['tax_amount'],
            'price_with_tax' => $quote['price_with_tax'],
            'track_inventory' => $product->trackInventory(),
            'available' => $isVariable
                ? array_sum(array_column($variations, 'available'))
                : ($free[$product->id . ':0'] ?? 0),
            'variations' => $variations,
        ];
    }
}
