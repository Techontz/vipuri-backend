<?php

namespace App\Http\Controllers\Api\Account;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\UserResource;
use App\Models\Address;
use App\Models\Deposit;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\SupportAttachment;
use App\Models\SupportTicket;
use App\Models\UserNotification;
use App\Services\FileManager;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The signed-in customer area: dashboard, orders, addresses, profile,
 * password, reviews, notifications, payments and support tickets.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly FileManager $files,
    ) {}

    public function dashboard(Request $request)
    {
        $user = $request->user('user');

        return responseSuccess('dashboard', 'Dashboard fetched', [
            'user' => new UserResource($user),
            'widgets' => $this->orders->counterWidgets($user->id),
            'recent_orders' => OrderResource::collection(
                Order::where('user_id', $user->id)
                    ->with('orderItems.product.media', 'orderItems.variation', 'branch')
                    ->latest('id')
                    ->limit(5)
                    ->get()
            ),
            'total_spent' => (float) Order::where('user_id', $user->id)
                ->where('payment_status', Status::PAYMENT_SUCCESS)
                ->sum('total'),
            'wishlist_count' => \App\Models\Wishlist::where('user_id', $user->id)->count(),
            'unread_notifications' => UserNotification::where('user_id', $user->id)->where('is_read', 0)->count(),
        ]);
    }

    /* ------------------------------------------------------------------ *
     | Orders
     * ------------------------------------------------------------------ */

    public function orders(Request $request)
    {
        $user = $request->user('user');

        $query = Order::where('user_id', $user->id)
            ->with('orderItems.product.media', 'branch', 'shippingMethod')
            ->latest('id');

        match ($request->query('status')) {
            'pending' => $query->pending(),
            'processing' => $query->processing(),
            'dispatched' => $query->dispatched(),
            'completed', 'delivered' => $query->delivered(),
            'cancelled' => $query->cancelled(),
            'returned' => $query->returned(),
            'unpaid' => $query->unpaid(),
            default => null,
        };

        if ($search = $request->query('search')) {
            $query->where('order_number', 'like', "%{$search}%");
        }

        $orders = $query->paginate(getPaginate(10));

        return responseSuccess('orders', 'Orders fetched', [
            'orders' => OrderResource::collection($orders->items()),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
            'widgets' => $this->orders->counterWidgets($user->id),
        ]);
    }

    public function orderDetails(Request $request, string $orderNumber)
    {
        $order = Order::where('user_id', $request->user('user')->id)
            ->where('order_number', $orderNumber)
            ->with(['orderItems.product.media', 'orderItems.variation', 'branch', 'shippingMethod', 'statusLogs', 'deposits'])
            ->firstOrFail();

        return responseSuccess('order', 'Order fetched', ['order' => new OrderResource($order)]);
    }

    /**
     * The customer's own copy of the invoice — the `order.print` route in the
     * source system, as a PDF rather than a print stylesheet.
     */
    public function orderInvoice(Request $request, string $orderNumber)
    {
        $order = Order::where('user_id', $request->user('user')->id)
            ->where('order_number', $orderNumber)
            ->with(['orderItems.product', 'user', 'guest', 'branch', 'shippingMethod'])
            ->firstOrFail();

        return Pdf::loadView('invoice', [
            'order' => $order,
            'company' => \App\Models\Company::current(),
        ])->download("invoice-{$order->order_number}.pdf");
    }

    /**
     * Download a support attachment.
     *
     * Attachments can carry receipts and identity documents, so they are never
     * served from the public media path: this checks that the file belongs to a
     * ticket the caller opened before streaming it.
     */
    public function downloadAttachment(Request $request, int $id)
    {
        $attachment = SupportAttachment::with('message.ticket')->findOrFail($id);
        $ticket = $attachment->message?->ticket;

        if (! $ticket || (int) $ticket->user_id !== (int) $request->user('user')->id) {
            abort(403, 'This attachment belongs to another ticket');
        }

        return $this->files->download('attachment', $attachment->attachment);
    }

    /** A customer may cancel an order that has not been dispatched yet. */
    public function cancelOrder(Request $request, string $orderNumber)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $order = Order::where('user_id', $request->user('user')->id)
            ->where('order_number', $orderNumber)
            ->with('orderItems')
            ->firstOrFail();

        if (! in_array((int) $order->status, [Status::ORDER_PENDING, Status::ORDER_PAID, Status::ORDER_PROCESSING], true)) {
            return responseError('cannot_cancel', ['This order can no longer be cancelled']);
        }

        $order = $this->orders->changeStatus($order, Status::ORDER_CANCELLED, $data['reason'] ?? 'Cancelled by customer');

        return responseSuccess('order_cancelled', 'Your order has been cancelled', [
            'order' => new OrderResource($order),
        ]);
    }

    /* ------------------------------------------------------------------ *
     | Addresses
     * ------------------------------------------------------------------ */

    public function addresses(Request $request)
    {
        return responseSuccess('addresses', 'Addresses fetched', [
            'addresses' => Address::where('user_id', $request->user('user')->id)
                ->orderByDesc('is_default')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function saveAddress(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'firstname' => ['required', 'string', 'max:80'],
            'lastname' => ['required', 'string', 'max:80'],
            'dial_code' => ['nullable', 'string', 'max:10'],
            'mobile' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:191'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:191'],
            'state' => ['nullable', 'string', 'max:191'],
            'zip' => ['nullable', 'string', 'max:40'],
            'country_name' => ['nullable', 'string', 'max:191'],
            'country_code' => ['nullable', 'string', 'max:10'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $userId = $request->user('user')->id;

        $address = $id
            ? Address::where('user_id', $userId)->findOrFail($id)
            : new Address(['user_id' => $userId]);

        $address->fill($data);
        $address->user_id = $userId;
        $address->country_name = $data['country_name'] ?? 'Tanzania';
        $address->country_code = $data['country_code'] ?? 'TZ';
        $address->save();

        if (! empty($data['is_default'])) {
            Address::where('user_id', $userId)->where('id', '!=', $address->id)->update(['is_default' => 0]);
        }

        return responseSuccess('address_saved', 'Address saved', ['address' => $address->fresh()]);
    }

    public function deleteAddress(Request $request, int $id)
    {
        Address::where('user_id', $request->user('user')->id)->findOrFail($id)->delete();

        return responseSuccess('address_deleted', 'Address removed');
    }

    /* ------------------------------------------------------------------ *
     | Profile
     * ------------------------------------------------------------------ */

    public function updateProfile(Request $request)
    {
        $user = $request->user('user');

        $data = $request->validate([
            'firstname' => ['required', 'string', 'max:80'],
            'lastname' => ['required', 'string', 'max:80'],
            'dial_code' => ['nullable', 'string', 'max:10'],
            'mobile' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:191'],
            'state' => ['nullable', 'string', 'max:191'],
            'zip' => ['nullable', 'string', 'max:40'],
            'country_name' => ['nullable', 'string', 'max:191'],
            'country_code' => ['nullable', 'string', 'max:10'],
            'preferred_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);

        if ($request->hasFile('image')) {
            $data['image'] = $this->files->uploadImage($request->file('image'), 'userProfile', $user->image);
        }

        $user->fill($data)->save();

        return responseSuccess('profile_updated', 'Profile updated', ['user' => new UserResource($user->fresh())]);
    }

    public function changePassword(Request $request)
    {
        $user = $request->user('user');

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', gs('secure_password')
                ? Password::min(8)->mixedCase()->numbers()->symbols()
                : Password::min(6)],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return responseError('wrong_password', ['Your current password is incorrect']);
        }

        $user->password = $data['password'];
        $user->save();

        // Sign out every other device.
        $currentId = $user->currentAccessToken()?->id;
        $user->tokens()->when($currentId, fn ($q) => $q->where('id', '!=', $currentId))->delete();

        return responseSuccess('password_changed', 'Your password has been changed');
    }

    /* ------------------------------------------------------------------ *
     | Reviews
     * ------------------------------------------------------------------ */

    /** Delivered items the customer has not reviewed yet. */
    public function reviewableProducts(Request $request)
    {
        $userId = $request->user('user')->id;

        $productIds = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('user_id', $userId)->delivered())
            ->pluck('product_id')
            ->unique();

        $reviewed = ProductReview::where('user_id', $userId)->pluck('product_id')->all();

        $products = Product::whereIn('id', $productIds)
            ->with('media', 'brand')
            ->get()
            ->map(fn ($product) => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'image' => fileUrl('product', $product->main_image, true),
                'reviewed' => in_array($product->id, $reviewed, true),
            ]);

        return responseSuccess('reviewable_products', 'Products fetched', ['products' => $products->values()]);
    }

    public function submitReview(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'review' => ['nullable', 'string', 'max:2000'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['image', 'max:5120'],
        ]);

        $userId = $request->user('user')->id;

        // Only verified purchasers can review, as in the source system.
        $hasPurchased = OrderItem::where('product_id', $data['product_id'])
            ->whereHas('order', fn ($q) => $q->where('user_id', $userId)->delivered())
            ->exists();

        if (! $hasPurchased) {
            return responseError('not_purchased', ['You can only review products you have received']);
        }

        $images = [];

        foreach ($request->file('images', []) as $file) {
            $images[] = $this->files->uploadImage($file, 'review');
        }

        $review = ProductReview::updateOrCreate(
            ['user_id' => $userId, 'product_id' => $data['product_id']],
            [
                'rating' => $data['rating'],
                'review' => $data['review'] ?? null,
                'images' => $images ?: null,
                'status' => Status::REVIEW_PENDING,
                'is_viewed' => 0,
            ],
        );

        return responseSuccess('review_submitted', 'Thank you — your review is awaiting approval', [
            'review' => $review,
        ]);
    }

    /* ------------------------------------------------------------------ *
     | Notifications, payments, tickets
     * ------------------------------------------------------------------ */

    public function notifications(Request $request)
    {
        $userId = $request->user('user')->id;

        $notifications = UserNotification::where('user_id', $userId)
            ->latest('id')
            ->paginate(getPaginate(15));

        return responseSuccess('notifications', 'Notifications fetched', [
            'notifications' => $notifications->items(),
            'unread' => UserNotification::where('user_id', $userId)->where('is_read', 0)->count(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    public function readNotification(Request $request, int $id)
    {
        UserNotification::where('user_id', $request->user('user')->id)
            ->findOrFail($id)
            ->update(['is_read' => 1]);

        return responseSuccess('notification_read', 'Notification marked as read');
    }

    public function readAllNotifications(Request $request)
    {
        UserNotification::where('user_id', $request->user('user')->id)->update(['is_read' => 1]);

        return responseSuccess('notifications_read', 'All notifications marked as read');
    }

    public function payments(Request $request)
    {
        $deposits = Deposit::where('user_id', $request->user('user')->id)
            ->with('order:id,order_number', 'gateway:id,code,name,image')
            ->latest('id')
            ->paginate(getPaginate(15));

        return responseSuccess('payments', 'Payment history fetched', [
            'payments' => collect($deposits->items())->map(fn ($d) => [
                'id' => $d->id,
                'trx' => $d->trx,
                'order_number' => $d->order?->order_number,
                'method' => $d->gateway?->name,
                'amount' => (float) $d->amount,
                'charge' => (float) $d->charge,
                'final_amount' => (float) $d->final_amount,
                'currency' => $d->method_currency,
                'status' => (int) $d->status,
                'status_label' => Status::PAYMENT_STATUS_LABELS[$d->status] ?? 'Unknown',
                'created_at' => $d->created_at?->toIso8601String(),
            ])->values(),
            'pagination' => [
                'current_page' => $deposits->currentPage(),
                'last_page' => $deposits->lastPage(),
                'total' => $deposits->total(),
            ],
        ]);
    }

    public function tickets(Request $request)
    {
        $tickets = SupportTicket::where('user_id', $request->user('user')->id)
            ->latest('id')
            ->paginate(getPaginate(15));

        return responseSuccess('tickets', 'Support tickets fetched', [
            'tickets' => $tickets->items(),
            'pagination' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    public function createTicket(Request $request)
    {
        $user = $request->user('user');

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'priority' => ['nullable', Rule::in([Status::PRIORITY_LOW, Status::PRIORITY_MEDIUM, Status::PRIORITY_HIGH])],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        $ticket = SupportTicket::create([
            'user_id' => $user->id,
            'name' => $user->fullname,
            'email' => $user->email,
            'ticket' => strtoupper(Str::random(10)),
            'subject' => $data['subject'],
            'status' => Status::TICKET_OPEN,
            'priority' => $data['priority'] ?? Status::PRIORITY_MEDIUM,
            'last_reply' => now(),
        ]);

        $message = $ticket->messages()->create(['message' => $data['message']]);

        foreach ($request->file('attachments', []) as $file) {
            $message->attachments()->create([
                'attachment' => $this->files->uploadFile($file, 'attachment'),
            ]);
        }

        return responseSuccess('ticket_created', 'Your ticket has been created', ['ticket' => $ticket]);
    }

    public function ticket(Request $request, string $ticketNumber)
    {
        $ticket = SupportTicket::where('user_id', $request->user('user')->id)
            ->where('ticket', $ticketNumber)
            ->with('messages.attachments', 'messages.admin:id,name')
            ->firstOrFail();

        return responseSuccess('ticket', 'Ticket fetched', [
            'ticket' => $ticket,
            'messages' => $ticket->messages->map(fn ($m) => [
                'id' => $m->id,
                'message' => $m->message,
                'from_admin' => (bool) $m->admin_id,
                'admin_name' => $m->admin?->name,
                'attachments' => $m->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->attachment,
                ])->values(),
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function replyTicket(Request $request, string $ticketNumber)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        $ticket = SupportTicket::where('user_id', $request->user('user')->id)
            ->where('ticket', $ticketNumber)
            ->firstOrFail();

        if ((int) $ticket->status === Status::TICKET_CLOSE) {
            return responseError('ticket_closed', ['This ticket has been closed']);
        }

        $message = $ticket->messages()->create(['message' => $data['message']]);

        foreach ($request->file('attachments', []) as $file) {
            $message->attachments()->create([
                'attachment' => $this->files->uploadFile($file, 'attachment'),
            ]);
        }

        $ticket->update(['status' => Status::TICKET_REPLY, 'last_reply' => now()]);

        return responseSuccess('ticket_replied', 'Your reply has been sent');
    }

    public function closeTicket(Request $request, string $ticketNumber)
    {
        SupportTicket::where('user_id', $request->user('user')->id)
            ->where('ticket', $ticketNumber)
            ->firstOrFail()
            ->update(['status' => Status::TICKET_CLOSE]);

        return responseSuccess('ticket_closed', 'Ticket closed');
    }
}
