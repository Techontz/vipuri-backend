<?php

namespace App\Services;

use App\Constants\Status;
use App\Models\Admin;
use App\Models\AdminNotification;
use App\Models\BranchInventory;
use App\Models\Deposit;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\ProductReview;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Template-driven notifications (e-mail, SMS, in-app), ported from the source
 * system's notify() helper.
 *
 * Every send is written to `notification_logs` so the admin "Notification
 * History" report has real data, exactly as in the original.
 */
class NotificationService
{
    /**
     * Send a templated notification to a customer.
     *
     * @param  array<string, string>  $shortCodes
     */
    public function toUser(User $user, string $act, array $shortCodes = [], ?string $clickUrl = null): void
    {
        $template = NotificationTemplate::where('act', $act)->first();

        if (! $template) {
            return;
        }

        $globals = [
            'site_name' => gs('site_name'),
            'fullname' => trim($user->firstname . ' ' . $user->lastname),
            'username' => $user->username,
            'email' => $user->email,
        ];

        $codes = $globals + $shortCodes;

        if (gs('en') && $template->email_status && $user->email) {
            $subject = $this->replace($template->subject ?? '', $codes);
            $body = $this->replace($template->email_body ?? '', $codes);

            $this->sendEmail($user->email, $subject, $body);

            NotificationLog::create([
                'user_id' => $user->id,
                'sender' => 'system',
                'sent_from' => $template->email_sent_from_address ?: gs('email_from'),
                'sent_to' => $user->email,
                'subject' => $subject,
                'message' => $body,
                'notification_type' => 'email',
            ]);
        }

        if (gs('sn') && $template->sms_status && $user->mobile) {
            $message = $this->replace($template->sms_body ?? '', $codes);

            $this->sendSms($user->dial_code . $user->mobile, $message);

            NotificationLog::create([
                'user_id' => $user->id,
                'sender' => 'system',
                'sent_from' => $template->sms_sent_from ?: gs('sms_from'),
                'sent_to' => $user->dial_code . $user->mobile,
                'subject' => $template->subject,
                'message' => $message,
                'notification_type' => 'sms',
            ]);
        }

        UserNotification::create([
            'user_id' => $user->id,
            'title' => $this->replace($template->subject ?? $template->name, $codes),
            'click_url' => $clickUrl,
        ]);
    }

    /** In-app notification for staff. Null branch = super admins only. */
    public function toStaff(string $title, ?string $clickUrl = null, ?int $branchId = null, int $userId = 0): void
    {
        AdminNotification::create([
            'branch_id' => $branchId,
            'user_id' => $userId,
            'title' => $title,
            'click_url' => $clickUrl,
        ]);
    }

    /* ------------------------------------------------------------------ *
     | Domain events
     * ------------------------------------------------------------------ */

    public function orderPlaced(Order $order): void
    {
        $user = $order->user_id ? User::find($order->user_id) : null;

        if ($user) {
            $this->toUser($user, 'ORDER_PLACED', [
                'order_number' => $order->order_number,
                'amount' => showAmount($order->total),
                'status' => $order->status_label,
            ], "/user/orders/details/{$order->order_number}");
        }

        $this->toStaff(
            "New order {$order->order_number} — " . showAmount($order->total),
            "/admin/orders/{$order->id}",
            $order->branch_id,
            (int) $order->user_id,
        );
    }

    public function orderPaid(Order $order): void
    {
        $user = $order->user_id ? User::find($order->user_id) : null;

        if ($user) {
            $this->toUser($user, 'ORDER_PAID', [
                'order_number' => $order->order_number,
                'amount' => showAmount($order->total),
            ], "/user/orders/details/{$order->order_number}");
        }

        $this->toStaff(
            "Payment received for {$order->order_number}",
            "/admin/orders/{$order->id}",
            $order->branch_id,
        );
    }

    /**
     * One template per transition, as in the source system, so an
     * administrator can word "dispatched" differently from "cancelled".
     * Anything without its own template falls back to the generic one.
     */
    private const STATUS_TEMPLATES = [
        Status::ORDER_PROCESSING => 'ORDER_ON_PROCESSING_CONFIRMATION',
        Status::ORDER_DISPATCHED => 'ORDER_DISPATCHED_CONFIRMATION',
        Status::ORDER_DELIVERED => 'ORDER_DELIVERY_CONFIRMATION',
        Status::ORDER_RETURNED => 'ORDER_RETURNED_CONFIRMATION',
        Status::ORDER_CANCELLED => 'ORDER_CANCELLATION_CONFIRMATION',
    ];

