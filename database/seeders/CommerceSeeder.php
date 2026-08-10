<?php

namespace Database\Seeders;

use App\Constants\Status;
use App\Models\Address;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\StockLog;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Shipping configuration, branch inventory, customers, orders and reviews —
 * enough real data for every dashboard, report and storefront screen to have
 * something meaningful to show.
 */
class CommerceSeeder extends Seeder
{
    public function run(): void
    {
        $this->shipping();
        $this->marketing();
        $this->inventory();
        $customers = $this->customers();
        $this->orders($customers);
        $this->reviews($customers);
    }

    private function shipping(): void
    {
        $zones = [
            'Dar es Salaam' => [['Same-day Delivery', 8000, 0, 1], ['Standard Delivery', 5000, 1, 2], ['Branch Pickup', 0, 0, 0]],
            'Coast & Morogoro' => [['Standard Delivery', 15000, 2, 3], ['Bus Courier', 12000, 1, 2]],
            'Northern Zone' => [['Bus Courier', 18000, 2, 3], ['Standard Delivery', 25000, 3, 4]],
            'Lake Zone' => [['Bus Courier', 22000, 3, 4], ['Standard Delivery', 30000, 4, 5]],
            'Central & Southern' => [['Bus Courier', 20000, 3, 4], ['Standard Delivery', 28000, 4, 6]],
        ];

        $methods = [];

        foreach (['Same-day Delivery', 'Standard Delivery', 'Bus Courier', 'Branch Pickup'] as $name) {
            $methods[$name] = ShippingMethod::updateOrCreate(
                ['name' => $name],
                [
                    'description' => match ($name) {
                        'Same-day Delivery' => 'Delivered within Dar es Salaam the same working day for orders placed before 14:00.',
                        'Standard Delivery' => 'Delivered by our own fleet or a partner courier.',
                        'Bus Courier' => 'Sent by bus courier to your nearest town; collect at the depot.',
                        default => 'Collect free of charge from any VIPURI branch.',
                    },
                    'status' => Status::ENABLE,
                ],
            );
        }

        foreach ($zones as $zoneName => $rates) {
            $zone = ShippingZone::updateOrCreate(['name' => $zoneName], ['status' => Status::ENABLE]);

            foreach ($rates as [$methodName, $amount, $minDays, $maxDays]) {
                ShippingRate::updateOrCreate(
                    ['shipping_zone_id' => $zone->id, 'shipping_method_id' => $methods[$methodName]->id],
                    [
                        'amount' => $amount,
                        'min_order_amount' => 0,
                        'max_order_amount' => 0,
                        'expected_delivery_days' => $maxDays,
                        'is_cod' => Status::YES,
                        'status' => Status::ENABLE,
                    ],
                );
            }
        }
    }

    private function marketing(): void
    {
        Coupon::updateOrCreate(
            ['code' => 'KARIBU10'],
            [
                'name' => 'Welcome discount',
                'description' => '10% off your first VIPURI order.',
                'discount_type' => Status::COUPON_DISCOUNT_PERCENT,
                'amount' => 10,
                'max_discount' => 50000,
                'minimum_spend' => 50000,
                'limit_per_customer' => 1,
                'expiry_date' => now()->addYear()->toDateString(),
                'status' => Status::ENABLE,
            ],
        );

        Coupon::updateOrCreate(
            ['code' => 'SHEHENA20K'],
            [
                'name' => 'TZS 20,000 off big baskets',
                'description' => 'Flat TZS 20,000 off orders above TZS 400,000.',
                'discount_type' => Status::COUPON_DISCOUNT_FIXED_CART,
                'amount' => 20000,
                'minimum_spend' => 400000,
                'expiry_date' => now()->addMonths(6)->toDateString(),
                'status' => Status::ENABLE,
            ],
        );

        $offer = Offer::updateOrCreate(
            ['name' => 'Brake Week — 12% off'],
            [
                'description' => 'Twelve percent off every braking component this week.',
                'discount_type' => Status::OFFER_PERCENT,
                'amount' => 12,
                'priority' => 1,
                'start_at' => now()->subDays(2),
                'end_at' => now()->addDays(12),
                'show_on_section' => Status::YES,
                'status' => Status::ENABLE,
            ],
        );

        $brakeCategories = \App\Models\Category::whereIn('slug', [
            'brake-pads', 'brake-discs', 'brake-fluid', 'brake-callipers',
        ])->pluck('id');

        $offer->categories()->sync($brakeCategories);
    }

