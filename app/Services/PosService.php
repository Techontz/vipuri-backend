<?php

namespace App\Services;

use App\Constants\Status;
use App\Exceptions\PosPermissionException;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Point of sale: counter sales to walk-in customers from one branch's stock.
 *
 * Prices and tax are worked out exactly as the storefront cart does
 * ({@see CartService::items()}): the unit price is the product's price after
 * any running offer, and VAT is added on top of it for taxable products. A
 * counter sale therefore costs the customer what the same basket would online,
 * minus delivery.
 *
 * A counter sale is complete the moment it is rung up: the order is created
 * delivered and paid, the stock leaves the branch immediately, and the seller
 * earns commission on it like on any delivered order. Everything afterwards —
 * the orders list, returns, reports, the dashboard — treats it as an ordinary
 * order with `channel = pos`.
 */
class PosService
{
    public function __construct(
        private readonly OfferService $offers,
        private readonly InventoryService $inventory,
        private readonly OrderService $orders,
        private readonly CommissionService $commissions,
        private readonly AuditService $audit,
    ) {}

    /**
     * Counter price of one product (or one of its variations).
     *
     * @return array{price: float, original_price: float, tax_name: ?string, tax_rate: float, tax_amount: float, price_with_tax: float}
     */
    public function quote(Product $product, ?ProductVariation $variation = null, ?float $override = null): array
    {
        $priceData = $this->offers->priceAfterOffer($product, $variation);
        $price = $override ?? (float) $priceData['final_price'];

        $taxable = $product->tax && $product->tax_status === 'taxable';
        $rate = $taxable ? (float) $product->tax->rate : 0.0;
        $tax = $taxable ? ($price * $rate) / 100 : 0.0;

        return [
            'price' => round($price, 2),
            'original_price' => round((float) $priceData['original_price'], 2),
            'tax_name' => $taxable ? $product->tax->name : null,
            'tax_rate' => $rate,
            'tax_amount' => round($tax, 2),
            'price_with_tax' => round($price + $tax, 2),
        ];
    }

    /** Products not sold over a counter: bundle listings and external links. */
    public function isSellable(Product $product): bool
    {
        return ! $product->isGrouped() && ! $product->isExternal();
    }

