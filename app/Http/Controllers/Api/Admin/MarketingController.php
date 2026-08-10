<?php

namespace App\Http\Controllers\Api\Admin;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Coupon;
use App\Models\Offer;
use App\Models\Subscriber;
use App\Services\AuditService;
use App\Services\FileManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Coupons, offers, campaigns and newsletter subscribers.
 */
class MarketingController extends Controller
{
    public function __construct(
        private readonly FileManager $files,
        private readonly AuditService $audit,
    ) {}

    /* ------------------------------- Coupons --------------------------- */

    public function coupons(Request $request)
    {
        $coupons = Coupon::query()
            ->withCount('usages')
            ->when($request->query('search'), fn ($q, $s) => $q->where('code', 'like', "%$s%")->orWhere('name', 'like', "%$s%"))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->latest('id')
            ->paginate(getPaginate(20));

        return responseSuccess('coupons', 'Coupons fetched', [
            'coupons' => collect($coupons->items())->map(fn (Coupon $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'description' => $c->description,
                'discount_type' => (int) $c->discount_type,
                'amount' => (float) $c->amount,
                'max_discount' => (float) $c->max_discount,
                'minimum_spend' => (float) $c->minimum_spend,
                'maximum_spend' => (float) $c->maximum_spend,
                'limit_per_coupon' => $c->limit_per_coupon,
                'limit_per_customer' => $c->limit_per_customer,
                'total_uses' => (int) $c->total_uses,
                'usages_count' => $c->usages_count,
                'exclude_sale_items' => (bool) $c->exclude_sale_items,
                'exclude_offers' => (bool) $c->exclude_offers,
                'expiry_date' => $c->expiry_date?->toDateString(),
                'is_expired' => $c->isExpired(),
                'status' => (bool) $c->status,
            ])->values(),
            'pagination' => $this->pagination($coupons),
        ]);
    }

    public function showCoupon(int $id)
    {
        $coupon = Coupon::with('products:id,name', 'categories:id,name')->findOrFail($id);

        return responseSuccess('coupon', 'Coupon fetched', [
            'coupon' => $coupon,
            'product_ids' => $coupon->products->pluck('id')->values(),
            'category_ids' => $coupon->categories->pluck('id')->values(),
        ]);
    }

    public function saveCoupon(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:80', 'unique:coupons,code' . ($id ? ",$id" : '')],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', 'integer', Rule::in([
                Status::COUPON_DISCOUNT_PERCENT,
                Status::COUPON_DISCOUNT_FIXED_CART,
                Status::COUPON_DISCOUNT_FIXED_PRODUCT,
            ])],
            'amount' => ['required', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'minimum_spend' => ['nullable', 'numeric', 'min:0'],
            'maximum_spend' => ['nullable', 'numeric', 'min:0'],
            'limit_per_coupon' => ['nullable', 'integer', 'min:0'],
            'limit_per_customer' => ['nullable', 'integer', 'min:0'],
            'exclude_sale_items' => ['nullable', 'boolean'],
            'exclude_offers' => ['nullable', 'boolean'],
            'expiry_date' => ['nullable', 'date'],
            'status' => ['nullable', 'boolean'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
        ]);

        if ($data['discount_type'] === Status::COUPON_DISCOUNT_PERCENT && $data['amount'] > 100) {
            return responseError('invalid_amount', ['A percentage discount cannot exceed 100']);
        }

        $coupon = $id ? Coupon::findOrFail($id) : new Coupon();
        $before = $coupon->getAttributes();

        $coupon->fill(collect($data)->except('product_ids', 'category_ids')->all())->save();
        $coupon->products()->sync($data['product_ids'] ?? []);
        $coupon->categories()->sync($data['category_ids'] ?? []);

        $id
            ? $this->audit->logUpdate('coupon.updated', $coupon, $before, "Coupon {$coupon->code} updated")
            : $this->audit->log('coupon.created', $coupon, description: "Coupon {$coupon->code} created");

