<?php

use App\Http\Controllers\Api\Account\AccountController;
use App\Http\Controllers\Api\Account\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\BranchController;
use App\Http\Controllers\Api\Admin\CommissionController;
use App\Http\Controllers\Api\Admin\CustomerController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\InventoryController;
use App\Http\Controllers\Api\Admin\MarketingController;
use App\Http\Controllers\Api\Admin\OperationsController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Admin\SettingController;
use App\Http\Controllers\Api\Admin\StaffController;
use App\Http\Controllers\Api\Admin\TaxonomyController;
use App\Http\Controllers\Api\Shop\AiController;
use App\Http\Controllers\Api\Shop\CartController;
use App\Http\Controllers\Api\Shop\CatalogController;
use App\Http\Controllers\Api\Shop\CheckoutController;
use App\Http\Controllers\Api\Shop\PaymentCallbackController;
use App\Http\Controllers\Api\Shop\SiteController;
use App\Http\Controllers\Api\Shop\WishlistController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| VIPURI API
|--------------------------------------------------------------------------
| All routes are consumed by the Next.js frontend. Two guards:
|   auth:user   — storefront customers
|   auth:admin  — VIPURI staff (super admin, branch manager, branch worker)
*/

Route::prefix('v1')->group(function () {

    /* ================================================================== *
     | Public storefront
     * ================================================================== */

    Route::get('settings', [SiteController::class, 'settings']);
    Route::get('captcha', [SiteController::class, 'captcha'])->middleware('throttle:60,1');
    Route::get('translations/{code}', [SiteController::class, 'translations'])->where('code', '[A-Za-z_-]{2,20}');
    Route::get('branches', [SiteController::class, 'branches']);

    Route::middleware('shop.open')->group(function () {
        Route::get('home', [SiteController::class, 'home']);
        Route::get('pages/{slug}', [SiteController::class, 'page']);
        Route::get('policy/{slug}', [SiteController::class, 'policy']);
        Route::get('blogs', [SiteController::class, 'blogs']);
        Route::get('blogs/{slug}', [SiteController::class, 'blog']);
        Route::post('subscribe', [SiteController::class, 'subscribe']);
        Route::post('contact', [SiteController::class, 'contact'])->middleware('throttle:10,1');

        // Catalogue
        Route::get('categories', [CatalogController::class, 'categories']);
        Route::get('categories/{slug}', [CatalogController::class, 'category']);
        Route::get('brands', [CatalogController::class, 'brands']);
        Route::get('offers', [CatalogController::class, 'offers']);
        Route::get('products', [CatalogController::class, 'products']);
        Route::get('products/vehicle-filters', [CatalogController::class, 'vehicleFilters']);
        Route::get('products/{slug}', [CatalogController::class, 'show']);
        Route::get('products/{slug}/quick-view', [CatalogController::class, 'quickView']);
        Route::get('products/{id}/reviews', [CatalogController::class, 'reviews'])->whereNumber('id');

        // AI (rate limited — these calls cost money)
        Route::middleware('throttle:20,1')->group(function () {
            // Signed-in only: the endpoint forwards a caller-supplied prompt to
            // the configured model on the company's account, so leaving it open
            // to anonymous traffic hands out the AI budget to anybody.
            Route::post('ai/review-summary', [AiController::class, 'reviewSummary'])
                ->middleware('auth:user');
            Route::post('ai/chat', [AiController::class, 'chat']);
        });

        // Cart / wishlist — work for guests via the X-Cart-Token header
        Route::prefix('cart')->group(function () {
            Route::get('/', [CartController::class, 'index']);
            Route::post('add/{slug}', [CartController::class, 'add']);
            Route::post('update', [CartController::class, 'update']);
            Route::post('remove', [CartController::class, 'remove']);
            Route::post('clear', [CartController::class, 'clear']);
            Route::get('shipping-rates', [CartController::class, 'shippingRates']);
            Route::post('shipping-rate', [CartController::class, 'chooseShippingRate']);
            Route::post('branch', [CartController::class, 'chooseBranch']);
            Route::post('coupon', [CartController::class, 'applyCoupon']);
            Route::delete('coupon', [CartController::class, 'removeCoupon']);
        });

        Route::prefix('wishlist')->group(function () {
            Route::get('/', [WishlistController::class, 'index']);
            Route::post('add/{productId}', [WishlistController::class, 'add'])->whereNumber('productId');
            Route::post('remove', [WishlistController::class, 'remove']);
        });

        // Checkout + orders
        Route::prefix('checkout')->group(function () {
            Route::get('/', [CheckoutController::class, 'index']);
            Route::post('/', [CheckoutController::class, 'store']);
            Route::get('{orderNumber}/payment-methods', [CheckoutController::class, 'paymentMethods']);
            Route::post('{orderNumber}/pay', [CheckoutController::class, 'pay']);
            Route::post('{orderNumber}/mobile-money', [CheckoutController::class, 'mobileMoney']);
        });

        Route::post('payment/manual/{trx}', [CheckoutController::class, 'submitManualPayment']);
        Route::get('payment/confirm/{trx}', [PaymentCallbackController::class, 'confirm']);
        Route::get('orders/{orderNumber}/confirmation', [CheckoutController::class, 'orderConfirmation']);
        Route::get('orders/{orderNumber}/track', [CheckoutController::class, 'track']);
    });

    // Gateway webhooks — no session, verified against the provider by the driver.
    // Exempt from the per-audience guest limit (a provider is not a browser),
    // but not unlimited: every hit costs one outbound call to the provider.
    Route::any('ipn/{alias}', [PaymentCallbackController::class, 'handle'])
        ->withoutMiddleware('throttle:api')
        ->middleware('throttle:ipn');

    /* ================================================================== *
     | Customer authentication
     * ================================================================== */

    Route::prefix('auth')->group(function () {
        Route::post('register', [CustomerAuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('login', [CustomerAuthController::class, 'login'])->middleware('throttle:20,1');
        Route::post('check-availability', [CustomerAuthController::class, 'checkAvailability']);
        Route::post('forgot-password', [CustomerAuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
        Route::post('verify-reset-code', [CustomerAuthController::class, 'verifyResetCode'])->middleware('throttle:10,1');
        Route::post('reset-password', [CustomerAuthController::class, 'resetPassword'])->middleware('throttle:10,1');

        Route::middleware('auth:user')->group(function () {
            Route::post('logout', [CustomerAuthController::class, 'logout']);
            Route::get('me', [CustomerAuthController::class, 'me']);
            Route::post('send-verification-code', [CustomerAuthController::class, 'sendVerificationCode'])->middleware('throttle:6,1');
            Route::post('verify', [CustomerAuthController::class, 'verify']);
        });
    });

    /* ================================================================== *
     | Customer dashboard
     * ================================================================== */

    Route::prefix('user')->middleware(['auth:user', 'user.active'])->group(function () {
        Route::get('dashboard', [AccountController::class, 'dashboard']);

        Route::get('orders', [AccountController::class, 'orders']);
        Route::get('orders/{orderNumber}', [AccountController::class, 'orderDetails']);
        Route::post('orders/{orderNumber}/cancel', [AccountController::class, 'cancelOrder']);
        Route::get('orders/{orderNumber}/invoice', [AccountController::class, 'orderInvoice']);

        Route::get('addresses', [AccountController::class, 'addresses']);
        Route::post('addresses', [AccountController::class, 'saveAddress']);
        Route::post('addresses/{id}', [AccountController::class, 'saveAddress'])->whereNumber('id');
        Route::delete('addresses/{id}', [AccountController::class, 'deleteAddress'])->whereNumber('id');

        Route::post('profile', [AccountController::class, 'updateProfile']);
        Route::post('change-password', [AccountController::class, 'changePassword']);

        Route::get('reviewable-products', [AccountController::class, 'reviewableProducts']);
        Route::post('reviews', [AccountController::class, 'submitReview']);

        Route::get('notifications', [AccountController::class, 'notifications']);
        Route::post('notifications/{id}/read', [AccountController::class, 'readNotification'])->whereNumber('id');
        Route::post('notifications/read-all', [AccountController::class, 'readAllNotifications']);

        Route::get('payments', [AccountController::class, 'payments']);

        Route::get('tickets', [AccountController::class, 'tickets']);
        Route::post('tickets', [AccountController::class, 'createTicket']);
        Route::get('tickets/{ticketNumber}', [AccountController::class, 'ticket']);
        Route::get('attachments/{id}', [AccountController::class, 'downloadAttachment'])->whereNumber('id');
        Route::post('tickets/{ticketNumber}/reply', [AccountController::class, 'replyTicket']);
        Route::post('tickets/{ticketNumber}/close', [AccountController::class, 'closeTicket']);
    });

    /* ================================================================== *
     | Staff (admin) API
     * ================================================================== */

    Route::prefix('admin')->group(function () {
        Route::post('auth/login', [AdminAuthController::class, 'login'])->middleware('throttle:20,1');
        Route::post('auth/forgot-password', [AdminAuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
        Route::post('auth/verify-reset-code', [AdminAuthController::class, 'verifyResetCode'])->middleware('throttle:10,1');
        Route::post('auth/reset-password', [AdminAuthController::class, 'resetPassword'])->middleware('throttle:10,1');

        Route::middleware(['auth:admin', 'admin.active'])->group(function () {
            Route::post('auth/logout', [AdminAuthController::class, 'logout']);
            Route::get('auth/me', [AdminAuthController::class, 'me']);
            Route::post('auth/profile', [AdminAuthController::class, 'updateProfile']);
            Route::post('auth/change-password', [AdminAuthController::class, 'changePassword']);

            Route::get('dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard.view');

            /* ----------------------------- Branches ---------------------- */
            Route::prefix('branches')->group(function () {
                Route::get('/', [BranchController::class, 'index'])->middleware('permission:branch.view');
                Route::get('options', [BranchController::class, 'options']);
                Route::get('performance', [BranchController::class, 'performance'])->middleware('permission:report.branch_performance');
                Route::get('{id}', [BranchController::class, 'show'])->whereNumber('id')->middleware('permission:branch.view');
                Route::post('/', [BranchController::class, 'store'])->middleware('permission:branch.create');
                Route::post('{id}', [BranchController::class, 'update'])->whereNumber('id')->middleware('permission:branch.update');
                Route::post('{id}/status', [BranchController::class, 'changeStatus'])->whereNumber('id')->middleware('permission:branch.status');
                Route::delete('{id}', [BranchController::class, 'destroy'])->whereNumber('id')->middleware('permission:branch.delete');
            });

            /* ------------------------------- Staff ----------------------- */
            Route::prefix('staff')->group(function () {
                Route::get('/', [StaffController::class, 'index'])->middleware('permission:staff.view');
                Route::get('roles', [StaffController::class, 'rolesAndPermissions'])->middleware('permission:staff.view');
                Route::post('roles/{roleId}/permissions', [StaffController::class, 'updateRolePermissions'])
                    ->whereNumber('roleId')->middleware('permission:staff.roles');
                Route::get('{id}', [StaffController::class, 'show'])->whereNumber('id')->middleware('permission:staff.view');
                Route::post('/', [StaffController::class, 'store'])->middleware('permission:staff.create');
                Route::post('{id}', [StaffController::class, 'update'])->whereNumber('id')->middleware('permission:staff.update');
                Route::post('{id}/status', [StaffController::class, 'changeStatus'])->whereNumber('id')->middleware('permission:staff.status');
            });

            /* ----------------------------- Customers --------------------- */
            Route::prefix('customers')->group(function () {
                Route::get('/', [CustomerController::class, 'index'])->middleware('permission:customer.view');
                Route::post('notify-all', [CustomerController::class, 'notifyAll'])->middleware('permission:customer.notify');
                Route::get('{id}', [CustomerController::class, 'show'])->whereNumber('id')->middleware('permission:customer.view');
                Route::post('{id}', [CustomerController::class, 'update'])->whereNumber('id')->middleware('permission:customer.update');
                Route::post('{id}/status', [CustomerController::class, 'changeStatus'])->whereNumber('id')->middleware('permission:customer.status');
                Route::post('{id}/notify', [CustomerController::class, 'notify'])->whereNumber('id')->middleware('permission:customer.notify');
            });

            /* ------------------------------ Products --------------------- */
            Route::prefix('products')->group(function () {
                Route::get('/', [AdminProductController::class, 'index'])->middleware('permission:product.view');
                Route::get('form-data', [AdminProductController::class, 'formData'])->middleware('permission:product.view');
                Route::get('search', [AdminProductController::class, 'search'])->middleware('permission:product.view');
                Route::post('ai-generate', [AdminProductController::class, 'aiGenerate'])
                    ->middleware(['permission:product.ai_generate', 'throttle:30,1']);
                Route::get('{id}', [AdminProductController::class, 'show'])->whereNumber('id')->middleware('permission:product.view');
                Route::post('/', [AdminProductController::class, 'store'])->middleware('permission:product.create');
                Route::post('{id}', [AdminProductController::class, 'update'])->whereNumber('id')->middleware('permission:product.update');
                Route::post('{id}/status', [AdminProductController::class, 'changeStatus'])->whereNumber('id')->middleware('permission:product.status');
                Route::delete('{id}', [AdminProductController::class, 'destroy'])->whereNumber('id')->middleware('permission:product.delete');
                Route::delete('media/{mediaId}', [AdminProductController::class, 'deleteMedia'])->whereNumber('mediaId')->middleware('permission:product.update');
                Route::post('media/{mediaId}/main', [AdminProductController::class, 'setMainMedia'])->whereNumber('mediaId')->middleware('permission:product.update');
            });

            /* ------------------------------ Taxonomy --------------------- */
            Route::middleware('permission:product.view')->group(function () {
                Route::get('categories', [TaxonomyController::class, 'categories']);
                Route::get('brands', [TaxonomyController::class, 'brands']);
                Route::get('attributes', [TaxonomyController::class, 'attributes']);
                Route::get('taxes', [TaxonomyController::class, 'taxes']);
                Route::get('stock-units', [TaxonomyController::class, 'stockUnits']);
                Route::get('shipping-classes', [TaxonomyController::class, 'shippingClasses']);
            });

            Route::middleware('permission:category.manage')->group(function () {
                Route::post('categories', [TaxonomyController::class, 'saveCategory']);
                Route::post('categories/reorder', [TaxonomyController::class, 'reorderCategories']);
                Route::post('categories/{id}', [TaxonomyController::class, 'saveCategory'])->whereNumber('id');
                Route::post('categories/{id}/status', [TaxonomyController::class, 'categoryStatus'])->whereNumber('id');
                Route::delete('categories/{id}', [TaxonomyController::class, 'deleteCategory'])->whereNumber('id');
            });

            Route::middleware('permission:brand.manage')->group(function () {
                Route::post('brands', [TaxonomyController::class, 'saveBrand']);
                Route::post('brands/{id}', [TaxonomyController::class, 'saveBrand'])->whereNumber('id');
                Route::post('brands/{id}/status', [TaxonomyController::class, 'brandStatus'])->whereNumber('id');
                Route::post('brands/{id}/popular', [TaxonomyController::class, 'brandPopularStatus'])->whereNumber('id');
            });

            Route::middleware('permission:attribute.manage')->group(function () {
                Route::post('attributes', [TaxonomyController::class, 'saveAttribute']);
                Route::post('attributes/{id}', [TaxonomyController::class, 'saveAttribute'])->whereNumber('id');
                Route::delete('attributes/{id}', [TaxonomyController::class, 'deleteAttribute'])->whereNumber('id');
            });

            Route::middleware('permission:tax.manage')->group(function () {
                Route::post('taxes', [TaxonomyController::class, 'saveTax']);
                Route::post('taxes/{id}', [TaxonomyController::class, 'saveTax'])->whereNumber('id');
                Route::post('taxes/{id}/status', [TaxonomyController::class, 'taxStatus'])->whereNumber('id');
            });

            Route::middleware('permission:stock_unit.manage')->group(function () {
                Route::post('stock-units', [TaxonomyController::class, 'saveStockUnit']);
                Route::post('stock-units/{id}', [TaxonomyController::class, 'saveStockUnit'])->whereNumber('id');
                Route::post('stock-units/{id}/status', [TaxonomyController::class, 'stockUnitStatus'])->whereNumber('id');
                Route::post('shipping-classes', [TaxonomyController::class, 'saveShippingClass']);
                Route::post('shipping-classes/{id}', [TaxonomyController::class, 'saveShippingClass'])->whereNumber('id');
            });

            /* ----------------------------- Inventory --------------------- */
            Route::prefix('inventory')->group(function () {
                Route::get('/', [InventoryController::class, 'index'])->middleware('permission:inventory.view');
                Route::get('assignable-products', [InventoryController::class, 'assignableProducts'])->middleware('permission:inventory.view');
                Route::get('history', [InventoryController::class, 'history'])->middleware('permission:inventory.history');
                Route::post('adjust', [InventoryController::class, 'adjust'])->middleware('permission:inventory.adjust');

                Route::get('transfers', [InventoryController::class, 'transfers'])->middleware('permission:inventory.view');
                Route::post('transfers', [InventoryController::class, 'createTransfer'])->middleware('permission:inventory.transfer');
                Route::post('transfers/{id}/dispatch', [InventoryController::class, 'dispatchTransfer'])->whereNumber('id')->middleware('permission:inventory.transfer');
                Route::post('transfers/{id}/receive', [InventoryController::class, 'receiveTransfer'])->whereNumber('id')->middleware('permission:inventory.transfer');
                Route::post('transfers/{id}/cancel', [InventoryController::class, 'cancelTransfer'])->whereNumber('id')->middleware('permission:inventory.transfer');
            });

            /* ------------------------------- Orders ---------------------- */
            Route::prefix('orders')->group(function () {
                Route::get('/', [AdminOrderController::class, 'index'])->middleware('permission:order.view');
                Route::get('{id}', [AdminOrderController::class, 'show'])->whereNumber('id')->middleware('permission:order.view');
                Route::get('{id}/invoice', [AdminOrderController::class, 'invoice'])->whereNumber('id')->middleware('permission:order.invoice');
                Route::post('{id}/status', [AdminOrderController::class, 'changeStatus'])->whereNumber('id')->middleware('permission:order.update_status');
                Route::post('{id}/mark-paid', [AdminOrderController::class, 'markPaid'])->whereNumber('id')->middleware('permission:order.update_status');
                Route::post('{id}/branch', [AdminOrderController::class, 'assignBranch'])->whereNumber('id')->middleware('permission:order.assign_branch');
            });

            /* ---------------------------- Commission --------------------- */
            Route::prefix('commissions')->group(function () {
                // Scoped inside the controller: a worker sees only their own,
                // a manager only their branch.
                Route::get('/', [CommissionController::class, 'index']);
                Route::post('{id}/status', [CommissionController::class, 'changeStatus'])
                    ->whereNumber('id')->middleware('permission:commission.manage');
            });

            /* ----------------------------- Marketing --------------------- */
            Route::middleware('permission:coupon.manage')->prefix('coupons')->group(function () {
                Route::get('/', [MarketingController::class, 'coupons']);
                Route::get('{id}', [MarketingController::class, 'showCoupon'])->whereNumber('id');
                Route::post('/', [MarketingController::class, 'saveCoupon']);
                Route::post('{id}', [MarketingController::class, 'saveCoupon'])->whereNumber('id');
                Route::post('{id}/status', [MarketingController::class, 'couponStatus'])->whereNumber('id');
            });

            Route::middleware('permission:offer.manage')->prefix('offers')->group(function () {
                Route::get('/', [MarketingController::class, 'offers']);
                Route::get('{id}', [MarketingController::class, 'showOffer'])->whereNumber('id');
                Route::post('/', [MarketingController::class, 'saveOffer']);
                Route::post('{id}', [MarketingController::class, 'saveOffer'])->whereNumber('id');
                Route::post('{id}/status', [MarketingController::class, 'offerStatus'])->whereNumber('id');
            });

            Route::middleware('permission:campaign.manage')->prefix('campaigns')->group(function () {
                Route::get('/', [MarketingController::class, 'campaigns']);
                Route::get('{id}', [MarketingController::class, 'showCampaign'])->whereNumber('id');
                Route::post('/', [MarketingController::class, 'saveCampaign']);
                Route::post('{id}', [MarketingController::class, 'saveCampaign'])->whereNumber('id');
                Route::post('{id}/status', [MarketingController::class, 'campaignStatus'])->whereNumber('id');
            });

            Route::middleware('permission:subscriber.manage')->prefix('subscribers')->group(function () {
                Route::get('/', [MarketingController::class, 'subscribers']);
                Route::post('email', [MarketingController::class, 'emailSubscribers']);
                Route::delete('{id}', [MarketingController::class, 'removeSubscriber'])->whereNumber('id');
            });

            /* ------------------------------ Shipping --------------------- */
            Route::get('shipping', [OperationsController::class, 'shipping'])->middleware('permission:shipping.manage');
            Route::middleware('permission:shipping.manage')->prefix('shipping')->group(function () {
                Route::post('zones', [OperationsController::class, 'saveShippingZone']);
                Route::post('zones/{id}', [OperationsController::class, 'saveShippingZone'])->whereNumber('id');
                Route::post('zones/{id}/status', [OperationsController::class, 'shippingZoneStatus'])->whereNumber('id');
                Route::post('methods', [OperationsController::class, 'saveShippingMethod']);
                Route::post('methods/{id}', [OperationsController::class, 'saveShippingMethod'])->whereNumber('id');
                Route::post('methods/{id}/status', [OperationsController::class, 'shippingMethodStatus'])->whereNumber('id');
                Route::post('rates', [OperationsController::class, 'saveShippingRate']);
                Route::post('rates/{id}', [OperationsController::class, 'saveShippingRate'])->whereNumber('id');
                Route::post('rates/{id}/status', [OperationsController::class, 'shippingRateStatus'])->whereNumber('id');
            });

            /* ------------------------------ Payments --------------------- */
            Route::middleware('permission:gateway.manage')->prefix('gateways')->group(function () {
                Route::get('/', [OperationsController::class, 'gateways']);
                Route::post('manual', [OperationsController::class, 'createManualGateway']);
                Route::post('{id}', [OperationsController::class, 'saveGateway'])->whereNumber('id');
                Route::post('{id}/status', [OperationsController::class, 'gatewayStatus'])->whereNumber('id');
            });

            Route::prefix('deposits')->group(function () {
                Route::get('/', [OperationsController::class, 'deposits'])->middleware('permission:deposit.view');
                Route::post('{id}/approve', [OperationsController::class, 'approveDeposit'])->whereNumber('id')->middleware('permission:deposit.approve');
                Route::post('{id}/reject', [OperationsController::class, 'rejectDeposit'])->whereNumber('id')->middleware('permission:deposit.approve');
            });

            /* ------------------------------- Reviews --------------------- */
            Route::middleware('permission:review.manage')->prefix('reviews')->group(function () {
                Route::get('/', [OperationsController::class, 'reviews']);
                Route::post('{id}/approve', [OperationsController::class, 'approveReview'])->whereNumber('id');
                Route::post('{id}/reject', [OperationsController::class, 'rejectReview'])->whereNumber('id');
                Route::post('{id}/reply', [OperationsController::class, 'replyReview'])->whereNumber('id');
                Route::delete('{id}', [OperationsController::class, 'deleteReview'])->whereNumber('id');
            });

            /* ------------------------------- Tickets --------------------- */
            Route::prefix('tickets')->group(function () {
                Route::get('/', [OperationsController::class, 'tickets'])->middleware('permission:ticket.view');
                Route::get('{id}', [OperationsController::class, 'ticket'])->whereNumber('id')->middleware('permission:ticket.view');
                Route::post('{id}/reply', [OperationsController::class, 'replyTicket'])->whereNumber('id')->middleware('permission:ticket.reply');
                Route::post('{id}/close', [OperationsController::class, 'closeTicket'])->whereNumber('id')->middleware('permission:ticket.close');
                Route::get('attachments/{id}', [OperationsController::class, 'downloadAttachment'])->whereNumber('id')->middleware('permission:ticket.view');
                Route::delete('{id}', [OperationsController::class, 'deleteTicket'])->whereNumber('id')->middleware('permission:ticket.delete');
            });

            /* ------------------------------- Reports --------------------- */
            Route::prefix('reports')->group(function () {
                Route::get('sales', [ReportController::class, 'sales'])->middleware('permission:report.sales');
                Route::get('inventory', [ReportController::class, 'inventory'])->middleware('permission:report.inventory');
                Route::get('branch-performance', [ReportController::class, 'branchPerformance'])->middleware('permission:report.branch_performance');
                Route::get('login-history', [ReportController::class, 'loginHistory'])->middleware('permission:report.login_history');
                Route::get('notification-history', [ReportController::class, 'notificationHistory'])->middleware('permission:report.notification_history');
                Route::get('audit-logs', [ReportController::class, 'auditLogs'])->middleware('permission:report.audit_log');
            });

            /* ------------------------------ Settings --------------------- */
            Route::get('notifications', [SettingController::class, 'notifications']);
            Route::post('notifications/{id}/read', [SettingController::class, 'readNotification'])->whereNumber('id');
            Route::post('notifications/read-all', [SettingController::class, 'readAllNotifications']);
            Route::delete('notifications/{id}', [SettingController::class, 'deleteNotification'])->whereNumber('id');

            Route::prefix('settings')->group(function () {
                Route::get('general', [SettingController::class, 'general'])->middleware('permission:setting.general');
                Route::post('general', [SettingController::class, 'updateGeneral'])->middleware('permission:setting.general');
                Route::post('logo', [SettingController::class, 'updateLogo'])->middleware('permission:setting.general');
                Route::post('maintenance-mode', [SettingController::class, 'maintenanceMode'])->middleware('permission:setting.system');

                Route::get('company', [SettingController::class, 'company'])->middleware('permission:setting.company');
                Route::post('company', [SettingController::class, 'updateCompany'])->middleware('permission:setting.company');

                Route::get('ai', [SettingController::class, 'aiSettings'])->middleware('permission:setting.ai');
                Route::post('ai', [SettingController::class, 'updateAiSettings'])->middleware('permission:setting.ai');

                Route::get('social-logins', [SettingController::class, 'socialLogins'])->middleware('permission:setting.general');
                Route::post('social-logins/{provider}', [SettingController::class, 'updateSocialLogin'])->middleware('permission:setting.general');

                Route::get('notification-templates', [SettingController::class, 'notificationTemplates'])->middleware('permission:setting.notification');
                Route::post('notification-templates/{id}', [SettingController::class, 'saveNotificationTemplate'])->whereNumber('id')->middleware('permission:setting.notification');
                Route::post('global-templates', [SettingController::class, 'updateGlobalTemplates'])->middleware('permission:setting.notification');

                Route::get('system-info', [SettingController::class, 'systemInfo'])->middleware('permission:setting.system');
                Route::post('clear-cache', [SettingController::class, 'clearCache'])->middleware('permission:setting.system');
            });

            /* -------------------------------- CMS ------------------------ */
            Route::middleware('permission:frontend.manage')->prefix('frontend')->group(function () {
                Route::get('sections', [SettingController::class, 'frontendSections']);
                Route::get('sections/{key}', [SettingController::class, 'frontendSections']);
                Route::post('sections/{key}/content', [SettingController::class, 'saveFrontendContent']);
                Route::post('sections/{key}/element', [SettingController::class, 'saveFrontendElement']);
                Route::post('sections/{key}/element/{id}', [SettingController::class, 'saveFrontendElement'])->whereNumber('id');
                Route::delete('element/{id}', [SettingController::class, 'deleteFrontendElement'])->whereNumber('id');
                Route::post('seo/{id}', [SettingController::class, 'saveSeo'])->whereNumber('id');
            });

            Route::middleware('permission:page.manage')->prefix('pages')->group(function () {
                Route::get('/', [SettingController::class, 'pages']);
                Route::post('/', [SettingController::class, 'savePage']);
                Route::post('{id}', [SettingController::class, 'savePage'])->whereNumber('id');
                Route::delete('{id}', [SettingController::class, 'deletePage'])->whereNumber('id');
            });

            Route::middleware('permission:language.manage')->prefix('languages')->group(function () {
                Route::get('/', [SettingController::class, 'languages']);
                Route::get('{id}/strings', [SettingController::class, 'languageStrings'])->whereNumber('id');
                Route::post('{id}/strings', [SettingController::class, 'saveLanguageStrings'])->whereNumber('id');
                Route::post('/', [SettingController::class, 'saveLanguage']);
                Route::post('{id}', [SettingController::class, 'saveLanguage'])->whereNumber('id');
                Route::delete('{id}', [SettingController::class, 'deleteLanguage'])->whereNumber('id');
            });

            Route::middleware('permission:extension.manage')->prefix('extensions')->group(function () {
                Route::get('/', [SettingController::class, 'extensions']);
                Route::post('{id}', [SettingController::class, 'saveExtension'])->whereNumber('id');
            });
        });
    });
});
