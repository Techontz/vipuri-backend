<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counter sales (point of sale).
 *
 * Additive only: every existing order becomes `channel = online` through the
 * column default, so the storefront, reports and the orders list keep working
 * unchanged. The payment columns describe how a counter sale was settled;
 * online orders keep recording their payments as deposits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'channel')) {
                $table->string('channel', 20)->default('online')->after('order_number')
                    ->comment('online | pos');
            }
            if (! Schema::hasColumn('orders', 'sold_by')) {
                $table->unsignedBigInteger('sold_by')->nullable()->after('processed_by')
                    ->comment('admins.id — staff member who made a counter sale');
            }
            if (! Schema::hasColumn('orders', 'payment_method')) {
                $table->string('payment_method', 30)->nullable()->after('payment_status')
                    ->comment('Counter sales: cash | mobile_money | card | bank_transfer');
            }
            if (! Schema::hasColumn('orders', 'payment_reference')) {
                $table->string('payment_reference', 100)->nullable()->after('payment_method');
            }
            if (! Schema::hasColumn('orders', 'amount_received')) {
                $table->decimal('amount_received', 28, 8)->nullable()->after('total');
            }
            if (! Schema::hasColumn('orders', 'change_due')) {
                $table->decimal('change_due', 28, 8)->default(0)->after('amount_received');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['channel', 'branch_id', 'created_at'], 'orders_channel_branch_created_index');
            $table->index('sold_by', 'orders_sold_by_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_channel_branch_created_index');
            $table->dropIndex('orders_sold_by_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            foreach (['channel', 'sold_by', 'payment_method', 'payment_reference', 'amount_received', 'change_due'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
