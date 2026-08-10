<?php

namespace App\Http\Controllers\Api\Admin;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\UserResource;
use App\Models\Order;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Traits\ScopesToBranch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customer administration.
 *
 * Branch staff only see customers who have ordered from their branch; a super
 * admin sees everybody.
 */
class CustomerController extends Controller
{
    use ScopesToBranch;

    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request)
    {
        $branchIds = $this->visibleBranchIds();

        $query = User::query()
            ->withCount('orders')
            ->when($request->query('search'), fn ($q, $s) => $q->where(function ($inner) use ($s) {
                $inner->where('firstname', 'like', "%$s%")
                    ->orWhere('lastname', 'like', "%$s%")
                    ->orWhere('username', 'like', "%$s%")
                    ->orWhere('email', 'like', "%$s%")
                    ->orWhere('mobile', 'like', "%$s%");
            }))
            ->when($branchIds !== null, fn ($q) => $q->whereHas('orders', fn ($o) => $o->whereIn('branch_id', $branchIds ?: [0])))
            ->latest('id');

        match ($request->query('segment')) {
            'active' => $query->active(),
            'banned' => $query->banned(),
            'email_verified' => $query->emailVerified(),
            'email_unverified' => $query->emailUnverified(),
            'mobile_verified' => $query->mobileVerified(),
            'mobile_unverified' => $query->mobileUnverified(),
            'with_orders' => $query->has('orders'),
            default => null,
        };

        $customers = $query->paginate(getPaginate(20));

        return responseSuccess('customers', 'Customers fetched', [
            'customers' => UserResource::collection($customers->items()),
            'pagination' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'total' => $customers->total(),
            ],
            'segments' => $this->segmentCounts($branchIds),
        ]);
    }

    public function show(int $id)
    {
        $customer = $this->findVisible($id);

        $orderQuery = Order::where('user_id', $customer->id);
        $this->scopeBranch($orderQuery);

        return responseSuccess('customer', 'Customer fetched', [
            'customer' => new UserResource($customer->loadCount('orders')),
            'stats' => [
                'orders_total' => (clone $orderQuery)->count(),
                'orders_delivered' => (clone $orderQuery)->delivered()->count(),
                'orders_cancelled' => (clone $orderQuery)->cancelled()->count(),
                'total_spent' => (float) (clone $orderQuery)->where('payment_status', Status::PAYMENT_SUCCESS)->sum('total'),
                'last_order_at' => (clone $orderQuery)->latest('id')->value('created_at'),
            ],
            'recent_orders' => OrderResource::collection(
                (clone $orderQuery)->with('branch', 'orderItems.product.media', 'orderItems.variation')->latest('id')->limit(10)->get()
            ),
            'addresses' => $customer->addresses,
            'logins' => $customer->logins()->latest('id')->limit(10)->get(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $customer = $this->findVisible($id);
        $before = $customer->getAttributes();

        $data = $request->validate([
            'firstname' => ['required', 'string', 'max:80'],
            'lastname' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email,' . $customer->id],
            'dial_code' => ['nullable', 'string', 'max:10'],
            'mobile' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:191'],
            'state' => ['nullable', 'string', 'max:191'],
            'zip' => ['nullable', 'string', 'max:40'],
            'country_name' => ['nullable', 'string', 'max:191'],
            'ev' => ['nullable', 'boolean'],
            'sv' => ['nullable', 'boolean'],
        ]);

        $customer->fill($data)->save();

        $this->audit->logUpdate('customer.updated', $customer, $before, "Customer {$customer->username} updated");

        return responseSuccess('customer_updated', 'Customer updated', [
            'customer' => new UserResource($customer->fresh()),
        ]);
    }

    public function changeStatus(Request $request, int $id)
    {
        // Suspending an account is a company-level action.
        if (! $this->admin()->isSuperAdmin() && ! $this->admin()->can('customer.status')) {
            abort(403, 'You do not have permission to change customer status');
        }

        $customer = $this->findVisible($id);

        $data = $request->validate(['ban_reason' => ['nullable', 'string', 'max:255']]);

        $customer->status = $customer->status ? Status::USER_BAN : Status::USER_ACTIVE;
        $customer->ban_reason = $customer->status ? null : ($data['ban_reason'] ?? null);
        $customer->save();

        if (! $customer->status) {
            $customer->tokens()->delete();
        }

        $this->audit->log(
            'customer.status_changed',
            $customer,
            newValues: ['status' => $customer->status],
            description: "Customer {$customer->username} " . ($customer->status ? 'reactivated' : 'suspended'),
        );

        return responseSuccess('customer_status_changed', 'Customer status updated', [
            'customer' => new UserResource($customer->fresh()),
        ]);
    }

    /** Send an in-app (and templated e-mail/SMS) notification to a customer. */
    public function notify(Request $request, int $id)
    {
        $customer = $this->findVisible($id);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        UserNotification::create([
            'user_id' => $customer->id,
            'title' => $data['subject'],
        ]);

        \App\Models\NotificationLog::create([
            'user_id' => $customer->id,
            'sender' => $this->admin()->name,
            'sent_from' => gs('email_from'),
            'sent_to' => $customer->email,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'notification_type' => 'email',
        ]);

        $this->audit->log('customer.notified', $customer, description: "Notification sent to {$customer->username}");

        return responseSuccess('customer_notified', 'Notification sent');
    }

    /** Bulk notification to a customer segment. Super admin only. */
    public function notifyAll(Request $request)
    {
        if (! $this->admin()->isSuperAdmin()) {
            abort(403, 'Only a super administrator can send bulk notifications');
        }

        $data = $request->validate([
            'segment' => ['required', Rule::in(['all', 'active', 'banned', 'email_verified', 'with_orders'])],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $query = User::query();

        match ($data['segment']) {
            'active' => $query->active(),
            'banned' => $query->banned(),
            'email_verified' => $query->emailVerified(),
            'with_orders' => $query->has('orders'),
            default => null,
        };

        $count = 0;

        $query->chunkById(200, function ($users) use ($data, &$count) {
            foreach ($users as $user) {
                UserNotification::create(['user_id' => $user->id, 'title' => $data['subject']]);

                \App\Models\NotificationLog::create([
                    'user_id' => $user->id,
                    'sender' => $this->admin()->name,
                    'sent_to' => $user->email,
                    'subject' => $data['subject'],
                    'message' => $data['message'],
                    'notification_type' => 'email',
                ]);

                $count++;
            }
        });

        $this->audit->log('customer.bulk_notified', description: "Bulk notification sent to $count customers");

        return responseSuccess('customers_notified', "Notification queued for $count customers");
    }

    private function segmentCounts(?array $branchIds): array
    {
        $base = fn () => User::query()->when(
            $branchIds !== null,
            fn ($q) => $q->whereHas('orders', fn ($o) => $o->whereIn('branch_id', $branchIds ?: [0]))
        );

        return [
            'all' => $base()->count(),
            'active' => $base()->active()->count(),
            'banned' => $base()->banned()->count(),
            'email_verified' => $base()->emailVerified()->count(),
            'email_unverified' => $base()->emailUnverified()->count(),
            'mobile_verified' => $base()->mobileVerified()->count(),
            'mobile_unverified' => $base()->mobileUnverified()->count(),
            'with_orders' => $base()->has('orders')->count(),
        ];
    }

    private function findVisible(int $id): User
    {
        $customer = User::findOrFail($id);
        $branchIds = $this->visibleBranchIds();

        if ($branchIds !== null) {
            $ordered = Order::where('user_id', $customer->id)
                ->whereIn('branch_id', $branchIds ?: [0])
                ->exists();

            if (! $ordered) {
                abort(403, 'This customer has not ordered from your branch');
            }
        }

        return $customer;
    }
}
