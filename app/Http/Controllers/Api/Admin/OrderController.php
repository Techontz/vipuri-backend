<?php

namespace App\Http\Controllers\Api\Admin;

use App\Constants\Roles;
use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Branch;
use App\Models\Order;
use App\Services\OrderService;
use App\Traits\ScopesToBranch;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    use ScopesToBranch;

    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request)
    {
        $query = Order::query()
            // OrderResource reads product and variation off every line, so they
            // are eager-loaded here — without them a page of orders costs one
            // query per item rather than one for the page.
            ->with(
                'user:id,firstname,lastname,email,mobile',
                'guest',
                'branch:id,name,code',
                'soldBy:id,name',
                'orderItems.product.media',
                'orderItems.variation',
            )
            // Search covers the order number and the customer, whether that
            // customer has an account or checked out as a guest. Guests were
            // previously unreachable: a phone order placed without an account
            // could only be found by its order number.
            ->when($request->query('search'), fn ($q, $s) => $q->where(function ($inner) use ($s) {
                $inner->where('order_number', 'like', "%$s%")
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%$s%")
                        ->orWhere('firstname', 'like', "%$s%")
                        ->orWhere('lastname', 'like', "%$s%")
                        ->orWhere('mobile', 'like', "%$s%"))
                    ->orWhereHas('guest', fn ($g) => $g->where('email', 'like', "%$s%")
                        ->orWhere('firstname', 'like', "%$s%")
                        ->orWhere('lastname', 'like', "%$s%")
                        ->orWhere('mobile', 'like', "%$s%"))
                    // Walk-in counter customers exist only on the order itself.
                    ->orWhere(fn ($pos) => $pos->where('channel', Order::CHANNEL_POS)
                        ->where('shipping_address', 'like', "%$s%"));
            }))
            ->when(in_array($request->query('channel'), [Order::CHANNEL_ONLINE, Order::CHANNEL_POS], true),
                fn ($q) => $q->where('channel', $request->query('channel')))
            ->when($request->query('branch_id'), function ($q, $branchId) {
                $this->authorizeBranch((int) $branchId);
                $q->where('branch_id', $branchId);
            })
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->integer('payment_status')))
            ->when($request->date('from'), fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->date('to'), fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->latest('id');

        match ($request->query('status')) {
            'pending' => $query->pending(),
            'processing' => $query->processing(),
            'dispatched' => $query->dispatched(),
            'delivered', 'completed' => $query->delivered(),
            'returned' => $query->returned(),
            'cancelled' => $query->cancelled(),
            'cod' => $query->cod(),
            'unpaid' => $query->unpaid(),
            'paid' => $query->paid(),
            default => null,
        };

        $this->scopeBranch($query);

        $orders = $query->paginate(getPaginate(20));

        return responseSuccess('orders', 'Orders fetched', [
            'orders' => OrderResource::collection($orders->items()),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
            'widgets' => $this->orders->counterWidgets(null, $this->visibleBranchIds()),
        ]);
    }

    public function show(int $id)
    {
        $order = $this->findScoped($id);

        return responseSuccess('order', 'Order fetched', [
            'order' => new OrderResource($order->load([
                'orderItems.product.media', 'orderItems.variation', 'user', 'guest',
                'branch', 'shippingMethod', 'statusLogs', 'deposits.gateway', 'processedBy', 'soldBy',
            ])),
            'branches' => $this->admin()->isCompanyWide()
                ? Branch::active()->get(['id', 'name', 'code'])
                : [],
        ]);
    }

    public function changeStatus(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => ['required', 'integer', Rule::in(array_keys(Status::ORDER_STATUS_LABELS))],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $order = $this->findScoped($id);

        // Workers can only move an order along the fulfilment path.
        if ($this->admin()->hasRole(Roles::BRANCH_WORKER)
            && ! in_array((int) $data['status'], config('vipuri.order.worker_allowed_statuses'), true)) {
            return responseError('forbidden_status', [
                'Branch workers may only set an order to processing, dispatched or delivered',
            ], code: 403);
        }

        // `order.cancel` and `order.return` exist in the permission catalogue
        // and the admin panel hides the buttons without them, but the route
        // only asked for `order.update_status` — so a role deliberately denied
        // those two could still reach them by posting the status directly.
        $needed = match ((int) $data['status']) {
            Status::ORDER_CANCELLED => 'order.cancel',
            Status::ORDER_RETURNED => 'order.return',
            default => null,
        };

        if ($needed && ! $this->admin()->can($needed)) {
            return responseError('forbidden_status', [
                $data['status'] == Status::ORDER_CANCELLED
                    ? 'You do not have permission to cancel an order'
                    : 'You do not have permission to return an order',
            ], code: 403);
        }

        $order = $this->orders->changeStatus($order->load('orderItems'), (int) $data['status'], $data['remark'] ?? null);

        return responseSuccess('order_status_changed', 'Order status updated', [
            'order' => new OrderResource(
                $order->load('orderItems.product.media', 'orderItems.variation', 'statusLogs', 'branch')
            ),
        ]);
    }

    public function markPaid(int $id)
    {
        $order = $this->findScoped($id);

        if ((int) $order->payment_status === Status::PAYMENT_SUCCESS) {
            return responseError('already_paid', ['This order is already marked as paid']);
        }

        $order = $this->orders->markPaid($order);

        return responseSuccess('order_paid', 'Order marked as paid', ['order' => new OrderResource($order)]);
    }

    /** Reassign fulfilment to another branch. Company-wide staff only. */
    public function assignBranch(Request $request, int $id)
    {
        if (! $this->admin()->isCompanyWide()) {
            abort(403, 'Only company-wide administrators can move an order to another branch');
        }

        $data = $request->validate(['branch_id' => ['required', 'integer', 'exists:branches,id']]);

        $order = Order::with('orderItems')->findOrFail($id);

        // A counter sale left the shelf of the branch that sold it; moving it
        // would make a later return restock the wrong branch.
        if ($order->isPos()) {
            return responseError('pos_order', ['A counter sale stays with the branch that made it']);
        }

        $order = $this->orders->assignBranch($order, (int) $data['branch_id']);

        return responseSuccess('order_reassigned', 'Order reassigned', [
            'order' => new OrderResource($order->load('branch')),
        ]);
    }

    /** Printable invoice as PDF, with TZS amounts. */
    public function invoice(int $id)
    {
        $order = $this->findScoped($id)->load(['orderItems.product', 'user', 'guest', 'branch', 'shippingMethod']);

        $pdf = Pdf::loadView('invoice', [
            'order' => $order,
            'company' => \App\Models\Company::current(),
        ]);

        return $pdf->download("invoice-{$order->order_number}.pdf");
    }

    /**
     * Load an order the caller is allowed to touch. Branch staff are limited to
     * their own branch, which is what stops IDOR on order ids.
     */
    private function findScoped(int $id): Order
    {
        $order = Order::findOrFail($id);

        if (! $this->admin()->canAccessBranch($order->branch_id)) {
            abort(403, 'This order belongs to another branch');
        }

        return $order;
    }
}
