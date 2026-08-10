<?php

namespace App\Constants;

/**
 * Status constants. Values are kept identical to the source system so that
 * migrated data and the replicated UI behave exactly the same.
 */
class Status
{
    public const ENABLE = 1;
    public const DISABLE = 0;

    public const YES = 1;
    public const NO = 0;

    public const VERIFIED = 1;
    public const UNVERIFIED = 0;

    public const PAYMENT_INITIATE = 0;
    public const PAYMENT_SUCCESS = 1;
    public const PAYMENT_PENDING = 2;
    public const PAYMENT_REJECT = 3;

    public const TICKET_OPEN = 0;
    public const TICKET_ANSWER = 1;
    public const TICKET_REPLY = 2;
    public const TICKET_CLOSE = 3;

    public const PRIORITY_LOW = 1;
    public const PRIORITY_MEDIUM = 2;
    public const PRIORITY_HIGH = 3;

    public const USER_ACTIVE = 1;
    public const USER_BAN = 0;

    public const CUR_BOTH = 1;
    public const CUR_TEXT = 2;
    public const CUR_SYM = 3;

    public const TRACK_INVENTORY = 1;
    public const DONT_TRACK_INVENTORY = 0;

    public const LOW_STOCK_NOTHING = 0;
    public const LOW_STOCK_DISABLE_BUY_BUTTON = 1;
    public const LOW_STOCK_UNPUBLISH_PRODUCT = 2;

    /** Percentage off (e.g. 20% off) */
    public const COUPON_DISCOUNT_PERCENT = 1;
    /** Flat amount off the entire cart */
    public const COUPON_DISCOUNT_FIXED_CART = 2;
    /** Flat amount off each product in the cart */
    public const COUPON_DISCOUNT_FIXED_PRODUCT = 3;

    public const PRODUCT_SIMPLE = 'simple';
    public const PRODUCT_VARIABLE = 'variable';
    public const PRODUCT_GROUPED = 'grouped';
    public const PRODUCT_EXTERNAL = 'external';

    public const ORDER_INITIATED = 0;
    public const ORDER_PENDING = 0;
    public const ORDER_PAID = 1;
    public const ORDER_PROCESSING = 2;
    public const ORDER_DISPATCHED = 3;
    public const ORDER_DELIVERED = 4;
    public const ORDER_RETURNED = 6;
    public const ORDER_CANCELLED = 7;

    /**
     * Worker commission on a completed order.
     *
     * `REVERSED` is a terminal state and never becomes anything else: a
     * returned or cancelled order withdraws the entitlement but leaves the row
     * where it is, so the history a payroll question needs stays intact.
     */
    public const COMMISSION_PENDING = 0;
    public const COMMISSION_APPROVED = 1;
    public const COMMISSION_PAID = 2;
    public const COMMISSION_REVERSED = 3;

    public const COMMISSION_STATUS_LABELS = [
        self::COMMISSION_PENDING => 'Pending',
        self::COMMISSION_APPROVED => 'Approved',
        self::COMMISSION_PAID => 'Paid',
        self::COMMISSION_REVERSED => 'Reversed',
    ];

    public const OFFER_PERCENT = 1;
    public const OFFER_FIXED = 2;

    public const REVIEW_PENDING = 0;
    public const REVIEW_APPROVED = 1;
    public const REVIEW_REJECTED = 2;

    public const GEMINI_MODEL = 1;
    public const OPENAI_MODEL = 2;

    public const TRANSFER_PENDING = 0;
    public const TRANSFER_IN_TRANSIT = 1;
    public const TRANSFER_RECEIVED = 2;
    public const TRANSFER_CANCELLED = 3;

    /** Human labels for order statuses, used by API resources and reports. */
    public const ORDER_STATUS_LABELS = [
        self::ORDER_PENDING => 'Pending',
        self::ORDER_PAID => 'Paid',
        self::ORDER_PROCESSING => 'Processing',
        self::ORDER_DISPATCHED => 'Dispatched',
        self::ORDER_DELIVERED => 'Delivered',
        self::ORDER_RETURNED => 'Returned',
        self::ORDER_CANCELLED => 'Cancelled',
    ];

    public const PAYMENT_STATUS_LABELS = [
        self::PAYMENT_INITIATE => 'Initiated',
        self::PAYMENT_SUCCESS => 'Paid',
        self::PAYMENT_PENDING => 'Pending',
        self::PAYMENT_REJECT => 'Rejected',
    ];

    public const TRANSFER_STATUS_LABELS = [
        self::TRANSFER_PENDING => 'Pending',
        self::TRANSFER_IN_TRANSIT => 'In Transit',
        self::TRANSFER_RECEIVED => 'Received',
        self::TRANSFER_CANCELLED => 'Cancelled',
    ];
}