    /** Spread stock across branches so branch dashboards differ realistically. */
    private function inventory(): void
    {
        $branches = Branch::orderBy('id')->get();
        $products = Product::with('variations')->get();

        if ($branches->isEmpty()) {
            return;
        }

        foreach ($products as $index => $product) {
            if (! $product->trackInventory() && ! $product->isVariable()) {
                continue;
            }

            foreach ($branches as $bIndex => $branch) {
                $variations = $product->variations;

                if ($variations->isNotEmpty()) {
                    foreach ($variations as $vIndex => $variation) {
                        $this->stock($branch->id, $product->id, $variation->id, $this->quantityFor($index + $vIndex, $bIndex));
                    }

                    continue;
                }

                $this->stock($branch->id, $product->id, 0, $this->quantityFor($index, $bIndex));
            }

            app(\App\Services\InventoryService::class)->syncCatalogueTotals($product->id);

            foreach ($product->variations as $variation) {
                app(\App\Services\InventoryService::class)->syncCatalogueTotals($product->id, $variation->id);
            }
        }
    }

    /** Deterministic but varied quantities, including some zero/low rows. */
    private function quantityFor(int $productIndex, int $branchIndex): int
    {
        $seed = ($productIndex * 7 + $branchIndex * 13) % 30;

        return match (true) {
            $seed === 0 => 0,          // out of stock somewhere
            $seed < 3 => $seed,        // low stock
            default => 8 + $seed,
        };
    }

    private function stock(int $branchId, int $productId, int $variationId, int $quantity): void
    {
        $row = BranchInventory::updateOrCreate(
            ['branch_id' => $branchId, 'product_id' => $productId, 'variation_id' => $variationId],
            [
                'stock_quantity' => $quantity,
                'reserved_quantity' => 0,
                'min_stock_quantity' => 5,
                'shelf_location' => 'A' . str_pad((string) (($productId % 20) + 1), 2, '0', STR_PAD_LEFT),
                'cost_price' => round(Product::find($productId)?->regular_price * 0.72 ?? 0, 2),
                'last_counted_at' => now()->subDays(random_int(1, 25)),
            ],
        );

        StockLog::firstOrCreate(
            [
                'branch_id' => $branchId,
                'product_id' => $productId,
                'product_variation_id' => $variationId,
                'remark' => 'opening_stock',
            ],
            [
                'change_quantity' => $quantity,
                'post_quantity' => $quantity,
                'description' => 'Opening stock loaded during setup',
                'actor_type' => 'system',
                'actor_name' => 'Seeder',
            ],
        );
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function customers()
    {
        $password = env('SEED_PASSWORD');

        if (! $password) {
            return collect();
        }

        $people = [
            ['Asha', 'Mwinyi', 'asha.mwinyi', 'asha.mwinyi@example.co.tz', 'Dar es Salaam', '754111001'],
            ['Bakari', 'Juma', 'bakari.juma', 'bakari.juma@example.co.tz', 'Dar es Salaam', '754111002'],
            ['Christina', 'Massawe', 'christina.massawe', 'christina.massawe@example.co.tz', 'Arusha', '754111003'],
            ['David', 'Mahenge', 'david.mahenge', 'david.mahenge@example.co.tz', 'Mwanza', '754111004'],
            ['Esther', 'Ngowi', 'esther.ngowi', 'esther.ngowi@example.co.tz', 'Dodoma', '754111005'],
            ['Frank', 'Kileo', 'frank.kileo', 'frank.kileo@example.co.tz', 'Dar es Salaam', '754111006'],
            ['Halima', 'Said', 'halima.said', 'halima.said@example.co.tz', 'Morogoro', '754111007'],
            ['Ibrahim', 'Mkwawa', 'ibrahim.mkwawa', 'ibrahim.mkwawa@example.co.tz', 'Mbeya', '754111008'],
        ];

        $customers = collect();

        foreach ($people as [$first, $last, $username, $email, $city, $mobile]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'firstname' => $first,
                    'lastname' => $last,
                    'username' => $username,
                    'dial_code' => '+255',
                    'mobile' => $mobile,
                    'password' => $password,
                    'city' => $city,
                    'state' => $city,
                    'country_name' => 'Tanzania',
                    'country_code' => 'TZ',
                    'address' => 'House ' . random_int(10, 300) . ', ' . $city,
                    'status' => Status::USER_ACTIVE,
                    'ev' => Status::VERIFIED,
                    'sv' => Status::VERIFIED,
                    'profile_complete' => Status::YES,
                ],
            );

            Address::updateOrCreate(
                ['user_id' => $user->id, 'title' => 'Home'],
                [
                    'firstname' => $first,
                    'lastname' => $last,
                    'dial_code' => '+255',
                    'mobile' => $mobile,
                    'email' => $email,
                    'address' => 'House ' . random_int(10, 300) . ', ' . $city,
                    'city' => $city,
                    'state' => $city,
                    'country_name' => 'Tanzania',
                    'country_code' => 'TZ',
                    'is_default' => true,
                ],
            );

            $customers->push($user);
        }

