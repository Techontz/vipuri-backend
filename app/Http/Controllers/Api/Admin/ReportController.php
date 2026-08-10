<?php

namespace App\Http\Controllers\Api\Admin;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\UserLogin;
use App\Traits\ScopesToBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reporting. Every figure is derived from live data and honours branch scope.
 */
class ReportController extends Controller
{
    use ScopesToBranch;

    public function sales(Request $request)
    {
        [$from, $to] = $this->range($request);
        $branchIds = $this->visibleBranchIds();

        $orders = fn () => Order::query()
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds ?: [0]))
            ->when($request->query('branch_id'), function ($q, $branchId) {
                $this->authorizeBranch((int) $branchId);
                $q->where('branch_id', $branchId);
            })
            ->whereBetween('created_at', [$from, $to]);

        $paid = fn () => $orders()->where('payment_status', Status::PAYMENT_SUCCESS);

        $daily = $orders()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as orders, SUM(total) as gross, SUM(CASE WHEN payment_status = ? THEN total ELSE 0 END) as revenue, SUM(discount) as discount, SUM(total_tax) as tax, SUM(shipping_charge) as shipping', [Status::PAYMENT_SUCCESS])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->day,
                'orders' => (int) $row->orders,
                'gross' => (float) $row->gross,
                'revenue' => (float) $row->revenue,
                'discount' => (float) $row->discount,
                'tax' => (float) $row->tax,
                'shipping' => (float) $row->shipping,
            ]);

        $byBranch = Order::query()
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds ?: [0]))
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('branch_id, COUNT(*) as orders, SUM(CASE WHEN payment_status = ? THEN total ELSE 0 END) as revenue', [Status::PAYMENT_SUCCESS])
            ->groupBy('branch_id')
            ->get()
            ->map(fn ($row) => [
                'branch_id' => $row->branch_id,
                'branch' => Branch::find($row->branch_id)?->name ?? 'Unassigned',
                'orders' => (int) $row->orders,
                'revenue' => (float) $row->revenue,
            ]);

        $topProducts = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('orders.branch_id', $branchIds ?: [0]))
            ->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw('products.id, products.name, products.sku, SUM(order_items.quantity) as sold, SUM(order_items.subtotal) as revenue')
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('revenue')
            ->limit(20)
            ->get();

        return responseSuccess('sales_report', 'Sales report generated', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => [
                'orders' => $orders()->count(),
                'gross' => (float) $orders()->sum('total'),
                'revenue' => (float) $paid()->sum('total'),
                'discount' => (float) $orders()->sum('discount'),
                'tax' => (float) $orders()->sum('total_tax'),
                'shipping' => (float) $orders()->sum('shipping_charge'),
                'delivered' => $orders()->delivered()->count(),
                'cancelled' => $orders()->cancelled()->count(),
                'returned' => $orders()->returned()->count(),
                'average_order_value' => (float) ($paid()->avg('total') ?? 0),
            ],
            'daily' => $daily,
            'by_branch' => $byBranch,
            'top_products' => $topProducts,
        ]);
    }

    public function inventory(Request $request)
    {
        $query = BranchInventory::query()
            ->with('product:id,name,sku', 'branch:id,name')
            ->when($request->query('branch_id'), function ($q, $branchId) {
                $this->authorizeBranch((int) $branchId);
                $q->where('branch_id', $branchId);
            });

        $this->scopeBranch($query);

        $rows = (clone $query)
            ->orderBy('stock_quantity')
            ->limit(500)
            ->get()
            ->map(fn ($row) => [
                'branch' => $row->branch?->name,
                'product' => $row->product?->name,
                'sku' => $row->product?->sku,
                'variation_id' => (int) $row->variation_id,
                'stock_quantity' => (int) $row->stock_quantity,
                'reserved_quantity' => (int) $row->reserved_quantity,
                'available' => $row->available_quantity,
                'min_stock_quantity' => (int) $row->min_stock_quantity,
                'cost_price' => (float) $row->cost_price,
                'stock_value' => (float) $row->cost_price * (int) $row->stock_quantity,
                'is_low' => $row->isLow(),
            ]);

        return responseSuccess('inventory_report', 'Inventory report generated', [
            'summary' => [
                'items' => (clone $query)->count(),
                'total_units' => (int) (clone $query)->sum('stock_quantity'),
                'low_stock' => (clone $query)->lowStock()->count(),
                'out_of_stock' => (clone $query)->outOfStock()->count(),
                'stock_value' => (float) (clone $query)->selectRaw('SUM(stock_quantity * cost_price) as v')->value('v'),
            ],
            'rows' => $rows,
        ]);
    }

    public function branchPerformance(Request $request)
    {
        [$from, $to] = $this->range($request);
        $branchIds = $this->visibleBranchIds();

        $branches = Branch::query()
            ->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds ?: [0]))
            ->orderBy('name')
            ->get()
            ->map(function (Branch $branch) use ($from, $to) {
                $orders = Order::where('branch_id', $branch->id)->whereBetween('created_at', [$from, $to]);
                $revenue = (float) (clone $orders)->where('payment_status', Status::PAYMENT_SUCCESS)->sum('total');
                $count = (clone $orders)->count();

                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'code' => $branch->code,
                    'city' => $branch->city,
                    'orders' => $count,
                    'revenue' => $revenue,
                    'average_order_value' => $count ? round($revenue / $count, 2) : 0.0,
                    'delivered' => (clone $orders)->delivered()->count(),
                    'cancelled' => (clone $orders)->cancelled()->count(),
                    'fulfilment_rate' => $count ? round((clone $orders)->delivered()->count() / $count * 100, 1) : 0.0,
                    'staff' => \App\Models\Admin::where('branch_id', $branch->id)->count(),
                    'low_stock_items' => BranchInventory::where('branch_id', $branch->id)->lowStock()->count(),
                ];
            });

        return responseSuccess('branch_performance', 'Branch performance report generated', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'branches' => $branches,
        ]);
    }

    public function loginHistory(Request $request)
    {
        $logins = UserLogin::query()
            ->when($request->query('guard'), fn ($q, $guard) => $q->where('guard', $guard))
            ->when($request->query('ip'), fn ($q, $ip) => $q->where('user_ip', $ip))
            ->latest('id')
            ->paginate(getPaginate(30));

        return responseSuccess('login_history', 'Login history fetched', [
            'logins' => collect($logins->items())->map(function (UserLogin $login) {
                $name = $login->guard === 'admin'
                    ? \App\Models\Admin::find($login->user_id)?->name
                    : \App\Models\User::find($login->user_id)?->username;

                return [
                    'id' => $login->id,
                    'guard' => $login->guard,
                    'account' => $name,
                    'ip' => $login->user_ip,
                    'browser' => $login->browser,
                    'os' => $login->os,
                    'country' => $login->country,
                    'created_at' => $login->created_at?->toIso8601String(),
                ];
            })->values(),
            'pagination' => $this->pagination($logins),
        ]);
    }

    public function notificationHistory(Request $request)
    {
        $logs = NotificationLog::query()
            ->when($request->query('type'), fn ($q, $type) => $q->where('notification_type', $type))
            ->when($request->query('search'), fn ($q, $s) => $q->where('sent_to', 'like', "%$s%")->orWhere('subject', 'like', "%$s%"))
            ->latest('id')
            ->paginate(getPaginate(30));

        return responseSuccess('notification_history', 'Notification history fetched', [
            'logs' => $logs->items(),
            'pagination' => $this->pagination($logs),
        ]);
    }

    public function auditLogs(Request $request)
    {
        $query = AuditLog::query()
            ->when($request->query('event'), fn ($q, $event) => $q->where('event', 'like', "$event%"))
            ->when($request->query('actor_type'), fn ($q, $type) => $q->where('actor_type', $type))
            ->when($request->query('search'), fn ($q, $s) => $q
                ->where('description', 'like', "%$s%")
                ->orWhere('actor_name', 'like', "%$s%"))
            ->when($request->date('from'), fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->date('to'), fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->latest('id');

        // A branch manager sees their own branch's trail only.
        $this->scopeBranch($query);

        $logs = $query->paginate(getPaginate(30));

        return responseSuccess('audit_logs', 'Audit log fetched', [
            'logs' => collect($logs->items())->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'actor_type' => $log->actor_type,
                'actor_name' => $log->actor_name,
                'branch_id' => $log->branch_id,
                'branch' => $log->branch?->name,
                'description' => $log->description,
                'auditable_type' => $log->auditable_type ? class_basename($log->auditable_type) : null,
                'auditable_id' => $log->auditable_id,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values(),
            'pagination' => $this->pagination($logs),
            'events' => AuditLog::distinct()->orderBy('event')->pluck('event'),
        ]);
    }

    private function range(Request $request): array
    {
        return [
            $request->date('from')?->startOfDay() ?? now()->subDays(29)->startOfDay(),
            $request->date('to')?->endOfDay() ?? now()->endOfDay(),
        ];
    }

    private function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }
}