    /**
     * Ring up a sale.
     *
     * @param  array  $data  Validated request: items, customer, discount,
     *                       payment_method, amount_received, payment_reference, note.
     *
     * @throws RuntimeException with a message fit for the cashier.
     */
    public function sell(Admin $seller, Branch $branch, array $data): Order
    {
        $lines = $this->priceLines($data['items'], $seller);

        $subtotal = round(array_sum(array_column($lines, 'subtotal')), 2);
        $totalTax = round(array_sum(array_column($lines, 'total_tax')), 2);
        $discount = round((float) ($data['discount'] ?? 0), 2);

        if ($discount > 0 && ! $seller->can('pos.discount')) {
            throw new PosPermissionException('You do not have permission to give a discount');
        }

        if ($discount > $subtotal) {
            throw new RuntimeException('The discount cannot be more than the sale total');
        }

        $total = round(max(0, $subtotal - $discount), 2);

        $method = $data['payment_method'];
        $received = $method === 'cash' ? round((float) ($data['amount_received'] ?? 0), 2) : $total;

        if ($method === 'cash' && $received < $total) {
            throw new RuntimeException('The cash received is less than the amount due');
        }

        $customer = $this->customerDetails($data['customer'] ?? [], $branch);

        $order = DB::transaction(function () use ($seller, $branch, $data, $lines, $subtotal, $totalTax, $discount, $total, $method, $received, $customer) {
            $order = Order::create([
                'company_id' => Company::current()->id,
                'branch_id' => $branch->id,
                'channel' => Order::CHANNEL_POS,
                'order_number' => $this->orders->nextOrderNumber(),
                'user_id' => $customer['user_id'],
                'guest_id' => 0,
                'status' => Status::ORDER_DELIVERED,
                'payment_status' => Status::PAYMENT_SUCCESS,
                'payment_method' => $method,
                'payment_reference' => $data['payment_reference'] ?? null,
                'subtotal' => $subtotal,
                'shipping_charge' => 0,
                'total_tax' => $totalTax,
                'discount' => $discount,
                'total' => $total,
                'amount_received' => $received,
                'change_due' => round($received - $total, 2),
                'cod' => false,
                'coupon_id' => 0,
                'shipping_address' => $customer['address'],
                'note' => $data['note'] ?? null,
                'processed_by' => $seller->id,
                'sold_by' => $seller->id,
                'delivered_at' => now(),
            ]);

            foreach ($lines as $line) {
                OrderItem::create(['order_id' => $order->id] + $line['item']);
            }

            $order->load('orderItems');

            // Locks the branch rows, checks free stock and deducts it — any
            // shortfall throws and rolls the whole sale back.
            $this->inventory->sellAtCounter($order);

            OrderStatusLog::create([
                'order_id' => $order->id,
                'branch_id' => $branch->id,
                'from_status' => null,
                'to_status' => Status::ORDER_DELIVERED,
                'actor_type' => 'admin',
                'actor_id' => $seller->id,
                'actor_name' => $seller->name,
                'remark' => "Sold at the counter by {$seller->name}",
            ]);

            return $order;
        });

        $this->audit->log(
            'order.pos_sale',
            $order,
            newValues: ['total' => $total, 'payment_method' => $method],
            description: "Counter sale {$order->order_number} at {$branch->name}",
            branchId: $branch->id,
        );

        // Outside the transaction, as for online deliveries: a problem
        // recording commission must not undo a sale the customer has paid for.
        $this->commissions->recordForDelivery($order->fresh());

        return $order->fresh();
    }

    /**
     * Price every requested line and build its order item.
     *
     * @return array<int, array{subtotal: float, total_tax: float, item: array}>
     */
    private function priceLines(array $items, Admin $seller): array
    {
        $products = Product::with(['tax', 'categories:id', 'offers', 'campaigns'])
            ->whereIn('id', array_column($items, 'product_id'))
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($items as $index => $item) {
            $position = $index + 1;
            $product = $products->get((int) $item['product_id']);

            if (! $product || ! $product->status) {
                throw new RuntimeException("Item {$position}: this product is not available for sale");
            }

            if (! $this->isSellable($product)) {
                throw new RuntimeException("\"{$product->name}\" cannot be sold at the counter");
            }

            $variation = null;

            if ($product->isVariable()) {
                $variation = empty($item['variation_id'])
                    ? null
                    : ProductVariation::where('product_id', $product->id)->find((int) $item['variation_id']);

                if (! $variation) {
                    throw new RuntimeException("Choose an option for \"{$product->name}\"");
                }
            }

            $listPrice = $this->quote($product, $variation)['price'];
            $override = isset($item['unit_price']) && $item['unit_price'] !== null && $item['unit_price'] !== ''
                ? round((float) $item['unit_price'], 2)
                : null;

            if ($override !== null && abs($override - $listPrice) >= 0.01) {
                if (! $seller->can('pos.discount')) {
                    throw new PosPermissionException('You do not have permission to change prices');
                }
            } else {
                $override = null;
            }

            $quote = $this->quote($product, $variation, $override);
            $quantity = (int) $item['quantity'];

            $lines[] = [
                'subtotal' => $quote['price_with_tax'] * $quantity,
                'total_tax' => $quote['tax_amount'] * $quantity,
                'item' => [
                    'product_id' => $product->id,
                    'variation_id' => $variation?->id ?? 0,
                    'attribute_values' => $variation ? json_encode($variation->attribute_values) : null,
                    'product_name' => $product->name,
                    'sku' => $variation?->sku ?: $product->sku,
                    'quantity' => $quantity,
                    'price' => $quote['price'],
                    'subtotal' => round($quote['price_with_tax'] * $quantity, 2),
                    'total_tax' => round($quote['tax_amount'] * $quantity, 2),
                ],
            ];
        }

        return $lines;
    }

