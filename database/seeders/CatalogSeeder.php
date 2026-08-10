<?php

namespace Database\Seeders;

use App\Constants\Status;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ShippingClass;
use App\Models\StockUnit;
use App\Models\Tax;
use Database\Seeders\Support\PlaceholderImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Auto-parts catalogue for the Tanzanian market: categories, brands, taxes,
 * units, attributes and a realistic set of products with vehicle fitment.
 */
class CatalogSeeder extends Seeder
{
    /** Vehicles commonly on Tanzanian roads, used for fitment data. */
    private const VEHICLES = [
        ['Toyota', 'Hilux', [2015, 2018, 2020, 2022], ['2.4L', '2.8L'], ['Diesel']],
        ['Toyota', 'Land Cruiser Prado', [2014, 2017, 2019, 2021], ['2.8L', '4.0L'], ['Diesel', 'Petrol']],
        ['Toyota', 'RAV4', [2016, 2019, 2021], ['2.0L', '2.5L'], ['Petrol', 'Hybrid']],
        ['Toyota', 'Corolla', [2014, 2017, 2020], ['1.6L', '1.8L'], ['Petrol']],
        ['Toyota', 'Noah', [2013, 2016, 2019], ['2.0L'], ['Petrol']],
        ['Nissan', 'Navara', [2016, 2019, 2021], ['2.3L', '2.5L'], ['Diesel']],
        ['Nissan', 'X-Trail', [2015, 2018, 2021], ['2.0L', '2.5L'], ['Petrol']],
        ['Mitsubishi', 'Pajero', [2014, 2017, 2020], ['3.2L'], ['Diesel']],
        ['Isuzu', 'D-Max', [2016, 2019, 2022], ['2.5L', '3.0L'], ['Diesel']],
        ['Suzuki', 'Alto', [2015, 2018, 2021], ['0.8L', '1.0L'], ['Petrol']],
        ['Land Rover', 'Defender', [2017, 2020, 2022], ['2.0L', '3.0L'], ['Diesel', 'Petrol']],
    ];

    public function run(): void
    {
        $company = Company::current();

        $this->taxesAndUnits();
        $brands = $this->brands();
        $categories = $this->categories();
        $attributes = $this->attributes();

        $this->products($company, $brands, $categories, $attributes);
    }

    private function taxesAndUnits(): void
    {
        Tax::updateOrCreate(['name' => 'VAT 18%'], ['rate' => 18, 'status' => Status::ENABLE]);
        Tax::updateOrCreate(['name' => 'Zero rated'], ['rate' => 0, 'status' => Status::ENABLE]);

        foreach (['Piece', 'Set', 'Pair', 'Litre', 'Box', 'Metre'] as $unit) {
            StockUnit::updateOrCreate(['name' => $unit], ['status' => Status::ENABLE]);
        }

        foreach (['Standard', 'Bulky', 'Fragile', 'Hazardous'] as $class) {
            ShippingClass::updateOrCreate(['name' => $class], ['status' => Status::ENABLE]);
        }
    }

    /** @return array<string, Brand> */
    private function brands(): array
    {
        $brandNames = [
            'Toyota Genuine Parts' => true,
            'Denso' => true,
            'Bosch' => true,
            'NGK' => true,
            'Aisin' => false,
            'KYB' => true,
            'Exide' => false,
            'Michelin' => true,
            'Dunlop' => false,
            'Castrol' => true,
            'Total Energies' => false,
            'Nissan Genuine Parts' => false,
            'Mitsubishi Motors Parts' => false,
            'Isuzu Genuine Parts' => false,
        ];

        $brands = [];
        $index = 0;

        foreach ($brandNames as $name => $popular) {
            $brands[$name] = Brand::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'logo' => Brand::where('slug', Str::slug($name))->value('logo')
                        ?: PlaceholderImage::make('brand', $name, $index),
                    'is_popular' => $popular,
                    'status' => Status::ENABLE,
                    'seo_content' => [
                        'title' => "$name spare parts in Tanzania | VIPURI",
                        'description' => "Buy genuine $name spare parts and accessories from VIPURI branches across Tanzania.",
                    ],
                ],
            );