    public function orderStatusChanged(Order $order): void
    {
        $user = $order->user_id ? User::find($order->user_id) : null;

        if (! $user) {
            return;
        }

        $template = self::STATUS_TEMPLATES[(int) $order->status] ?? 'ORDER_STATUS_CHANGED';

        $this->toUser($user, $template, [
            'order_number' => $order->order_number,
            'status' => $order->status_label,
            'amount' => showAmount($order->total),
        ], "/user/orders/details/{$order->order_number}");
    }

    /* ----------------------------- Payments ---------------------------- */

    public function depositRequested(Deposit $deposit): void
    {
        $user = $deposit->user_id ? User::find($deposit->user_id) : null;

        if ($user) {
            $this->toUser($user, 'DEPOSIT_REQUEST', $this->depositShortcodes($deposit));
        }

        $this->toStaff(
            'Manual payment awaiting review: ' . showAmount($deposit->amount),
            "/admin/deposits/{$deposit->id}",
            $deposit->order?->branch_id,
        );
    }

    public function depositApproved(Deposit $deposit): void
    {
        $this->notifyDepositOwner($deposit, 'DEPOSIT_APPROVE');
    }

    public function depositRejected(Deposit $deposit): void
    {
        $this->notifyDepositOwner($deposit, 'DEPOSIT_REJECT');
    }

    private function notifyDepositOwner(Deposit $deposit, string $template): void
    {
        $user = $deposit->user_id ? User::find($deposit->user_id) : null;

        if ($user) {
            $this->toUser($user, $template, $this->depositShortcodes($deposit));
        }
    }

    private function depositShortcodes(Deposit $deposit): array
    {
        return [
            'trx' => $deposit->trx,
            'amount' => showAmount($deposit->amount),
            'gateway' => $deposit->gateway?->name ?? 'Manual',
            'order_number' => $deposit->order?->order_number ?? '',
            'reason' => $deposit->admin_feedback ?? '',
        ];
    }

    /* ------------------------------ Reviews ---------------------------- */

    public function reviewApproved(ProductReview $review): void
    {
        $this->notifyReviewer($review, 'REVIEW_APPROVE');
    }

    public function reviewRejected(ProductReview $review): void
    {
        $this->notifyReviewer($review, 'REVIEW_REJECT');
    }

    private function notifyReviewer(ProductReview $review, string $template): void
    {
        $user = $review->user_id ? User::find($review->user_id) : null;

        if (! $user) {
            return;
        }

        $this->toUser($user, $template, [
            'product' => $review->product?->name ?? '',
            'rating' => (string) $review->rating,
        ], '/user/reviews');
    }

    /* ------------------------------ Support ---------------------------- */

    public function supportReplied(SupportTicket $ticket): void
    {
        $user = $ticket->user_id ? User::find($ticket->user_id) : null;

        if (! $user) {
            return;
        }

        $this->toUser($user, 'ADMIN_SUPPORT_REPLY', [
            'ticket' => $ticket->ticket,
            'subject' => $ticket->subject,
        ], "/user/tickets/{$ticket->ticket}");
    }

    /** Raise low-stock alerts for branch staff and super admins. */
    public function lowStockAlert(BranchInventory $row): void
    {
        $product = $row->product;

        $this->toStaff(
            "Low stock: {$product?->name} at {$row->branch?->name} ({$row->stock_quantity} left)",
            "/admin/inventory?product={$row->product_id}",
            $row->branch_id,
        );
    }

    /* ------------------------------------------------------------------ *
     | Transports
     * ------------------------------------------------------------------ */

    private function sendEmail(string $to, string $subject, string $body): void
    {
        $wrapper = gs('email_template') ?: '{{message}}';
        $html = str_replace('{{message}}', $body, $wrapper);

        try {
            Mail::html($html, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::warning('VIPURI mail send failed: ' . $e->getMessage());
        }
    }

    /**
     * SMS transport. The default `log` driver records the message so the flow
     * is fully exercised without a paid provider; configure SMS_DRIVER plus the
     * matching credentials in .env to send for real.
     */
    private function sendSms(string $to, string $message): void
    {
        $driver = config('services.sms.driver', 'log');
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');

        if ($driver === 'twilio' && $sid && $token) {
            try {
                \Illuminate\Support\Facades\Http::withBasicAuth($sid, $token)
                    ->asForm()
                    ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                        'From' => config('services.twilio.from'),
                        'To' => $to,
                        'Body' => $message,
                    ]);

                return;
            } catch (\Throwable $e) {
                Log::warning('VIPURI SMS send failed: ' . $e->getMessage());
            }
        }

        Log::info("VIPURI SMS to {$to}: {$message}");
    }

    /** Replace {{key}} placeholders in a template body. */
    private function replace(string $text, array $codes): string
    {
        foreach ($codes as $key => $value) {
            $text = str_replace(['{{' . $key . '}}', '{{ ' . $key . ' }}'], (string) $value, $text);
        }

        return $text;
    }
}