    /**
     * Who the sale is for: a registered customer, a walk-in who gave a name or
     * phone number, or nobody in particular. Walk-ins are recorded on the
     * order only — no account is created for them.
     *
     * @return array{user_id: int, address: array}
     */
    private function customerDetails(array $customer, Branch $branch): array
    {
        $base = [
            'address' => trim((string) $branch->address) ?: $branch->name,
            'city' => $branch->city,
            'country_name' => 'Tanzania',
            'counter_sale' => true,
        ];

        if (! empty($customer['user_id'])) {
            $user = User::find((int) $customer['user_id']);

            if (! $user) {
                throw new RuntimeException('That customer could not be found');
            }

            return [
                'user_id' => $user->id,
                'address' => [
                    'name' => $user->fullname,
                    'mobile' => $user->mobile,
                    'email' => $user->email,
                ] + $base,
            ];
        }

        $name = trim((string) ($customer['name'] ?? ''));
        $mobile = trim((string) ($customer['mobile'] ?? ''));

        return [
            'user_id' => 0,
            'address' => [
                'name' => $name !== '' ? $name : 'Walk-in customer',
                'mobile' => $mobile !== '' ? $mobile : null,
                'walk_in' => true,
            ] + $base,
        ];
    }

    /** Everything a printed receipt shows. */
    public function receipt(Order $order): array
    {
        $order->loadMissing(['orderItems.variation', 'branch', 'soldBy', 'user', 'company']);
        $company = $order->company ?? Company::current();
        $address = (array) ($order->shipping_address ?? []);

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'channel' => $order->channel,
            'status' => (int) $order->status,
            'status_label' => $order->status_label,
            'created_at' => $order->created_at?->toIso8601String(),
            'company' => [
                'name' => $company->name,
                'legal_name' => $company->legal_name,
                'tin' => $company->tin,
                'vrn' => $company->vrn,
                'phone' => $company->phone,
                'email' => $company->email,
                'address' => $company->address,
                'city' => $company->city,
                'logo' => $company->logo ? fileUrl('logoIcon', $company->logo) : null,
            ],
            'branch' => $order->branch ? [
                'id' => $order->branch->id,
                'name' => $order->branch->name,
                'code' => $order->branch->code,
                'address' => $order->branch->address,
                'city' => $order->branch->city,
                'phone' => $order->branch->phone,
            ] : null,
            'seller' => $order->soldBy?->name,
            'customer' => [
                'id' => $order->user_id ?: null,
                'name' => $order->user?->fullname ?? ($address['name'] ?? 'Walk-in customer'),
                'mobile' => $order->user?->mobile ?? ($address['mobile'] ?? null),
            ],
            'items' => $order->orderItems->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->product_name,
                'sku' => $item->sku,
                'variation' => $item->variation?->name,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->price,
                'unit_price_with_tax' => $item->quantity ? round((float) $item->subtotal / $item->quantity, 2) : 0.0,
                'tax' => (float) $item->total_tax,
                'total' => (float) $item->subtotal,
            ])->values()->all(),
            'totals' => [
                'net' => round((float) $order->subtotal - (float) $order->total_tax, 2),
                'tax' => (float) $order->total_tax,
                'subtotal' => (float) $order->subtotal,
                'discount' => (float) $order->discount,
                'total' => (float) $order->total,
            ],
            'payment' => [
                'method' => $order->payment_method,
                'label' => $order->payment_method_label,
                'reference' => $order->payment_reference,
                'amount_received' => (float) ($order->amount_received ?? $order->total),
                'change_due' => (float) $order->change_due,
            ],
            'note' => $order->note,
        ];
    }
}
