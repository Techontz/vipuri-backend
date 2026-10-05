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
    public const ADMIN = 'Admin';
    public const BRANCH_MANAGER = 'Branch Manager';
    public const HR_OFFICER = 'HR Officer';
    public const SALES_ASSISTANT = 'Sales Assistant';
    public const BRANCH_WORKER = 'Branch Worker';

    /** The roles VIPURI ships with. Administrators can add their own. */
    public const ALL = [
        self::SUPER_ADMIN,
        self::ADMIN,
        self::BRANCH_MANAGER,
        self::HR_OFFICER,
        self::SALES_ASSISTANT,
        self::BRANCH_WORKER,
    ];

    /**
     * Permission that lifts the one-branch restriction: holders see and act
     * on every branch, and need no branch of their own.
     */
    public const COMPANY_WIDE = 'branch.all';

    /**
     * Rank of each built-in role (roles.level). Staff can only create and
     * manage people whose role ranks below their own, so HR can hire sales
     * assistants but never a manager, and nobody but a super admin can make
     * another super admin.
     */
    public const LEVELS = [
        self::SUPER_ADMIN => 100,
        self::ADMIN => 90,
        self::BRANCH_MANAGER => 60,
        self::HR_OFFICER => 50,
        self::SALES_ASSISTANT => 20,
        self::BRANCH_WORKER => 20,
    ];

    /** Rank given to a custom role when none is chosen. */
    public const DEFAULT_LEVEL = 20;

    public const DESCRIPTIONS = [
        self::SUPER_ADMIN => 'Owns the system: every permission, every branch, and the only role that can create other super admins.',
        self::ADMIN => 'Runs the business across all branches: catalogue, stock, orders, staff and reports. Cannot touch system configuration or super admins.',
        self::BRANCH_MANAGER => 'Runs one branch: its orders, stock, counter sales, reports and staff below manager level.',
        self::HR_OFFICER => 'Looks after people in one branch: adds and updates staff accounts below their own rank.',
        self::SALES_ASSISTANT => 'Serves customers in one branch: sells at the counter, handles orders and checks stock.',
        self::BRANCH_WORKER => 'Fulfils orders in one branch: picks, dispatches and delivers, and keeps stock counts right.',
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
            'branch.all', // work across every branch (company-wide access)
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
            'role.manage', // create, rename and delete roles
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
        'Point of Sale' => [
            'pos.sell', // sell to walk-in customers at the counter
            'pos.discount', // change prices / give discounts at the counter
        ],
        'Inventory' => [
            'inventory.view',
            'inventory.receive', // book in new stock for a branch
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
        'inventory.view', 'inventory.receive', 'inventory.adjust', 'inventory.transfer', 'inventory.history',
        'pos.sell', 'pos.discount',
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

    /** Default permissions granted to an HR Officer. */
    public const HR_OFFICER_PERMISSIONS = [
        'dashboard.view',
        'staff.view', 'staff.create', 'staff.update', 'staff.status',
        'customer.view',
        'report.login_history',
    ];

    /** Default permissions granted to a Sales Assistant. */
    public const SALES_ASSISTANT_PERMISSIONS = [
        'dashboard.view',
        'pos.sell',
        'product.view',
        'inventory.view',
        'order.view', 'order.update_status', 'order.invoice',
        'customer.view',
        'commission.view_own',
        'ticket.view', 'ticket.reply',
    ];

    /**
     * An Admin holds everything except system-level configuration, which
     * stays with the super admin.
     */
    public const ADMIN_EXCLUDED_PERMISSIONS = [
        'setting.system',
        'extension.manage',
        'gateway.manage',
    ];

    /** @return array<string, string[]> default permission set per built-in role */
    public static function defaults(): array
    {
        return [
            self::SUPER_ADMIN => self::all(),
            self::ADMIN => array_values(array_diff(self::all(), self::ADMIN_EXCLUDED_PERMISSIONS)),
            self::BRANCH_MANAGER => self::BRANCH_MANAGER_PERMISSIONS,
            self::HR_OFFICER => self::HR_OFFICER_PERMISSIONS,
            self::SALES_ASSISTANT => self::SALES_ASSISTANT_PERMISSIONS,
            self::BRANCH_WORKER => self::BRANCH_WORKER_PERMISSIONS,
        ];
    }
}