            $index++;
        }

        return $brands;
    }

    /** @return array<string, Category> */
    private function categories(): array
    {
        $tree = [
            'Engine Parts' => [
                'icon' => true, 'top' => true, 'popular' => true,
                'children' => ['Filters', 'Belts & Hoses', 'Gaskets & Seals', 'Pistons & Rings', 'Cooling System'],
            ],
            'Brake System' => [
                'icon' => true, 'top' => true, 'popular' => true,
                'children' => ['Brake Pads', 'Brake Discs', 'Brake Fluid', 'Brake Callipers'],
            ],
            'Suspension & Steering' => [
                'icon' => true, 'top' => true, 'popular' => true,
                'children' => ['Shock Absorbers', 'Ball Joints', 'Control Arms', 'Steering Racks'],
            ],
            'Electrical' => [
                'icon' => true, 'top' => true, 'popular' => true,
                'children' => ['Batteries', 'Alternators', 'Starters', 'Spark Plugs', 'Sensors'],
            ],
            'Tyres & Wheels' => [
                'icon' => true, 'top' => true, 'popular' => true,
                'children' => ['Tyres', 'Alloy Rims', 'Wheel Bearings'],
            ],
            'Body & Exterior' => [
                'icon' => true, 'top' => false, 'popular' => true,
                'children' => ['Headlights', 'Mirrors', 'Bumpers', 'Wipers'],
            ],
            'Interior & Accessories' => [
                'icon' => true, 'top' => false, 'popular' => true,
                'children' => ['Seat Covers', 'Floor Mats', 'Car Audio', 'Dash Cameras'],
            ],
            'Lubricants & Fluids' => [
                'icon' => true, 'top' => true, 'popular' => true,
                'children' => ['Engine Oil', 'Gear Oil', 'Coolant', 'Grease'],
            ],
            'Tools & Garage' => [
                'icon' => true, 'top' => false, 'popular' => false,
                'children' => ['Hand Tools', 'Jacks & Stands', 'Diagnostic Tools'],
            ],
        ];

        $categories = [];
        $position = 1;
        $index = 0;

        foreach ($tree as $name => $config) {
            $parent = Category::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'parent_id' => null,
                    'position' => $position++,
                    'status' => Status::ENABLE,
                    'show_in_navbar' => Status::YES,
                    'is_top' => $config['top'] ? Status::YES : Status::NO,
                    'is_popular' => $config['popular'] ? Status::YES : Status::NO,
                    'description' => "$name for cars, pickups and SUVs — stocked across VIPURI branches.",
                    'icon' => Category::where('slug', Str::slug($name))->value('icon') ?: PlaceholderImage::make('category', $name, $index),
                    'image' => Category::where('slug', Str::slug($name))->value('image') ?: PlaceholderImage::make('category', $name, $index, withThumb: true),
                    'meta_title' => "$name in Tanzania | VIPURI",
                    'meta_description' => "Shop $name at VIPURI. Genuine parts, fair prices, delivery countrywide.",
                ],
            );

            $categories[$name] = $parent;
            $childPosition = 1;

            foreach ($config['children'] as $childName) {
                $categories[$childName] = Category::updateOrCreate(
                    ['slug' => Str::slug($childName)],
                    [
                        'name' => $childName,
                        'parent_id' => $parent->id,
                        'position' => $childPosition++,
                        'status' => Status::ENABLE,
                        'show_in_navbar' => Status::YES,
                        'is_top' => Status::NO,
                        'is_popular' => Status::NO,
                        'icon' => Category::where('slug', Str::slug($childName))->value('icon') ?: PlaceholderImage::make('category', $childName, $index + $childPosition),
                        'meta_title' => "$childName | VIPURI",
                    ],
                );
            }

            $index++;
        }

        return $categories;
    }

    /** @return array<string, Attribute> */
    private function attributes(): array
    {
        $definitions = [
            'Size' => ['control_type' => 'button', 'values' => ['Small', 'Medium', 'Large', 'X-Large']],
            'Colour' => ['control_type' => 'color', 'values' => [
                'Black' => '#111827', 'Silver' => '#c0c4cc', 'Grey' => '#6b7280', 'Beige' => '#e8ddc8',
            ]],
            'Volume' => ['control_type' => 'dropdown', 'values' => ['1 Litre', '4 Litres', '5 Litres', '20 Litres']],
            'Position' => ['control_type' => 'button', 'values' => ['Front', 'Rear', 'Left', 'Right']],
        ];

        $attributes = [];

        foreach ($definitions as $name => $config) {
            $attribute = Attribute::updateOrCreate(
                ['name' => $name],
                ['control_type' => $config['control_type'], 'is_global' => Status::YES],
            );

            $first = true;

            foreach ($config['values'] as $key => $value) {
                $isColour = is_string($key);

                AttributeValue::updateOrCreate(
                    ['attribute_id' => $attribute->id, 'name' => $isColour ? $key : $value],
                    [
                        'color_code' => $isColour ? $value : null,
                        'is_pre_selected' => $first ? Status::YES : Status::NO,
                    ],
                );

                $first = false;
            }

            $attributes[$name] = $attribute->load('values');
        }

        return $attributes;
    }

    private function products(Company $company, array $brands, array $categories, array $attributes): void
    {
        // [name, category, brand, regular price (TZS), sale price, unit, tax?, type]
        $catalogue = [
            ['Brake Pad Set — Front', 'Brake Pads', 'Toyota Genuine Parts', 145000, 129000, 'Set', true, 'simple'],
            ['Brake Pad Set — Rear', 'Brake Pads', 'Toyota Genuine Parts', 132000, 0, 'Set', true, 'simple'],
            ['Ventilated Brake Disc', 'Brake Discs', 'Bosch', 210000, 189000, 'Piece', true, 'simple'],
            ['DOT 4 Brake Fluid 1L', 'Brake Fluid', 'Castrol', 24000, 0, 'Litre', true, 'simple'],
            ['Oil Filter', 'Filters', 'Denso', 28000, 24500, 'Piece', true, 'simple'],
            ['Air Filter Element', 'Filters', 'Denso', 46000, 0, 'Piece', true, 'simple'],
            ['Cabin Pollen Filter', 'Filters', 'Bosch', 52000, 45000, 'Piece', true, 'simple'],
            ['Fuel Filter Assembly', 'Filters', 'Bosch', 88000, 0, 'Piece', true, 'simple'],
            ['Timing Belt Kit', 'Belts & Hoses', 'Aisin', 385000, 349000, 'Set', true, 'simple'],
            ['Alternator Drive Belt', 'Belts & Hoses', 'Bosch', 62000, 0, 'Piece', true, 'simple'],
            ['Radiator Upper Hose', 'Cooling System', 'Toyota Genuine Parts', 74000, 0, 'Piece', true, 'simple'],
            ['Cylinder Head Gasket', 'Gaskets & Seals', 'Aisin', 268000, 0, 'Piece', true, 'simple'],
            ['Water Pump Assembly', 'Cooling System', 'Aisin', 315000, 285000, 'Piece', true, 'simple'],
            ['Front Shock Absorber', 'Shock Absorbers', 'KYB', 295000, 265000, 'Piece', true, 'variable'],
            ['Rear Shock Absorber', 'Shock Absorbers', 'KYB', 275000, 0, 'Piece', true, 'variable'],
            ['Lower Ball Joint', 'Ball Joints', 'Toyota Genuine Parts', 98000, 0, 'Piece', true, 'simple'],
            ['Front Lower Control Arm', 'Control Arms', 'Nissan Genuine Parts', 340000, 310000, 'Piece', true, 'simple'],
            ['Maintenance-Free Battery 70Ah', 'Batteries', 'Exide', 385000, 359000, 'Piece', true, 'simple'],
            ['Heavy Duty Battery 100Ah', 'Batteries', 'Exide', 520000, 0, 'Piece', true, 'simple'],
            ['Alternator 12V 90A', 'Alternators', 'Denso', 690000, 0, 'Piece', true, 'simple'],
            ['Starter Motor', 'Starters', 'Denso', 640000, 595000, 'Piece', true, 'simple'],
            ['Iridium Spark Plug', 'Spark Plugs', 'NGK', 32000, 28000, 'Piece', true, 'simple'],
            ['Oxygen Sensor', 'Sensors', 'Denso', 245000, 0, 'Piece', true, 'simple'],
            ['All-Terrain Tyre 265/65 R17', 'Tyres', 'Michelin', 480000, 445000, 'Piece', true, 'simple'],
            ['Highway Tyre 205/55 R16', 'Tyres', 'Dunlop', 265000, 0, 'Piece', true, 'simple'],
            ['Alloy Rim 17 inch', 'Alloy Rims', 'Michelin', 420000, 0, 'Piece', true, 'variable'],
            ['Front Wheel Bearing Kit', 'Wheel Bearings', 'Aisin', 165000, 149000, 'Set', true, 'simple'],
            ['LED Headlight Assembly', 'Headlights', 'Bosch', 720000, 649000, 'Piece', true, 'simple'],
            ['Side Mirror with Indicator', 'Mirrors', 'Toyota Genuine Parts', 235000, 0, 'Piece', true, 'variable'],
            ['Front Bumper Guard', 'Bumpers', 'Isuzu Genuine Parts', 545000, 0, 'Piece', true, 'simple'],
            ['Wiper Blade Pair', 'Wipers', 'Bosch', 54000, 47000, 'Pair', true, 'simple'],
            ['Leather Seat Cover Set', 'Seat Covers', 'Toyota Genuine Parts', 380000, 340000, 'Set', true, 'variable'],
            ['All-Weather Floor Mat Set', 'Floor Mats', 'Toyota Genuine Parts', 145000, 0, 'Set', true, 'variable'],
            ['Double DIN Android Head Unit', 'Car Audio', 'Bosch', 690000, 619000, 'Piece', true, 'simple'],
            ['Dual Channel Dash Camera', 'Dash Cameras', 'Bosch', 420000, 0, 'Piece', true, 'simple'],
            ['Fully Synthetic Engine Oil 5W-30', 'Engine Oil', 'Castrol', 118000, 105000, 'Litre', true, 'variable'],
            ['Semi Synthetic Engine Oil 10W-40', 'Engine Oil', 'Total Energies', 92000, 0, 'Litre', true, 'variable'],
            ['Gear Oil 80W-90', 'Gear Oil', 'Total Energies', 78000, 0, 'Litre', true, 'simple'],
            ['Long Life Coolant Concentrate', 'Coolant', 'Castrol', 46000, 41000, 'Litre', true, 'simple'],
            ['Multi-Purpose Grease 500g', 'Grease', 'Total Energies', 22000, 0, 'Piece', true, 'simple'],
            ['Socket Wrench Set 108pc', 'Hand Tools', 'Bosch', 340000, 299000, 'Set', true, 'simple'],
            ['Hydraulic Trolley Jack 3 Ton', 'Jacks & Stands', 'Bosch', 480000, 0, 'Piece', true, 'simple'],
            ['OBD2 Diagnostic Scanner', 'Diagnostic Tools', 'Bosch', 560000, 499000, 'Piece', true, 'simple'],
            ['Piston Ring Set', 'Pistons & Rings', 'Aisin', 295000, 0, 'Set', true, 'simple'],
            ['Brake Calliper — Front Right', 'Brake Callipers', 'Bosch', 465000, 425000, 'Piece', true, 'simple'],
            ['Power Steering Rack', 'Steering Racks', 'Toyota Genuine Parts', 1250000, 1150000, 'Piece', true, 'simple'],
        ];

        $vat = Tax::where('name', 'VAT 18%')->first();
        $index = 0;

        foreach ($catalogue as [$name, $categoryName, $brandName, $regular, $sale, $unitName, $taxable, $type]) {
            $category = $categories[$categoryName] ?? null;
            $brand = $brands[$brandName] ?? null;
            $unit = StockUnit::where('name', $unitName)->first();

            if (! $category || ! $brand) {
                continue;
            }

            $vehicle = self::VEHICLES[$index % count(self::VEHICLES)];
            $slugBase = Str::slug($name);

            $existing = Product::where('name', $name)->first();

            $product = Product::updateOrCreate(
                ['name' => $name],
                [
                    'company_id' => $company->id,
                    'brand_id' => $brand->id,
                    'product_type' => $type,
                    'status' => Status::ENABLE,
                    'is_featured' => $index % 7 === 0,
                    'show_deals' => $sale > 0 && $index % 3 === 0,
                    'limited_stock' => $index % 11 === 0,
                    'collection_one' => $index % 5 === 0,
                    'collection_two' => $index % 6 === 0,
                    'short_description' => "$name for {$vehicle[0]} {$vehicle[1]}. Genuine quality, backed by the VIPURI warranty and available at branches countrywide.",
                    'description' => $this->description($name, $vehicle),
                    'specifications' => [
                        'key' => ['Part type', 'Fitment', 'Warranty', 'Country of origin', 'Packaging'],
                        'value' => [
                            $categoryName,
                            "{$vehicle[0]} {$vehicle[1]} " . implode('/', $vehicle[2]),
                            '12 months against manufacturing defects',
                            'Japan / Germany',
                            'Sealed manufacturer packaging',
                        ],
                    ],
                    'regular_price' => $regular,
                    'sale_price' => $sale,
                    'tax_class' => $taxable ? ($vat?->id ?? 0) : 0,
                    'tax_status' => $taxable ? 'taxable' : 'none',
                    'show_tax' => Status::YES,
                    'sku' => 'VP-' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                    'inventory_type' => Status::TRACK_INVENTORY,
                    'stock_unit_id' => $unit?->id ?? 0,
                    'display_available' => Status::YES,
                    'display_stock_quantity' => Status::YES,
                    'min_stock_quantity' => 3,
                    'low_stock_activity' => Status::LOW_STOCK_DISABLE_BUY_BUTTON,
                    'threshold_quantity' => 10,
                    'min_cart_quantity' => 1,
                    'max_cart_quantity' => 20,
                    'allow_backorder' => Status::NO,
                    'weight' => round(mt_rand(5, 250) / 10, 2),
                    'length' => 30,
                    'width' => 20,
                    'height' => 15,
                    'shipping_class' => 'Standard',
                    'vehicle_year' => $vehicle[2][array_rand($vehicle[2])],
                    'vehicle_model' => $vehicle[1],
                    'vehicle_engine' => $vehicle[3][array_rand($vehicle[3])],
                    'vehicle_engine_type' => $vehicle[4][array_rand($vehicle[4])],
                    'meta_title' => "$name — {$vehicle[0]} {$vehicle[1]} | VIPURI",
                    'meta_description' => "Buy $name for {$vehicle[0]} {$vehicle[1]} in Tanzania. In stock at VIPURI branches.",
                    'meta_keywords' => implode(', ', [$name, $categoryName, $brandName, $vehicle[0], $vehicle[1], 'spare parts Tanzania']),
                ],
            );

            $product->categories()->syncWithoutDetaching(array_filter([
                $category->id,
                $category->parent_id,
            ]));

            if ($product->media()->count() === 0) {
                foreach (range(0, 2) as $i) {
                    $product->allMedia()->create([
                        'path' => PlaceholderImage::make('product', $name, $index + $i, withThumb: true),
                        'is_main' => $i === 0,
                        'variation_id' => 0,
                    ]);
                }
            }

            if ($type === 'variable') {
                $this->buildVariations($product, $attributes, $regular, $sale, $unit?->id ?? 0);
            }

            $index++;
        }

        $this->buildGroupedAndLinked();
    }

    private function buildVariations(Product $product, array $attributes, float $regular, float $sale, int $unitId): void
    {
        // Choose the attribute that actually makes sense for the product.
        $attributeName = match (true) {
            str_contains(strtolower($product->name), 'oil') => 'Volume',
            str_contains(strtolower($product->name), 'shock') => 'Position',
            str_contains(strtolower($product->name), 'mirror') => 'Position',
            str_contains(strtolower($product->name), 'rim') => 'Size',
            default => 'Colour',
        };

        $attribute = $attributes[$attributeName] ?? null;

        if (! $attribute) {
            return;
        }

        $product->productAttributes()->firstOrCreate(
            ['attribute_id' => $attribute->id],
            ['is_visible' => Status::YES],
        );

        if ($product->variations()->count() > 0) {
            return;
        }

        $step = 0;

        foreach ($attribute->values as $value) {
            $multiplier = 1 + ($step * 0.18);

            $product->variations()->create([
                'attribute_values' => [$value->id],
                'regular_price' => round($regular * $multiplier, -2),
                'sale_price' => $sale > 0 ? round($sale * $multiplier, -2) : 0,
                'sku' => $product->sku . '-' . Str::upper(Str::substr(Str::slug($value->name), 0, 4)),
                'inventory_type' => Status::TRACK_INVENTORY,
                'stock_quantity' => 0,
                'stock_unit_id' => $unitId,
                'display_available' => Status::YES,
                'display_stock_quantity' => Status::YES,
                'min_stock_quantity' => 2,
                'low_stock_activity' => Status::LOW_STOCK_DISABLE_BUY_BUTTON,
                'min_cart_quantity' => 1,
                'max_cart_quantity' => 10,
                'allow_backorder' => Status::NO,
            ]);

            $step++;
        }
    }

    /** A grouped "service kit" product plus up-sell / cross-sell links. */
    private function buildGroupedAndLinked(): void
    {
        $company = Company::current();
        $oilFilter = Product::where('name', 'Oil Filter')->first();
        $airFilter = Product::where('name', 'Air Filter Element')->first();
        $sparkPlug = Product::where('name', 'Iridium Spark Plug')->first();
        $engineOil = Product::where('name', 'Fully Synthetic Engine Oil 5W-30')->first();

        if (! $oilFilter || ! $airFilter || ! $sparkPlug || ! $engineOil) {
            return;
        }

        $kit = Product::updateOrCreate(
            ['name' => 'Full Service Kit — Toyota Hilux'],
            [
                'company_id' => $company->id,
                'brand_id' => $oilFilter->brand_id,
                'product_type' => Status::PRODUCT_GROUPED,
                'status' => Status::ENABLE,
                'is_featured' => true,
                'short_description' => 'Everything needed for a full service on a Toyota Hilux: oil filter, air filter, spark plugs and 5 litres of fully synthetic engine oil.',
                'description' => '<p>Save on a complete service by buying the kit. Each item can also be bought separately.</p>',
                'sku' => 'VP-KIT-001',
                'inventory_type' => Status::DONT_TRACK_INVENTORY,
                'tax_status' => 'taxable',
                'tax_class' => $oilFilter->tax_class,
                'vehicle_year' => 2020,
                'vehicle_model' => 'Hilux',
                'vehicle_engine' => '2.4L',
                'vehicle_engine_type' => 'Diesel',
                'min_cart_quantity' => 1,
                'max_cart_quantity' => 5,
            ],
        );

        if ($kit->media()->count() === 0) {
            $kit->allMedia()->create([
                'path' => PlaceholderImage::make('product', 'Service Kit', 3, withThumb: true),
                'is_main' => true,
                'variation_id' => 0,
            ]);
        }

        $kit->categories()->syncWithoutDetaching(
            Category::whereIn('slug', ['engine-parts', 'filters'])->pluck('id')->all()
        );

        $kit->groupedProducts()->delete();

        foreach ([$oilFilter, $airFilter, $sparkPlug, $engineOil] as $child) {
            $kit->groupedProducts()->create(['grouped_product_id' => $child->id]);
        }

        // Up-sells and cross-sells so the product page sections have real data.
        $brakePads = Product::where('name', 'Brake Pad Set — Front')->first();
        $brakeDisc = Product::where('name', 'Ventilated Brake Disc')->first();
        $brakeFluid = Product::where('name', 'DOT 4 Brake Fluid 1L')->first();

        if ($brakePads && $brakeDisc && $brakeFluid) {
            $brakePads->productUpSells()->delete();
            $brakePads->productUpSells()->create(['up_sell_product_id' => $brakeDisc->id]);

            $brakePads->productCrossSells()->delete();
            $brakePads->productCrossSells()->create(['cross_sell_product_id' => $brakeFluid->id]);
        }

        if ($engineOil && $oilFilter) {
            $engineOil->productCrossSells()->delete();
            $engineOil->productCrossSells()->create(['cross_sell_product_id' => $oilFilter->id]);
        }
    }

    private function description(string $name, array $vehicle): string
    {
        [$make, $model, $years, $engines, $fuels] = $vehicle;
        $yearList = implode(', ', $years);

        return <<<HTML
<p><strong>$name</strong> supplied and fitted by VIPURI. Every part we stock is sourced from an
authorised distributor and carries our 12-month warranty against manufacturing defects.</p>
<h5>Fitment</h5>
<ul>
    <li>Make: $make</li>
    <li>Model: $model</li>
    <li>Model years: $yearList</li>
    <li>Engine: {$engines[0]}</li>
    <li>Fuel: {$fuels[0]}</li>
</ul>
<h5>Why buy from VIPURI</h5>
<ul>
    <li>Genuine stock held at our Dar es Salaam, Arusha, Mwanza and Dodoma branches</li>
    <li>Same-day collection, next-day delivery in Dar es Salaam</li>
    <li>Pay by M-Pesa, Tigo Pesa, Airtel Money, card or bank transfer</li>
    <li>Fitting available at our Nyerere Road workshop</li>
</ul>
<p>Not sure whether this fits your vehicle? Use the vehicle finder above or ask our AI assistant on
this page and we will confirm compatibility before you buy.</p>
HTML;
    }
}
