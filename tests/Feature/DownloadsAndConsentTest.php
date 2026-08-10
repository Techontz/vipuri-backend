<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Constants\Status;
use App\Models\Branch;
use App\Models\Frontend;
use App\Models\Order;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Customer invoice downloads, support attachments and the cookie notice —
 * the `order.print`, `ticket.download` and `cookie` features from the source.
 */
class DownloadsAndConsentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->branch = Branch::where('code', 'DSM-01')->firstOrFail();
        Storage::fake('public');
        // Attachments are private and so live on the `local` disk, not `public`.
        Storage::fake('local');
    }

    /* ------------------------------ Attachments ---------------------------- */

    /** Open a ticket with one attachment and return [ticket, attachmentId]. */
    private function ticketWithAttachment($customer): array
    {
        $this->withHeaders($this->userHeaders($customer))
            ->post('/api/v1/user/tickets', [
                'subject' => 'Wrong part delivered',
                'message' => 'The brake pads do not fit.',
                'attachments' => [UploadedFile::fake()->image('receipt.jpg')],
            ])
            ->assertOk();

        $ticket = SupportTicket::where('user_id', $customer->id)->firstOrFail();
        $attachment = $ticket->messages()->first()->attachments()->firstOrFail();

        return [$ticket, $attachment->id];
    }

    public function test_a_customer_can_download_their_own_attachment(): void
    {
        $customer = $this->makeCustomer();
        [, $attachmentId] = $this->ticketWithAttachment($customer);

        $this->withHeaders($this->userHeaders($customer))
            ->get("/api/v1/user/attachments/{$attachmentId}")
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_another_customer_cannot_download_the_attachment(): void
    {
        $owner = $this->makeCustomer();
        [, $attachmentId] = $this->ticketWithAttachment($owner);

        $intruder = $this->makeCustomer();

        $this->withHeaders($this->userHeaders($intruder))
            ->getJson("/api/v1/user/attachments/{$attachmentId}")
            ->assertForbidden();
    }

    public function test_an_anonymous_visitor_cannot_download_the_attachment(): void
    {
        $customer = $this->makeCustomer();
        [, $attachmentId] = $this->ticketWithAttachment($customer);

        // Drop the owner's bearer header left over from opening the ticket.
        $this->flushHeaders();
        $this->forgetAuthenticatedUser();

        $this->getJson("/api/v1/user/attachments/{$attachmentId}")->assertUnauthorized();
    }

    public function test_attachment_filenames_are_not_published_as_public_urls(): void
    {
        $customer = $this->makeCustomer();
        [$ticket] = $this->ticketWithAttachment($customer);

        $response = $this->withHeaders($this->userHeaders($customer))
            ->getJson("/api/v1/user/tickets/{$ticket->ticket}")
            ->assertOk();

        // The thread carries an id to download by, never a guessable media URL.
        $attachment = $response->json('data.messages.0.attachments.0');

        $this->assertArrayHasKey('id', $attachment);
        $this->assertArrayNotHasKey('url', $attachment);
        $this->assertStringNotContainsString('/media/assets/attachments', $response->getContent());
    }

    /**
     * The ownership check on the download endpoint is only worth anything if
     * the file has no second, unauthenticated way in. /media serves the whole
     * public disk, so an attachment written there would be readable by anyone
     * holding the filename — which is exactly what the endpoint exists to stop.
     */
    public function test_an_attachment_is_not_reachable_through_the_public_media_route(): void
    {
        $customer = $this->makeCustomer();
        [, $attachmentId] = $this->ticketWithAttachment($customer);

        $filename = \App\Models\SupportAttachment::findOrFail($attachmentId)->attachment;

        $this->flushHeaders();
        $this->forgetAuthenticatedUser();

        $this->get("/media/assets/attachments/{$filename}")->assertNotFound();

        // And it is not on the public disk in the first place.
        Storage::disk('public')->assertMissing("assets/attachments/{$filename}");
        Storage::disk('local')->assertExists("assets/attachments/{$filename}");
    }

    /**
     * Even a file already sitting on the public disk — written before the path
     * key became private — must not be served by /media.
     */
    public function test_the_media_route_refuses_private_directories_outright(): void
    {
        Storage::disk('public')->put('assets/attachments/legacy-receipt.pdf', 'sensitive');

        $this->get('/media/assets/attachments/legacy-receipt.pdf')->assertNotFound();

        // A genuinely public directory still works, so the block is targeted.
        Storage::disk('public')->put('assets/images/product/banner.png', 'image-bytes');
        $this->get('/media/assets/images/product/banner.png')->assertOk();
    }

    public function test_staff_can_download_a_ticket_attachment(): void
    {
        $customer = $this->makeCustomer();
        [, $attachmentId] = $this->ticketWithAttachment($customer);

        $admin = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->withHeaders($this->adminHeaders($admin))
            ->get("/api/v1/admin/tickets/attachments/{$attachmentId}")
            ->assertOk();
    }

    /* -------------------------------- Invoice ------------------------------ */

    private function makeOrder($customer): Order
    {
        return Order::create([
            'company_id' => \App\Models\Company::current()->id,
            'order_number' => 'VIP-TEST-0001',
            'user_id' => $customer->id,
            'branch_id' => $this->branch->id,
            'status' => Status::ORDER_DELIVERED,
            'payment_status' => Status::PAYMENT_SUCCESS,
            'subtotal' => 50000,
            'total' => 50000,
            'shipping_address' => ['name' => $customer->fullname, 'city' => 'Dar es Salaam'],
        ]);
    }

    public function test_a_customer_can_download_the_invoice_for_their_own_order(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer);

        $response = $this->withHeaders($this->userHeaders($customer))
            ->get("/api/v1/user/orders/{$order->order_number}/invoice")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_a_customer_cannot_download_somebody_elses_invoice(): void
    {
        $owner = $this->makeCustomer();
        $order = $this->makeOrder($owner);

        $intruder = $this->makeCustomer();

        $this->withHeaders($this->userHeaders($intruder))
            ->getJson("/api/v1/user/orders/{$order->order_number}/invoice")
            ->assertNotFound();
    }

    /* ----------------------------- Cookie notice --------------------------- */

    public function test_the_cookie_notice_is_published_while_enabled(): void
    {
        $cookie = $this->getJson('/api/v1/settings')->assertOk()->json('data.cookie');

        $this->assertNotNull($cookie);
        $this->assertNotEmpty($cookie['short_desc']);
        $this->assertNotEmpty($cookie['description']);
    }

    /* ---------------------------- Maintenance ------------------------------ */

    public function test_maintenance_mode_closes_the_storefront_and_explains_why(): void
    {
        $settings = \App\Models\GeneralSetting::current();
        $settings->maintenance_mode = 1;
        $settings->save();
        \App\Models\GeneralSetting::flush();

        $response = $this->getJson('/api/v1/home')
            ->assertStatus(503)
            ->assertJsonPath('remark', 'maintenance_mode');

        $this->assertNotEmpty($response->json('data.maintenance.description'));

        // Settings stays reachable so the storefront can render the notice.
        $settingsResponse = $this->getJson('/api/v1/settings')->assertOk();
        $this->assertTrue($settingsResponse->json('data.site.maintenance_mode'));
        $this->assertNotNull($settingsResponse->json('data.maintenance'));
    }

    public function test_maintenance_content_is_absent_while_the_shop_is_open(): void
    {
        $this->assertNull($this->getJson('/api/v1/settings')->json('data.maintenance'));
        $this->getJson('/api/v1/home')->assertOk();
    }

    public function test_staff_can_still_reach_the_admin_panel_during_maintenance(): void
    {
        $settings = \App\Models\GeneralSetting::current();
        $settings->maintenance_mode = 1;
        $settings->save();
        \App\Models\GeneralSetting::flush();

        $admin = $this->makeStaff(Roles::SUPER_ADMIN);

        // Otherwise an administrator could never switch the shop back on.
        $this->withHeaders($this->adminHeaders($admin))
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();
    }

    public function test_the_cookie_notice_disappears_when_switched_off(): void
    {
        $row = Frontend::where('data_keys', 'cookie.data')->firstOrFail();
        $values = (array) $row->data_values;
        $values['status'] = 0;
        $row->data_values = $values;
        $row->save();

        $this->assertNull($this->getJson('/api/v1/settings')->json('data.cookie'));
    }
}
