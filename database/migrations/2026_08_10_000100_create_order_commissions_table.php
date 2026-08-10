<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Worker commission on completed orders.
 *
 * `orders.processed_by` records the last member of staff to touch an order.
 * It is overwritten on every status change, so it can say who acted most
 * recently but never who earned anything — reading money out of it would be
 * wrong the moment a second person touches the order.
 *
 * This table is the auditable record instead: one row per order, naming the
 * staff member, the rate and the basis the amount was worked out from, and
 * what has happened to it since. Rows are never rewritten by a later status
 * change — a return reverses a commission, it does not delete it — so the
 * history stays intact for a payroll dispute.
 *
 * Nothing accrues until an administrator sets a rate: `commission_rate`
 * defaults to 0 and `commission_enabled` to false, so an existing installation
 * gains the machinery without gaining any figures nobody agreed to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->default(0)->index();

            // One commission per order. A second delivery event must find the
            // existing row rather than pay twice.
            $table->unsignedBigInteger('order_id')->unique();

            $table->unsignedBigInteger('admin_id')->index()->comment('The staff member who earned it');
            $table->unsignedBigInteger('branch_id')->default(0)->index();

            $table->decimal('rate', 5, 2)->default(0)->comment('Percent, as it stood when the order completed');
            $table->decimal('basis_amount', 18, 2)->default(0)->comment('The order value the rate was applied to');
            $table->decimal('amount', 18, 2)->default(0);

            $table->tinyInteger('status')->default(0)->index()
                ->comment('0 pending, 1 approved, 2 paid, 3 reversed');

            $table->timestamp('earned_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->string('payout_reference', 120)->nullable();
            $table->string('note', 255)->nullable();

            $table->timestamps();

            // The two reads the panel actually makes: one worker's statement,
            // and a branch's totals for a period.
            $table->index(['admin_id', 'status']);
            $table->index(['branch_id', 'earned_at']);
        });

        Schema::table('general_settings', function (Blueprint $table) {
            $table->boolean('commission_enabled')->default(0)->after('ai_product_chat');

            // Deliberately 0: a default percentage would be a number nobody in
            // the business chose, sitting in a financial record.
            $table->decimal('commission_rate', 5, 2)->default(0)->after('commission_enabled')
                ->comment('Percent of the order subtotal');

            $table->string('commission_attribution', 20)->default('delivered_by')->after('commission_rate')
                ->comment('delivered_by | processed_by — which status log names the earner');
        });
    }

    public function down(): void
    {
        Schema::table('general_settings', function (Blueprint $table) {
            $table->dropColumn(['commission_enabled', 'commission_rate', 'commission_attribution']);
        });

        Schema::dropIfExists('order_commissions');
    }
};
