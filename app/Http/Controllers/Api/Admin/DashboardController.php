<?php

namespace App\Http\Controllers\Api\Admin;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Admin;
use App\Models\AdminNotification;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Deposit;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\SupportTicket;
use App\Models\User;
use App\Traits\ScopesToBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One dashboard endpoint serving both the super admin (company-wide) and the
 * branch dashboards (scoped). The response shape is identical so the same
 * screen renders for every role, exactly as the source system's single
 * dashboard did.
 */
class DashboardController extends Controller
{
    use ScopesToBranch;

    public function index(Request $request)
    {
        $admin = $this->admin();
        $branchIds = $this->visibleBranchIds();
        $isCompanyWide = $branchIds === null;

        $orders = fn () => Order::query()->when(! $isCompanyWide, fn ($q) => $q->whereIn('branch_id', $branchIds ?: [0]));
        $inventory = fn () => BranchInventory::query()->when(! $isCompanyWide, fn ($q) => $q->whereIn('branch_id', $branchIds ?: [0]));

        $revenue = fn ($query) => (float) $query->where('payment_status', Status::PAYMENT_SUCCESS)->sum('total');

        $widgets = [
            'orders_total' => $orders()->count(),
            'orders_pending' => $orders()->pending()->count(),
            'orders_processing' => $orders()->processing()->count(),
            'orders_dispatched' => $orders()->dispatched()->count(),
            'orders_delivered' => $orders()->delivered()->count(),
            'orders_cancelled' => $orders()->cancelled()->count(),
            'orders_returned' => $orders()->returned()->count(),
            'orders_cod' => $orders()->cod()->count(),
            'revenue_total' => $revenue($orders()),
            'revenue_today' => $revenue($orders()->whereDate('created_at', today())),
            'revenue_month' => $revenue($orders()->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])),
            'orders_today' => $orders()->whereDate('created_at', today())->count(),
            'products_total' => Product::count(),
            'products_active' => Product::where('status', Status::ENABLE)->count(),
            'low_stock_items' => $inventory()->lowStock()->count(),
            'out_of_stock_items' => $inventory()->outOfStock()->count(),
            'pending_reviews' => ProductReview::pending()->count(),
            'open_tickets' => SupportTicket::whereIn('status', [Status::TICKET_OPEN, Status::TICKET_REPLY])->count(),
            'pending_deposits' => Deposit::pending()->count(),
        ];

        if ($isCompanyWide) {
            $widgets += [
                'branches_total' => Branch::count(),
                'branches_active' => Branch::where('status', Status::ENABLE)->count(),
                'managers_total' => Admin::role(\App\Constants\Roles::BRANCH_MANAGER, 'admin')->count(),
                'workers_total' => Admin::role(\App\Constants\Roles::BRANCH_WORKER, 'admin')->count(),
                'customers_total' => User::count(),
                'customers_active' => User::active()->count(),
            ];
        } else {
            $branchId = $branchIds[0] ?? 0;

            $widgets += [
                'branch_staff' => Admin::where('branch_id', $branchId)->count(),
                'branch_inventory_items' => $inventory()->count(),
                'customers_total' => User::whereHas('orders', fn ($q) => $q->where('branch_id', $branchId))->count(),
            ];
        }

        return responseSuccess('dashboard', 'Dashboard fetched', [
            'scope' => $isCompanyWide ? 'company' : 'branch',
            'branch' => $admin->branch ? [
                'id' => $admin->branch->id,
                'name' => $admin->branch->name,
                'code' => $admin->branch->code,
            ] : null,
            'widgets' => $widgets,
            'sales_chart' => $this->salesChart($branchIds),
            'order_status_chart' => $this->orderStatusChart($branchIds),
            'top_products' => $this->topProducts($branchIds),
            'recent_orders' => OrderResource::collection(
                $orders()->with('user', 'branch', 'orderItems.product.media', 'orderItems.variation')->latest('id')->limit(8)->get()
            ),
            'low_stock' => $inventory()
                ->lowStock()
                ->with('product:id,name,slug,sku', 'branch:id,name')
                ->orderBy('stock_quantity')
                ->limit(10)
                ->get()
                ->map(fn ($row) => [
                    'product_id' => $row->product_id,
                    'product_name' => $row->product?->name,
                    'sku' => $row->product?->sku,
                    'branch' => $row->branch?->name,
                    'stock_quantity' => (int) $row->stock_quantity,
                    'min_stock_quantity' => (int) $row->min_stock_quantity,
                ])->values(),
            'notifications' => AdminNotification::query()
                ->when(! $isCompanyWide, fn ($q) => $q->whereIn('branch_id', $branchIds ?: [0]))
                ->where('is_read', 0)
                ->latest('id')
                ->limit(10)
                ->get(),
            'recent_activity' => AuditLog::query()
                ->when(! $isCompanyWide, fn ($q) => $q->whereIn('branch_id', $branchIds ?: [0]))
                ->latest('id')
                ->limit(10)
                ->get(['id', 'event', 'actor_name', 'description', 'created_at']),
            'branch_summary' => $isCompanyWide ? $this->branchSummary() : null,
        ]);
    }

    /** Revenue and order count per day for the last 30 days. */
    private function salesChart(?array $branchIds): array
    {
        $rows = Order::query()
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds ?: [0]))
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as orders, SUM(CASE WHEN payment_status = ? THEN total ELSE 0 END) as revenue', [Status::PAYMENT_SUCCESS])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $series = [];

        for ($i = 29; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $row = $rows->get($day);

            $series[] = [
                'date' => $day,
                'orders' => (int) ($row->orders ?? 0),
                'revenue' => (float) ($row->revenue ?? 0),
            ];
        }

        return $series;
    }

    private function orderStatusChart(?array $branchIds): array
    {
        $counts = Order::query()
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds ?: [0]))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(Status::ORDER_STATUS_LABELS)
            ->map(fn ($label, $status) => [
                'status' => $status,
                'label' => $label,
                'total' => (int) ($counts[$status] ?? 0),
            ])
            ->values()
            ->all();
    }

    private function topProducts(?array $branchIds): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('orders.branch_id', $branchIds ?: [0]))
            ->where('orders.status', Status::ORDER_DELIVERED)
            ->selectRaw('products.id, products.name, products.slug, SUM(order_items.quantity) as sold, SUM(order_items.subtotal) as revenue')
            ->groupBy('products.id', 'products.name', 'products.slug')
            ->orderByDesc('sold')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'slug' => $row->slug,
                'sold' => (int) $row->sold,
                'revenue' => (float) $row->revenue,
            ])
            ->all();
    }

    private function branchSummary(): array
    {
        return Branch::query()
            ->orderBy('name')
            ->get()
            ->map(function (Branch $branch) {
                $orders = Order::where('branch_id', $branch->id);

                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'code' => $branch->code,
                    'city' => $branch->city,
                    'status' => (bool) $branch->status,
                    'orders' => (clone $orders)->count(),
                    'revenue' => (float) (clone $orders)->where('payment_status', Status::PAYMENT_SUCCESS)->sum('total'),
                    'staff' => Admin::where('branch_id', $branch->id)->count(),
                    'low_stock' => BranchInventory::where('branch_id', $branch->id)->lowStock()->count(),
                ];
            })
            ->all();
    }
}