        return responseSuccess('coupon_saved', 'Coupon saved', ['coupon' => $coupon->fresh()]);
    }

    public function couponStatus(int $id)
    {
        $coupon = Coupon::findOrFail($id);
        $coupon->changeStatus();

        return responseSuccess('coupon_status_changed', 'Coupon status updated', ['status' => (bool) $coupon->status]);
    }

    /* -------------------------------- Offers --------------------------- */

    public function offers(Request $request)
    {
        $offers = Offer::query()
            ->withCount('products', 'categories')
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%$s%"))
            ->latest('id')
            ->paginate(getPaginate(20));

        return responseSuccess('offers', 'Offers fetched', [
            'offers' => collect($offers->items())->map(fn (Offer $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'description' => $o->description,
                'image' => fileUrl('offer', $o->image),
                'discount_type' => (int) $o->discount_type,
                'amount' => (float) $o->amount,
                'priority' => (int) $o->priority,
                'apply_once_per_cart' => (bool) $o->apply_once_per_cart,
                'show_on_section' => (bool) $o->show_on_section,
                'start_at' => $o->start_at?->toIso8601String(),
                'end_at' => $o->end_at?->toIso8601String(),
                'is_running' => $o->isRunning(),
                'products_count' => $o->products_count,
                'categories_count' => $o->categories_count,
                'status' => (bool) $o->status,
            ])->values(),
            'pagination' => $this->pagination($offers),
        ]);
    }

    public function showOffer(int $id)
    {
        $offer = Offer::with('products:id,name', 'categories:id,name')->findOrFail($id);

        return responseSuccess('offer', 'Offer fetched', [
            'offer' => $offer,
            'product_ids' => $offer->products->pluck('id')->values(),
            'category_ids' => $offer->categories->pluck('id')->values(),
        ]);
    }

    public function saveOffer(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', 'integer', Rule::in([Status::OFFER_PERCENT, Status::OFFER_FIXED])],
            'amount' => ['required', 'numeric', 'min:0'],
            'priority' => ['nullable', 'integer', 'min:1'],
            'apply_once_per_cart' => ['nullable', 'boolean'],
            'show_on_section' => ['nullable', 'boolean'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'status' => ['nullable', 'boolean'],
            'campaign_id' => ['nullable', 'integer'],
            'image' => ['nullable', 'image', 'max:5120'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
        ]);

        if ($data['discount_type'] === Status::OFFER_PERCENT && $data['amount'] > 100) {
            return responseError('invalid_amount', ['A percentage discount cannot exceed 100']);
        }

        $offer = $id ? Offer::findOrFail($id) : new Offer();

        if ($request->hasFile('image')) {
            $data['image'] = $this->files->uploadImage($request->file('image'), 'offer', $offer->image);
        }

        $offer->fill(collect($data)->except('product_ids', 'category_ids')->all())->save();
        $offer->products()->sync($data['product_ids'] ?? []);
        $offer->categories()->sync($data['category_ids'] ?? []);

        $this->audit->log($id ? 'offer.updated' : 'offer.created', $offer, description: "Offer {$offer->name} saved");

        return responseSuccess('offer_saved', 'Offer saved', ['offer' => $offer->fresh()]);
    }

    public function offerStatus(int $id)
    {
        $offer = Offer::findOrFail($id);
        $offer->changeStatus();

        return responseSuccess('offer_status_changed', 'Offer status updated', ['status' => (bool) $offer->status]);
    }

    /* ------------------------------ Campaigns -------------------------- */

    public function campaigns(Request $request)
    {
        $campaigns = Campaign::query()
            ->withCount('products', 'categories')
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%$s%"))
            ->latest('id')
            ->paginate(getPaginate(20));

        return responseSuccess('campaigns', 'Campaigns fetched', [
            'campaigns' => collect($campaigns->items())->map(fn (Campaign $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'image' => fileUrl('campaign', $c->image),
                'banner' => fileUrl('campaign', $c->banner),
                'discount_type' => (int) $c->discount_type,
                'amount' => (float) $c->amount,
                'start_at' => $c->start_at?->toIso8601String(),
                'end_at' => $c->end_at?->toIso8601String(),
                'is_running' => $c->isRunning(),
                'show_on_section' => (bool) $c->show_on_section,
                'products_count' => $c->products_count,
                'categories_count' => $c->categories_count,
                'status' => (bool) $c->status,
            ])->values(),
            'pagination' => $this->pagination($campaigns),
        ]);
    }

    public function showCampaign(int $id)
    {
        $campaign = Campaign::with('products:id,name', 'categories:id,name')->findOrFail($id);

        return responseSuccess('campaign', 'Campaign fetched', [
            'campaign' => $campaign,
            'product_ids' => $campaign->products->pluck('id')->values(),
            'category_ids' => $campaign->categories->pluck('id')->values(),
        ]);
    }

    public function saveCampaign(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'discount_type' => ['required', 'integer', Rule::in([Status::OFFER_PERCENT, Status::OFFER_FIXED])],
            'amount' => ['required', 'numeric', 'min:0'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'show_on_section' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'max:5120'],
            'banner' => ['nullable', 'image', 'max:8192'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
        ]);

        $campaign = $id ? Campaign::findOrFail($id) : new Campaign();

        if ($request->hasFile('image')) {
            $data['image'] = $this->files->uploadImage($request->file('image'), 'campaign', $campaign->image);
        }

        if ($request->hasFile('banner')) {
            $data['banner'] = $this->files->uploadImage($request->file('banner'), 'campaign', $campaign->banner);
        }

        $campaign->fill(collect($data)->except('product_ids', 'category_ids')->all())->save();
        $campaign->products()->sync($data['product_ids'] ?? []);
        $campaign->categories()->sync($data['category_ids'] ?? []);

        $this->audit->log($id ? 'campaign.updated' : 'campaign.created', $campaign, description: "Campaign {$campaign->name} saved");

        return responseSuccess('campaign_saved', 'Campaign saved', ['campaign' => $campaign->fresh()]);
    }

    public function campaignStatus(int $id)
    {
        $campaign = Campaign::findOrFail($id);
        $campaign->changeStatus();

        return responseSuccess('campaign_status_changed', 'Campaign status updated', ['status' => (bool) $campaign->status]);
    }

    /* ----------------------------- Subscribers ------------------------- */

    public function subscribers(Request $request)
    {
        $subscribers = Subscriber::query()
            ->when($request->query('search'), fn ($q, $s) => $q->where('email', 'like', "%$s%"))
            ->latest('id')
            ->paginate(getPaginate(30));

        return responseSuccess('subscribers', 'Subscribers fetched', [
            'subscribers' => $subscribers->items(),
            'pagination' => $this->pagination($subscribers),
        ]);
    }

    public function removeSubscriber(int $id)
    {
        Subscriber::findOrFail($id)->delete();

        return responseSuccess('subscriber_removed', 'Subscriber removed');
    }

    public function emailSubscribers(Request $request)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:10000'],
        ]);

        $count = 0;

        Subscriber::chunkById(200, function ($subscribers) use ($data, &$count) {
            foreach ($subscribers as $subscriber) {
                \App\Models\NotificationLog::create([
                    'user_id' => 0,
                    'sender' => auth('admin')->user()->name,
                    'sent_to' => $subscriber->email,
                    'subject' => $data['subject'],
                    'message' => $data['message'],
                    'notification_type' => 'email',
                ]);

                try {
                    \Illuminate\Support\Facades\Mail::html(
                        $data['message'],
                        fn ($m) => $m->to($subscriber->email)->subject($data['subject']),
                    );
                } catch (\Throwable) {
                    // Logged above; a single bad address must not abort the run.
                }

                $count++;
            }
        });

        return responseSuccess('subscribers_emailed', "E-mail sent to $count subscribers");
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
