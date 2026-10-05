<?php

namespace App\Constants;

/**
 * VIPURI staff roles and the permission vocabulary used across the admin API.
 *
 * Customers are a separate guard (`user`) and are intentionally not part of
 * this role set — they can never hold a staff permission.
 */
class Roles
{
    public const GUARD = 'admin';

    public const SUPER_ADMIN = 'Super Admin';
    public const BRANCH_MANAGER = 'Branch Manager';
    public const BRANCH_WORKER = 'Branch Worker';

    public const ALL = [
        self::SUPER_ADMIN,
        self::BRANCH_MANAGER,
        self::BRANCH_WORKER,
    ];

    /**
     * Full permission catalogue, grouped for the admin "Roles & Permissions"
     * screen. Keys are group labels, values are permission names.
     */
    public const GROUPS = [
        'Dashboard' => [
            'dashboard.view',
            'dashboard.company', // company-wide figures (super admin only by default)
        ],
        'Branches' => [
            'branch.view',
            'branch.create',
            'branch.update',
            'branch.status',
            'branch.delete',
        ],
        'Staff' => [
            'staff.view',
            'staff.create',
            'staff.update',
            'staff.status',
            'staff.assign_branch',
            'staff.roles',
        ],
        'Customers' => [
            'customer.view',
            'customer.update',
            'customer.status',
            'customer.notify',
            'customer.login_as',
        ],
        'Catalog' => [
            'product.view',
            'product.create',
            'product.update',
            'product.status',
            'product.delete',
            'product.ai_generate',
            'category.manage',
            'brand.manage',
            'attribute.manage',
            'tax.manage',
            'stock_unit.manage',
            'review.manage',
        ],
        'Inventory' => [
            'inventory.view',
            'inventory.adjust',
            'inventory.transfer',
            'inventory.transfer_approve',
            'inventory.history',
        ],
        'Orders' => [
            'order.view',
            'order.update_status',
            'order.cancel',
            'order.return',
            'order.invoice',
            'order.assign_branch',
        ],
        /*
         * Commission is money, so reading someone else's is a separate right
         * from reading your own, and approving or paying one is separate again.
         */
        'Commission' => [
            'commission.view_own',
            'commission.view_all',
            'commission.manage',
        ],
        'Marketing' => [
            'coupon.manage',
            'offer.manage',
            'campaign.manage',
            'subscriber.manage',
        ],
        'Shipping' => [
            'shipping.manage',
        ],
        'Payments' => [
            'gateway.manage',
            'deposit.view',
            'deposit.approve',
        ],
        'Support' => [
            'ticket.view',
            'ticket.reply',
            'ticket.close',
            'ticket.delete',
        ],
        'Reports' => [
            'report.sales',
            'report.inventory',
            'report.branch_performance',
            'report.login_history',
            'report.notification_history',
            'report.audit_log',
        ],
        'Content' => [
            'frontend.manage',
            'page.manage',
            'language.manage',
            'extension.manage',
            'seo.manage',
        ],
        'Settings' => [
            'setting.general',
            'setting.system',
            'setting.notification',
            'setting.ai',
            'setting.company',
        ],
    ];

    /** Flattened list of every permission name. */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /**
     * Default permissions granted to a Branch Manager. They operate fully
     * inside their own branch but cannot touch company-wide configuration.
     */
    public const BRANCH_MANAGER_PERMISSIONS = [
        'dashboard.view',
        'staff.view', 'staff.create', 'staff.update', 'staff.status',
        'customer.view',
        'product.view',
        'inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.history',
        'order.view', 'order.update_status', 'order.cancel', 'order.return', 'order.invoice',
        'commission.view_own', 'commission.view_all',
        'review.manage',
        'ticket.view', 'ticket.reply', 'ticket.close',
        'report.sales', 'report.inventory', 'report.branch_performance',
        'deposit.view',
    ];

    /** Default permissions granted to a Branch Worker. */
    public const BRANCH_WORKER_PERMISSIONS = [
        'dashboard.view',
        'product.view',
        'inventory.view', 'inventory.adjust',
        'order.view', 'order.update_status', 'order.invoice',
        'commission.view_own',
        'ticket.view', 'ticket.reply',
        'customer.view',
    ];
}
