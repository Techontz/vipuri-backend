<?php

namespace Tests;

use App\Constants\Roles;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Database\Seeders\GatewaySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the minimum a test needs: the company, its branches, roles and the
     * settings row every helper reads through `gs()`.
     */
    protected function seedFoundation(): void
    {
        $this->seed(CompanySeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    protected function seedGateways(): void
    {
        $this->seed(GatewaySeeder::class);
    }

    /** Create a staff member with the given role, optionally bound to a branch. */
    protected function makeStaff(string $role, ?Branch $branch = null, array $attributes = []): Admin
    {
        static $sequence = 0;
        $sequence++;

        $admin = Admin::create(array_merge([
            'company_id' => Company::current()->id,
            'branch_id' => $role === Roles::SUPER_ADMIN ? null : ($branch?->id ?? Branch::first()->id),
            'name' => "Test $role $sequence",
            'email' => "staff{$sequence}@vipuri.test",
            'username' => "staff{$sequence}",
            'password' => 'Testing@2026',
            'status' => 1,
        ], $attributes));

        $admin->syncRoles([$role]);

        return $admin->fresh('roles');
    }

    protected function makeCustomer(array $attributes = []): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create(array_merge([
            'firstname' => 'Test',
            'lastname' => "Customer $sequence",
            'username' => "customer{$sequence}",
            'email' => "customer{$sequence}@vipuri.test",
            'dial_code' => '+255',
            'mobile' => '75400000' . $sequence,
            'password' => 'Testing@2026',
            'status' => 1,
            'ev' => 1,
            'sv' => 1,
            'profile_complete' => 1,
        ], $attributes));
    }

    /** A minimal, purchasable simple product with stock at the given branch. */
    protected function makeProduct(Branch $branch, int $stock = 10, array $attributes = []): Product
    {
        static $sequence = 0;
        $sequence++;

        $product = Product::create(array_merge([
            'company_id' => Company::current()->id,
            'brand_id' => 0,
            'name' => "Test Part $sequence",
            'product_type' => 'simple',
            'status' => 1,
            'regular_price' => 100000,
            'sale_price' => 0,
            'sku' => "TEST-{$sequence}",
            'inventory_type' => 1,
            'stock_quantity' => $stock,
            'min_stock_quantity' => 0,
            'low_stock_activity' => 0,
            'min_cart_quantity' => 1,
            'max_cart_quantity' => 50,
            'allow_backorder' => 0,
            'tax_status' => 'none',
        ], $attributes));

        $product->branchInventories()->create([
            'branch_id' => $branch->id,
            'variation_id' => 0,
            'stock_quantity' => $stock,
            'reserved_quantity' => 0,
            'min_stock_quantity' => 0,
        ]);

        return $product->fresh();
    }

    /**
     * Drop any user the auth guards have already resolved.
     *
     * A guard caches its user for the lifetime of the container, and the
     * container survives between requests inside one test method. Without this,
     * the second request in a test silently reuses the first caller's identity
     * — which would make a cross-account test pass no matter what the code does.
     * Every real request gets a fresh container, so this only restores in tests
     * what production already guarantees.
     */
    protected function forgetAuthenticatedUser(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /** Authorization header for a staff member. */
    protected function adminHeaders(Admin $admin): array
    {
        $this->forgetAuthenticatedUser();

        $abilities = $admin->getAllPermissions()->pluck('name')->all() ?: ['*'];

        return ['Authorization' => 'Bearer ' . $admin->createToken('test', $abilities)->plainTextToken];
    }

    /** Authorization header for a customer. */
    protected function userHeaders(User $user): array
    {
        $this->forgetAuthenticatedUser();

        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    /** Guest cart header. */
    protected function cartHeaders(string $token = 'test-cart-token'): array
    {
        return ['X-Cart-Token' => $token];
    }
}
