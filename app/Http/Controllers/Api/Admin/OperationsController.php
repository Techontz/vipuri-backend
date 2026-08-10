<?php

namespace App\Http\Controllers\Api\Admin;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Gateway;
use App\Models\GatewayCurrency;
use App\Models\ProductReview;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\SupportAttachment;
use App\Models\SupportTicket;
use App\Services\AuditService;
use App\Services\FileManager;
use App\Services\NotificationService;
use App\Services\Payment\PaymentManager;
use App\Traits\ScopesToBranch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shipping, payment gateways, deposits, product reviews and support tickets.
 */
class OperationsController extends Controller
{
    use ScopesToBranch;

    public function __construct(
        private readonly FileManager $files,
        private readonly AuditService $audit,
        private readonly PaymentManager $payments,
        private readonly NotificationService $notifications,
    ) {}

    /* ------------------------------ Shipping --------------------------- */

    public function shipping()
    {
        return responseSuccess('shipping', 'Shipping configuration fetched', [
            'zones' => ShippingZone::orderBy('name')->get(),
            'methods' => ShippingMethod::orderBy('name')->get()->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'description' => $m->description,
                'image' => fileUrl('shipping', $m->image),
                'status' => (bool) $m->status,
            ])->values(),
            'rates' => ShippingRate::with('method:id,name', 'zone:id,name')->get()->map(fn ($r) => [
                'id' => $r->id,
                'shipping_method_id' => $r->shipping_method_id,
                'shipping_zone_id' => $r->shipping_zone_id,
                'method' => $r->method?->name,
                'zone' => $r->zone?->name,
                'amount' => (float) $r->amount,
                'min_order_amount' => (float) $r->min_order_amount,
                'max_order_amount' => (float) $r->max_order_amount,
                'expected_delivery_days' => (int) $r->expected_delivery_days,
                'is_cod' => (bool) $r->is_cod,
                'status' => (bool) $r->status,
            ])->values(),
        ]);
    }

    public function saveShippingZone(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'status' => ['nullable', 'boolean'],
        ]);

        $zone = $id ? ShippingZone::findOrFail($id) : new ShippingZone();
        $zone->fill($data)->save();

        return responseSuccess('zone_saved', 'Shipping zone saved', ['zone' => $zone->fresh()]);
    }

    public function shippingZoneStatus(int $id)
    {
        $zone = ShippingZone::findOrFail($id);
        $zone->changeStatus();

        return responseSuccess('zone_status_changed', 'Zone status updated', ['status' => (bool) $zone->status]);
    }

    public function saveShippingMethod(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);

        $method = $id ? ShippingMethod::findOrFail($id) : new ShippingMethod();

        if ($request->hasFile('image')) {
            $data['image'] = $this->files->uploadImage($request->file('image'), 'shipping', $method->image);
        }

        $method->fill($data)->save();

        return responseSuccess('method_saved', 'Shipping method saved', ['method' => $method->fresh()]);
    }

    public function shippingMethodStatus(int $id)
    {
        $method = ShippingMethod::findOrFail($id);
        $method->changeStatus();

        return responseSuccess('method_status_changed', 'Method status updated', ['status' => (bool) $method->status]);
    }

    public function saveShippingRate(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'shipping_method_id' => ['required', 'integer', 'exists:shipping_methods,id'],
            'shipping_zone_id' => ['required', 'integer', 'exists:shipping_zones,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'max_order_amount' => ['nullable', 'numeric', 'min:0'],
            'expected_delivery_days' => ['nullable', 'integer', 'min:0'],
            'is_cod' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
        ]);

        $rate = $id ? ShippingRate::findOrFail($id) : new ShippingRate();
        $rate->fill($data)->save();

        return responseSuccess('rate_saved', 'Shipping rate saved', ['rate' => $rate->fresh()]);
    }

    public function shippingRateStatus(int $id)
    {
        $rate = ShippingRate::findOrFail($id);
        $rate->changeStatus();

        return responseSuccess('rate_status_changed', 'Rate status updated', ['status' => (bool) $rate->status]);
    }

    /* ------------------------------ Gateways --------------------------- */

    public function gateways()
    {
        $gateways = Gateway::with('currencies')->orderBy('name')->get();

        return responseSuccess('gateways', 'Payment gateways fetched', [
            'automatic' => $gateways->filter->isAutomatic()->map(fn ($g) => $this->mapGateway($g))->values(),
            'manual' => $gateways->filter->isManual()->map(fn ($g) => $this->mapGateway($g))->values(),
        ]);
    }

    public function saveGateway(Request $request, int $id)
    {
        $gateway = Gateway::findOrFail($id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'boolean'],
            'gateway_parameters' => ['nullable', 'array'],
            'currencies' => ['nullable', 'array'],
            'currencies.*.id' => ['nullable', 'integer'],
            'currencies.*.name' => ['required_with:currencies', 'string', 'max:120'],
            'currencies.*.currency' => ['required_with:currencies', 'string', 'max:20'],
            'currencies.*.symbol' => ['nullable', 'string', 'max:20'],
            'currencies.*.min_amount' => ['nullable', 'numeric', 'min:0'],
            'currencies.*.max_amount' => ['nullable', 'numeric', 'min:0'],
            'currencies.*.percent_charge' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currencies.*.fixed_charge' => ['nullable', 'numeric', 'min:0'],
            'currencies.*.rate' => ['nullable', 'numeric', 'min:0'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);

        if ($request->hasFile('image')) {
            $data['image'] = $this->files->uploadImage($request->file('image'), 'gateway', $gateway->image);
        }

        if (isset($data['gateway_parameters'])) {
            // Cast to object on the model handles encoding.
        }

        $gateway->fill(collect($data)->except('currencies')->all())->save();

        foreach ($data['currencies'] ?? [] as $currencyData) {
            $currency = ! empty($currencyData['id'])
                ? GatewayCurrency::find($currencyData['id'])
                : new GatewayCurrency();

            $currency->fill($currencyData);
            $currency->method_code = $gateway->code;
            $currency->gateway_alias = $gateway->alias;
            $currency->save();
        }

        $this->audit->log('gateway.updated', $gateway, description: "Gateway {$gateway->name} updated");

        return responseSuccess('gateway_saved', 'Gateway saved', [
            'gateway' => $this->mapGateway($gateway->fresh('currencies')),
        ]);
    }

    public function gatewayStatus(int $id)
    {
        $gateway = Gateway::findOrFail($id);

        // Refuse to enable an automatic gateway with no credentials — that would
        // give customers a payment option that cannot possibly complete.
        if (! $gateway->status && $gateway->isAutomatic()) {
            $driver = $this->payments->driverFor($gateway);

            if (! $driver) {
                return responseError('no_driver', ['No payment driver is implemented for this gateway']);
            }

            if (! $driver->isConfigured()) {
                return responseError('not_configured', [
                    'Configure these credentials first: ' . implode(', ', $driver->requiredCredentials()),
                ]);
            }
        }

        $gateway->changeStatus();

        $this->audit->log(
            'gateway.status_changed',
            $gateway,
            newValues: ['status' => $gateway->status],
            description: "Gateway {$gateway->name} " . ($gateway->status ? 'enabled' : 'disabled'),
        );

        return responseSuccess('gateway_status_changed', 'Gateway status updated', ['status' => (bool) $gateway->status]);
    }

    public function createManualGateway(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string'],
            'currency' => ['required', 'string', 'max:20'],
            'symbol' => ['nullable', 'string', 'max:20'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'percent_charge' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_charge' => ['nullable', 'numeric', 'min:0'],
            'gateway_parameters' => ['nullable', 'array'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);

        $code = (int) (Gateway::where('code', '>=', 1000)->max('code') ?? 999) + 1;

        $gateway = new Gateway([
            'code' => $code,
            'name' => $data['name'],
            'alias' => \Illuminate\Support\Str::slug($data['name']) . '-' . $code,
            'status' => Status::DISABLE,
            'description' => $data['description'] ?? null,
            'gateway_parameters' => $data['gateway_parameters'] ?? [],
        ]);

        if ($request->hasFile('image')) {
            $gateway->image = $this->files->uploadImage($request->file('image'), 'gateway');
        }

        $gateway->save();

        GatewayCurrency::create([
            'name' => $data['name'],
            'currency' => $data['currency'],
            'symbol' => $data['symbol'] ?? null,
            'method_code' => $code,
            'gateway_alias' => $gateway->alias,
            'min_amount' => $data['min_amount'] ?? 0,
            'max_amount' => $data['max_amount'] ?? 0,
            'percent_charge' => $data['percent_charge'] ?? 0,
            'fixed_charge' => $data['fixed_charge'] ?? 0,
            'rate' => $data['rate'] ?? 1,
        ]);

        $this->audit->log('gateway.created', $gateway, description: "Manual gateway {$gateway->name} created");

        return responseSuccess('gateway_created', 'Manual gateway created', [
            'gateway' => $this->mapGateway($gateway->fresh('currencies')),
        ]);
    }

    /* ------------------------------ Deposits --------------------------- */

    public function deposits(Request $request)
    {
        $query = Deposit::query()
            ->with('user:id,firstname,lastname,email', 'order:id,order_number,branch_id', 'gateway:id,code,name')
            ->when($request->query('search'), fn ($q, $s) => $q->where('trx', 'like', "%$s%"))
            ->latest('id');

        match ($request->query('status')) {
            'pending' => $query->pending(),
            'successful', 'approved' => $query->successful(),
            'rejected' => $query->rejected(),
            'initiated' => $query->initiated(),
            default => null,
        };

        // Branch staff only see payments for their own branch's orders.
        $branchIds = $this->visibleBranchIds();

        if ($branchIds !== null) {
            $query->whereHas('order', fn ($o) => $o->whereIn('branch_id', $branchIds ?: [0]));
        }

        $deposits = $query->paginate(getPaginate(20));

        return responseSuccess('deposits', 'Payments fetched', [
            'deposits' => collect($deposits->items())->map(fn (Deposit $d) => [
                'id' => $d->id,
                'trx' => $d->trx,
                'customer' => $d->user ? $d->user->fullname : 'Guest',
                'email' => $d->user?->email,
                'order_number' => $d->order?->order_number,
                'method' => $d->gateway?->name,
                'amount' => (float) $d->amount,
                'charge' => (float) $d->charge,
                'final_amount' => (float) $d->final_amount,
                'currency' => $d->method_currency,
                'detail' => $d->detail,
                'status' => (int) $d->status,
                'status_label' => Status::PAYMENT_STATUS_LABELS[$d->status] ?? 'Unknown',
                'admin_feedback' => $d->admin_feedback,
                'created_at' => $d->created_at?->toIso8601String(),
            ])->values(),
            'pagination' => $this->pagination($deposits),
        ]);
    }

    public function approveDeposit(int $id)
    {
        $deposit = $this->findScopedDeposit($id);

        if ((int) $deposit->status !== Status::PAYMENT_PENDING) {
            return responseError('invalid_state', ['Only a pending payment can be approved']);
        }

        $this->payments->markSuccess($deposit);
        $this->notifications->depositApproved($deposit->fresh(['order', 'gateway']));

        $this->audit->log('deposit.approved', $deposit, description: "Payment {$deposit->trx} approved", branchId: $deposit->order?->branch_id);

        return responseSuccess('deposit_approved', 'Payment approved');
    }

    public function rejectDeposit(Request $request, int $id)
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:255']]);

        $deposit = $this->findScopedDeposit($id);

        if ((int) $deposit->status !== Status::PAYMENT_PENDING) {
            return responseError('invalid_state', ['Only a pending payment can be rejected']);
        }

        $this->payments->markRejected($deposit, $data['message']);
        $this->notifications->depositRejected($deposit->fresh(['order', 'gateway']));

        $this->audit->log('deposit.rejected', $deposit, description: "Payment {$deposit->trx} rejected", branchId: $deposit->order?->branch_id);

        return responseSuccess('deposit_rejected', 'Payment rejected');
    }

    /* ------------------------------- Reviews --------------------------- */

    public function reviews(Request $request)
    {
        $query = ProductReview::query()
            ->with('user:id,firstname,lastname,username', 'product:id,name,slug', 'productReviewReply')
            ->when($request->query('search'), fn ($q, $s) => $q->whereHas('product', fn ($p) => $p->where('name', 'like', "%$s%")))
            ->latest('id');

        match ($request->query('status')) {
            'pending' => $query->pending(),
            'approved' => $query->approved(),
            'rejected' => $query->rejected(),
            default => null,
        };

        $reviews = $query->paginate(getPaginate(20));

        return responseSuccess('reviews', 'Reviews fetched', [
            'reviews' => collect($reviews->items())->map(fn (ProductReview $r) => [
                'id' => $r->id,
                'product_id' => $r->product_id,
                'product_name' => $r->product?->name,
                'customer' => $r->user ? trim($r->user->firstname . ' ' . $r->user->lastname) : null,
                'rating' => (int) $r->rating,
                'review' => $r->review,
                'images' => collect($r->images ?? [])->map(fn ($i) => fileUrl('review', $i))->values(),
                'status' => (int) $r->status,
                'reject_reason' => $r->reject_reason,
                'reply' => $r->productReviewReply?->comment,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values(),
            'pagination' => $this->pagination($reviews),
            'counts' => [
                'pending' => ProductReview::pending()->count(),
                'approved' => ProductReview::approved()->count(),
                'rejected' => ProductReview::rejected()->count(),
            ],
        ]);
    }

    public function approveReview(int $id)
    {
        $review = ProductReview::findOrFail($id);
        $review->update(['status' => Status::REVIEW_APPROVED, 'reject_reason' => null, 'is_viewed' => 1]);
        $this->notifications->reviewApproved($review->fresh('product'));

        $this->audit->log('review.approved', $review, description: "Review #{$review->id} approved");

        return responseSuccess('review_approved', 'Review approved');
    }

    public function rejectReview(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $review = ProductReview::findOrFail($id);
        $review->update(['status' => Status::REVIEW_REJECTED, 'reject_reason' => $data['reason'], 'is_viewed' => 1]);
        $this->notifications->reviewRejected($review->fresh('product'));

        $this->audit->log('review.rejected', $review, description: "Review #{$review->id} rejected");

        return responseSuccess('review_rejected', 'Review rejected');
    }

    public function replyReview(Request $request, int $id)
    {
        $data = $request->validate(['comment' => ['required', 'string', 'max:2000']]);

        $review = ProductReview::findOrFail($id);

        $review->productReviewReply()->updateOrCreate(
            ['product_review_id' => $review->id],
            ['admin_id' => $this->admin()->id, 'comment' => $data['comment']],
        );

        return responseSuccess('review_replied', 'Reply saved');
    }

    public function deleteReview(int $id)
    {
        $review = ProductReview::findOrFail($id);

        foreach ($review->images ?? [] as $image) {
            $this->files->removeImage('review', $image);
        }

        $review->productReviewReply()->delete();
        $review->delete();

        $this->audit->log('review.deleted', description: "Review #$id deleted");

        return responseSuccess('review_deleted', 'Review deleted');
    }

    /* ---------------------------- Support tickets ---------------------- */

    public function tickets(Request $request)
    {
        $query = SupportTicket::query()
            ->with('user:id,firstname,lastname,email')
            ->when($request->query('search'), fn ($q, $s) => $q
                ->where('ticket', 'like', "%$s%")
                ->orWhere('subject', 'like', "%$s%")
                ->orWhere('email', 'like', "%$s%"))
            ->latest('last_reply');

        match ($request->query('status')) {
            'open', 'pending' => $query->whereIn('status', [Status::TICKET_OPEN, Status::TICKET_REPLY]),
            'answered' => $query->answered(),
            'closed' => $query->closed(),
            default => null,
        };

        $tickets = $query->paginate(getPaginate(20));

        return responseSuccess('tickets', 'Tickets fetched', [
            'tickets' => $tickets->items(),
            'pagination' => $this->pagination($tickets),
            'counts' => [
                'pending' => SupportTicket::whereIn('status', [Status::TICKET_OPEN, Status::TICKET_REPLY])->count(),
                'answered' => SupportTicket::answered()->count(),
                'closed' => SupportTicket::closed()->count(),
            ],
        ]);
    }

    public function ticket(int $id)
    {
        $ticket = SupportTicket::with('messages.attachments', 'messages.admin:id,name', 'user')->findOrFail($id);

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

    /**
     * Download a support attachment. Staff can reach any ticket they are
     * allowed to view, but the file is still streamed rather than exposed on
     * the public media path.
     */
    public function downloadAttachment(int $id)
    {
        $attachment = SupportAttachment::findOrFail($id);

        return $this->files->download('attachment', $attachment->attachment);
    }

    public function replyTicket(Request $request, int $id)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        $ticket = SupportTicket::findOrFail($id);

        $message = $ticket->messages()->create([
            'admin_id' => $this->admin()->id,
            'message' => $data['message'],
        ]);

        foreach ($request->file('attachments', []) as $file) {
            $message->attachments()->create([
                'attachment' => $this->files->uploadFile($file, 'attachment'),
            ]);
        }

        $ticket->update(['status' => Status::TICKET_ANSWER, 'last_reply' => now()]);

        // Sends the e-mail/SMS template and records the in-app notification.
        $this->notifications->supportReplied($ticket);

        return responseSuccess('ticket_replied', 'Reply sent');
    }

    public function closeTicket(int $id)
    {
        SupportTicket::findOrFail($id)->update(['status' => Status::TICKET_CLOSE]);

        return responseSuccess('ticket_closed', 'Ticket closed');
    }

    public function deleteTicket(int $id)
    {
        $ticket = SupportTicket::with('messages.attachments')->findOrFail($id);

        foreach ($ticket->messages as $message) {
            foreach ($message->attachments as $attachment) {
                $this->files->removeImage('attachment', $attachment->attachment);
            }
        }

        $ticket->delete();

        $this->audit->log('ticket.deleted', description: "Ticket #$id deleted");

        return responseSuccess('ticket_deleted', 'Ticket deleted');
    }

    /* ------------------------------- Helpers --------------------------- */

    private function mapGateway(Gateway $gateway): array
    {
        $driver = $gateway->isAutomatic() ? $this->payments->driverFor($gateway) : null;

        return [
            'id' => $gateway->id,
            'code' => (int) $gateway->code,
            'name' => $gateway->name,
            'alias' => $gateway->alias,
            'image' => fileUrl('gateway', $gateway->image),
            'description' => $gateway->description,
            'status' => (bool) $gateway->status,
            'is_manual' => $gateway->isManual(),
            'has_driver' => $driver !== null,
            'is_configured' => $driver?->isConfigured() ?? true,
            'required_credentials' => $driver?->requiredCredentials() ?? [],
            'gateway_parameters' => $gateway->gateway_parameters,
            'currencies' => $gateway->currencies->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'currency' => $c->currency,
                'symbol' => $c->symbol,
                'min_amount' => (float) $c->min_amount,
                'max_amount' => (float) $c->max_amount,
                'percent_charge' => (float) $c->percent_charge,
                'fixed_charge' => (float) $c->fixed_charge,
                'rate' => (float) $c->rate,
            ])->values(),
        ];
    }

    private function findScopedDeposit(int $id): Deposit
    {
        $deposit = Deposit::with('order')->findOrFail($id);
        $branchIds = $this->visibleBranchIds();

        if ($branchIds !== null && ! in_array((int) $deposit->order?->branch_id, $branchIds, true)) {
            abort(403, 'This payment belongs to another branch');
        }

        return $deposit;
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