        return $customers;
    }

    private function orders($customers): void
    {
        if ($customers->isEmpty() || Order::count() > 0) {
            return;
        }

        $company = Company::current();
        $branches = Branch::orderBy('id')->get();
        $products = Product::where('product_type', '!=', Status::PRODUCT_GROUPED)->with('tax')->get();
        $rate = ShippingRate::with('method')->first();

        $statuses = [
            Status::ORDER_DELIVERED, Status::ORDER_DELIVERED, Status::ORDER_DELIVERED,
            Status::ORDER_DISPATCHED, Status::ORDER_PROCESSING, Status::ORDER_PENDING,
            Status::ORDER_CANCELLED, Status::ORDER_PAID,
        ];

        for ($i = 0; $i < 40; $i++) {
            $customer = $customers[$i % $customers->count()];
            $branch = $branches[$i % $branches->count()];
            $status = $statuses[$i % count($statuses)];
            $placedAt = now()->subDays(random_int(0, 45))->setTime(random_int(8, 19), random_int(0, 59));

            $lineCount = random_int(1, 4);
            $lines = $products->random(min($lineCount, $products->count()));

            $subtotal = 0.0;
            $totalTax = 0.0;
            $items = [];

            foreach ($lines as $product) {
                $quantity = random_int(1, 3);
                $price = (float) $product->display_price;
                $taxRate = $product->tax_status === 'taxable' ? (float) ($product->tax?->rate ?? 0) : 0;
                $taxAmount = ($price * $taxRate) / 100;

                $items[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'price' => $price,
                    'tax' => $taxAmount * $quantity,
                    'subtotal' => ($price + $taxAmount) * $quantity,
                ];

                $subtotal += ($price + $taxAmount) * $quantity;
                $totalTax += $taxAmount * $quantity;
            }

            $shipping = (float) ($rate?->amount ?? 0);
            $discount = $i % 5 === 0 ? 20000.0 : 0.0;
            $cod = $i % 4 === 0;

            $paymentStatus = match ($status) {
                Status::ORDER_DELIVERED, Status::ORDER_PAID, Status::ORDER_DISPATCHED => Status::PAYMENT_SUCCESS,
                Status::ORDER_CANCELLED => Status::PAYMENT_REJECT,
                default => Status::PAYMENT_PENDING,
            };

            $order = Order::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'shipping_method_id' => $rate?->shipping_method_id,
                'order_number' => 'VP' . $placedAt->format('ymd') . Str::upper(Str::random(6)),
                'user_id' => $customer->id,
                'guest_id' => 0,
                'status' => $status,
                'payment_status' => $paymentStatus,
                'subtotal' => round($subtotal, 2),
                'shipping_charge' => $shipping,
                'total_tax' => round($totalTax, 2),
                'discount' => $discount,
                'total' => round($subtotal - $discount + $shipping, 2),
                'cod' => $cod,
                'coupon_id' => 0,
                'shipping_address' => [
                    'firstname' => $customer->firstname,
                    'lastname' => $customer->lastname,
                    'email' => $customer->email,
                    'dial_code' => $customer->dial_code,
                    'mobile' => $customer->mobile,
                    'address' => $customer->address,
                    'city' => $customer->city,
                    'state' => $customer->state,
                    'country_name' => 'Tanzania',
                    'country_code' => 'TZ',
                ],
                'created_at' => $placedAt,
                'updated_at' => $placedAt,
                'dispatched_at' => in_array($status, [Status::ORDER_DISPATCHED, Status::ORDER_DELIVERED], true)
                    ? $placedAt->copy()->addDay() : null,
                'delivered_at' => $status === Status::ORDER_DELIVERED ? $placedAt->copy()->addDays(2) : null,
                'cancelled_at' => $status === Status::ORDER_CANCELLED ? $placedAt->copy()->addHours(6) : null,
                'cancel_reason' => $status === Status::ORDER_CANCELLED ? 'Customer changed their mind' : null,
            ]);

            foreach ($items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product']->id,
                    'variation_id' => 0,
                    'product_name' => $item['product']->name,
                    'sku' => $item['product']->sku,
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'subtotal' => $item['subtotal'],
                    'total_tax' => $item['tax'],
                    'created_at' => $placedAt,
                    'updated_at' => $placedAt,
                ]);
            }

            OrderStatusLog::create([
                'order_id' => $order->id,
                'branch_id' => $branch->id,
                'from_status' => null,
                'to_status' => Status::ORDER_PENDING,
                'actor_type' => 'user',
                'actor_id' => $customer->id,
                'actor_name' => $customer->fullname,
                'remark' => 'Order placed',
                'created_at' => $placedAt,
                'updated_at' => $placedAt,
            ]);

            if ($status !== Status::ORDER_PENDING) {
                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'branch_id' => $branch->id,
                    'from_status' => Status::ORDER_PENDING,
                    'to_status' => $status,
                    'actor_type' => 'admin',
                    'actor_name' => 'Branch staff',
                    'remark' => Status::ORDER_STATUS_LABELS[$status] ?? null,
                    'created_at' => $placedAt->copy()->addDay(),
                    'updated_at' => $placedAt->copy()->addDay(),
                ]);
            }
        }
    }

    private function reviews($customers): void
    {
        if ($customers->isEmpty() || ProductReview::count() > 0) {
            return;
        }

        $texts = [
            5 => [
                'Genuine part, fitted perfectly on my Hilux. Delivery to Kariakoo took one day.',
                'Exactly what I ordered and the price is fair compared to other shops in town.',
                'Great service from the Arusha branch — they even confirmed the fitment before I paid.',
            ],
            4 => [
                'Good quality. Packaging could be better but the part itself is solid.',
                'Works well so far. Delivery to Mwanza took three days by bus courier.',
            ],
            3 => [
                'Part is fine but I expected a slightly lower price.',
                'Average. Took a while to arrive but the staff kept me updated.',
            ],
        ];

        $products = Product::inRandomOrder()->limit(24)->get();

        foreach ($products as $index => $product) {
            $reviewCount = random_int(1, 3);

            for ($i = 0; $i < $reviewCount; $i++) {
                $customer = $customers[($index + $i) % $customers->count()];
                $rating = [5, 5, 4, 5, 4, 3][($index + $i) % 6];

                ProductReview::firstOrCreate(
                    ['user_id' => $customer->id, 'product_id' => $product->id],
                    [
                        'rating' => $rating,
                        'review' => $texts[$rating][($index + $i) % count($texts[$rating])],
                        'status' => $i === 0 ? Status::REVIEW_APPROVED : ($index % 5 === 0 ? Status::REVIEW_PENDING : Status::REVIEW_APPROVED),
                        'is_viewed' => 1,
                        'created_at' => now()->subDays(random_int(1, 40)),
                    ],
                );
            }
        }
    }
}
