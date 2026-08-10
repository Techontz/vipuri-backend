<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Constants\Status;
use App\Models\Branch;
use App\Models\Deposit;
use App\Models\Gateway;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\ProductReview;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customers hear about the things that happen to their orders, payments,
 * reviews and tickets — one template per event, as in the source system.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->branch = Branch::where('code', 'DSM-01')->firstOrFail();
        $this->customer = $this->makeCustomer();
    }

    private function notifications(): NotificationService
    {
        return app(NotificationService::class);
    }

    /** The subject line recorded for the most recent message to our customer. */
    private function lastSubject(): ?string
    {
        return NotificationLog::where('user_id', $this->customer->id)->latest('id')->value('subject');
    }

    private function makeOrder(int $status = Status::ORDER_PENDING): Order
    {
        return Order::create([
            'company_id' => \App\Models\Company::current()->id,
            'order_number' => 'VP-NOTIF-' . uniqid(),
            'user_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'status' => $status,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal' => 120000,
            'total' => 120000,
            'shipping_address' => ['name' => $this->customer->fullname],
        ]);
    }

    public function test_each_order_transition_uses_its_own_template(): void
    {
        $expected = [
            Status::ORDER_PROCESSING => 'preparing',
            Status::ORDER_DISPATCHED => 'on its way',
            Status::ORDER_DELIVERED => 'delivered',
            Status::ORDER_CANCELLED => 'cancelled',
            Status::ORDER_RETURNED => 'Return received',
        ];

        foreach ($expected as $status => $phrase) {
            $order = $this->makeOrder($status);

            $this->notifications()->orderStatusChanged($order);

            $this->assertStringContainsString(
                $phrase,
                (string) $this->lastSubject(),
                "Status $status should use its own template",
            );
        }
    }

    public function test_a_status_without_its_own_template_falls_back_to_the_generic_one(): void
    {
        $order = $this->makeOrder(Status::ORDER_PAID);

        $this->notifications()->orderStatusChanged($order);

        $this->assertStringContainsString('is now', (string) $this->lastSubject());
    }

    private function makeDeposit(): Deposit
    {
        $this->seedGateways();
        $gateway = Gateway::where('alias', 'mpesa-manual')->firstOrFail();

        return Deposit::create([
            'user_id' => $this->customer->id,
            'order_id' => $this->makeOrder()->id,
            'gateway_id' => $gateway->id,
            'method_code' => $gateway->id,
            'amount' => 120000,
            'charge' => 0,
            'rate' => 1,
            'final_amount' => 120000,
            'trx' => 'TRX' . strtoupper(uniqid()),
            'status' => Status::PAYMENT_PENDING,
        ]);
    }

    public function test_a_customer_is_told_when_a_manual_payment_is_approved_or_rejected(): void
    {
        $deposit = $this->makeDeposit();

        $this->notifications()->depositApproved($deposit);
        $this->assertStringContainsString('confirmed', (string) $this->lastSubject());

        $deposit->admin_feedback = 'The reference did not match any transaction.';
        $this->notifications()->depositRejected($deposit);
        $this->assertStringContainsString('could not confirm', (string) $this->lastSubject());
    }

    public function test_review_moderation_notifies_the_reviewer(): void
    {
        $product = $this->makeProduct($this->branch);

        $review = ProductReview::create([
            'user_id' => $this->customer->id,
            'product_id' => $product->id,
            'order_id' => $this->makeOrder()->id,
            'rating' => 5,
            'review' => 'Fitted my Hilux perfectly.',
            'status' => Status::REVIEW_PENDING,
        ]);

        $this->notifications()->reviewApproved($review);
        $this->assertStringContainsString('live', (string) $this->lastSubject());

        $this->notifications()->reviewRejected($review);
        $this->assertStringContainsString('About your review', (string) $this->lastSubject());
    }

    public function test_a_support_reply_reaches_the_customer(): void
    {
        $ticket = SupportTicket::create([
            'user_id' => $this->customer->id,
            'name' => $this->customer->fullname,
            'email' => $this->customer->email,
            'ticket' => 'TCK123456',
            'subject' => 'Wrong part delivered',
            'status' => Status::TICKET_OPEN,
            'priority' => Status::PRIORITY_MEDIUM,
            'last_reply' => now(),
        ]);

        $this->notifications()->supportReplied($ticket);

        $this->assertStringContainsString('Wrong part delivered', (string) $this->lastSubject());
        $this->assertDatabaseHas('user_notifications', ['user_id' => $this->customer->id]);
    }

    public function test_replying_to_a_ticket_from_the_admin_panel_notifies_the_customer(): void
    {
        $ticket = SupportTicket::create([
            'user_id' => $this->customer->id,
            'name' => $this->customer->fullname,
            'email' => $this->customer->email,
            'ticket' => 'TCK654321',
            'subject' => 'Delivery question',
            'status' => Status::TICKET_OPEN,
            'priority' => Status::PRIORITY_MEDIUM,
            'last_reply' => now(),
        ]);

        $admin = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->withHeaders($this->adminHeaders($admin))
            ->postJson("/api/v1/admin/tickets/{$ticket->id}/reply", ['message' => 'It ships tomorrow.'])
            ->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $this->customer->id,
            'click_url' => "/user/tickets/{$ticket->ticket}",
        ]);
    }

    public function test_every_template_an_event_asks_for_actually_exists(): void
    {
        $acts = [
            'EVER_CODE', 'PASS_RESET_CODE', 'PASS_RESET_DONE',
            'ORDER_PLACED', 'ORDER_PAID', 'ORDER_STATUS_CHANGED',
            'ORDER_ON_PROCESSING_CONFIRMATION', 'ORDER_DISPATCHED_CONFIRMATION',
            'ORDER_DELIVERY_CONFIRMATION', 'ORDER_CANCELLATION_CONFIRMATION',
            'ORDER_RETURNED_CONFIRMATION',
            'DEPOSIT_REQUEST', 'DEPOSIT_APPROVE', 'DEPOSIT_REJECT',
            'REVIEW_APPROVE', 'REVIEW_REJECT', 'ADMIN_SUPPORT_REPLY',
        ];

        foreach ($acts as $act) {
            $this->assertDatabaseHas('notification_templates', ['act' => $act]);
        }
    }
}
