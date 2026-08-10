<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Constants\Status;
use App\Models\Branch;
use App\Models\GeneralSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Company;
use App\Models\ProductReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAndContentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->branch = Branch::where('code', 'DSM-01')->firstOrFail();
    }

    /* ---------------------------------- AI --------------------------------- */

    public function test_ai_chat_reports_a_missing_api_key_rather_than_failing_silently(): void
    {
        config(['vipuri.ai.openai.key' => null, 'vipuri.ai.gemini.key' => null]);

        $product = $this->makeProduct($this->branch);

        $response = $this->postJson('/api/v1/ai/chat', [
            'product_id' => $product->id,
            'message' => 'Does this fit a 2020 Hilux?',
        ]);

        $response->assertStatus(422)->assertJsonPath('remark', 'missing_api_key');
    }

    public function test_ai_chat_answers_when_a_provider_is_configured(): void
    {
        config(['vipuri.ai.openai.key' => 'sk-test']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Yes, this part fits the 2020 Hilux.']]],
            ]),
        ]);

        $product = $this->makeProduct($this->branch, 5, ['vehicle_model' => 'Hilux', 'vehicle_year' => 2020]);

        $this->postJson('/api/v1/ai/chat', [
            'product_id' => $product->id,
            'message' => 'Does this fit a 2020 Hilux?',
        ])
            ->assertOk()
            ->assertJsonPath('data.reply', 'Yes, this part fits the 2020 Hilux.');
    }

    public function test_ai_features_can_be_switched_off(): void
    {
        GeneralSetting::current()->update(['ai_product_chat' => 0]);
        GeneralSetting::flush();

        $product = $this->makeProduct($this->branch);

        $this->postJson('/api/v1/ai/chat', ['product_id' => $product->id, 'message' => 'Hello'])
            ->assertStatus(422)
            ->assertJsonPath('remark', 'feature_disabled');
    }

    public function test_the_review_summary_needs_reviews_to_summarise(): void
    {
        config(['vipuri.ai.openai.key' => 'sk-test']);
        $product = $this->makeProduct($this->branch);

        $this->withHeaders($this->userHeaders($this->makeCustomer()))
            ->postJson('/api/v1/ai/review-summary', ['product_id' => $product->id])
            ->assertStatus(422)
            ->assertJsonPath('remark', 'no_reviews');
    }

    /* --------------------- Review summary: signed-in only ------------------ */

    /**
     * The endpoint forwards a caller-supplied instruction to the configured
     * model on the company's account, so it is not open to anonymous traffic.
     * These cover the boundary without touching what the feature does.
     */
    private function summarisableProduct(): \App\Models\Product
    {
        config(['vipuri.ai.openai.key' => 'sk-test']);

        $product = $this->makeProduct($this->branch);

        ProductReview::create([
            'product_id' => $product->id,
            'user_id' => $this->makeCustomer()->id,
            'rating' => 5,
            'review' => 'Fitted my Hilux perfectly and has lasted two seasons.',
            'status' => Status::REVIEW_APPROVED,
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Overall sentiment: positive. Verdict: Buy.']]],
            ]),
        ]);

        return $product;
    }

    public function test_an_anonymous_visitor_cannot_generate_a_review_summary(): void
    {
        $product = $this->summarisableProduct();

        $this->postJson('/api/v1/ai/review-summary', ['product_id' => $product->id])
            ->assertUnauthorized()
            ->assertJsonPath('remark', 'unauthenticated');

        // Nothing was spent on the model's behalf.
        Http::assertNothingSent();
    }

    public function test_a_signed_in_customer_can_generate_a_review_summary(): void
    {
        $product = $this->summarisableProduct();

        $this->withHeaders($this->userHeaders($this->makeCustomer()))
            ->postJson('/api/v1/ai/review-summary', ['product_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('remark', 'ai_review_summary');
    }

    /**
     * Same request, same answer: authentication is the only thing that changed,
     * so the payload a working call returns must be exactly what it was.
     */
    public function test_the_summary_a_valid_request_returns_is_unchanged(): void
    {
        $product = $this->summarisableProduct();

        $response = $this->withHeaders($this->userHeaders($this->makeCustomer()))
            ->postJson('/api/v1/ai/review-summary', [
                'product_id' => $product->id,
                'prompt' => 'Is this worth buying?',
            ])
            ->assertOk();

        $response->assertJsonPath('data.summary', 'Overall sentiment: positive. Verdict: Buy.')
            ->assertJsonPath('data.total_reviews', 1)
            ->assertJsonPath('data.average_rating', 5)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['remark', 'status', 'message', 'data' => ['summary', 'total_reviews', 'average_rating']]);
    }

    public function test_the_per_minute_limit_still_applies_to_a_signed_in_caller(): void
    {
        $product = $this->summarisableProduct();
        $headers = $this->userHeaders($this->makeCustomer());

        // The route's own throttle is 20 a minute, well below the 240 a signed-in
        // caller gets overall, so the 21st call is the one that must be refused.
        for ($call = 1; $call <= 20; $call++) {
            $this->withHeaders($headers)
                ->postJson('/api/v1/ai/review-summary', ['product_id' => $product->id])
                ->assertOk();
        }

        $this->withHeaders($headers)
            ->postJson('/api/v1/ai/review-summary', ['product_id' => $product->id])
            ->assertStatus(429);
    }

    public function test_admin_ai_generation_requires_permission(): void
    {
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);

        $this->withHeaders($this->adminHeaders($worker))
            ->postJson('/api/v1/admin/products/ai-generate', ['name' => 'Brake Pad'])
            ->assertStatus(403);
    }

    public function test_admin_ai_generation_returns_structured_copy(): void
    {
        config(['vipuri.ai.openai.key' => 'sk-test']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'short_description' => 'A short description.',
                            'description' => '<p>Long copy</p>',
                            'meta_title' => 'Brake Pad | VIPURI',
                            'meta_description' => 'Buy brake pads.',
                            'meta_keywords' => 'brake, pad',
                            'specifications' => [['key' => 'Material', 'value' => 'Ceramic']],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $super = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->withHeaders($this->adminHeaders($super))
            ->postJson('/api/v1/admin/products/ai-generate', ['name' => 'Brake Pad'])
            ->assertOk()
            ->assertJsonPath('data.content.meta_title', 'Brake Pad | VIPURI');
    }

    /* -------------------------------- Reviews ------------------------------ */

    private function deliveredOrderFor($product, $customer): void
    {
        $order = Order::create([
            'company_id' => Company::current()->id,
            'branch_id' => $this->branch->id,
            'order_number' => 'VP' . strtoupper(uniqid()),
            'user_id' => $customer->id,
            'guest_id' => 0,
            'status' => Status::ORDER_DELIVERED,
            'payment_status' => Status::PAYMENT_SUCCESS,
            'subtotal' => 100000,
            'total' => 100000,
            'shipping_address' => ['city' => 'Dar es Salaam'],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variation_id' => 0,
            'quantity' => 1,
            'price' => 100000,
            'subtotal' => 100000,
        ]);
    }

    public function test_only_verified_purchasers_can_review(): void
    {
        $product = $this->makeProduct($this->branch);
        $customer = $this->makeCustomer();

        $this->withHeaders($this->userHeaders($customer))
            ->postJson('/api/v1/user/reviews', ['product_id' => $product->id, 'rating' => 5])
            ->assertStatus(422)
            ->assertJsonPath('remark', 'not_purchased');
    }

    public function test_a_purchaser_can_review_and_it_waits_for_approval(): void
    {
        $product = $this->makeProduct($this->branch);
        $customer = $this->makeCustomer();
        $this->deliveredOrderFor($product, $customer);

        $this->withHeaders($this->userHeaders($customer))
            ->postJson('/api/v1/user/reviews', [
                'product_id' => $product->id,
                'rating' => 5,
                'review' => 'Fitted perfectly.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('product_reviews', [
            'product_id' => $product->id,
            'user_id' => $customer->id,
            'status' => Status::REVIEW_PENDING,
        ]);

        // A pending review is not shown on the storefront.
        $this->getJson("/api/v1/products/{$product->id}/reviews")
            ->assertOk()
            ->assertJsonCount(0, 'data.reviews');
    }

    public function test_an_approved_review_appears_on_the_storefront(): void
    {
        $product = $this->makeProduct($this->branch);
        $customer = $this->makeCustomer();
        $this->deliveredOrderFor($product, $customer);

        $review = ProductReview::create([
            'product_id' => $product->id,
            'user_id' => $customer->id,
            'rating' => 4,
            'review' => 'Good value.',
            'status' => Status::REVIEW_PENDING,
        ]);

        $super = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->withHeaders($this->adminHeaders($super))
            ->postJson("/api/v1/admin/reviews/{$review->id}/approve")
            ->assertOk();

        $this->getJson("/api/v1/products/{$product->id}/reviews")
            ->assertOk()
            ->assertJsonCount(1, 'data.reviews')
            ->assertJsonPath('data.reviews.0.review', 'Good value.');
    }

    /* -------------------------------- Content ------------------------------ */

    public function test_the_settings_endpoint_reports_tzs_and_vipuri_branding(): void
    {
        $response = $this->getJson('/api/v1/settings')->assertOk();

        $this->assertSame('VIPURI', $response->json('data.site.name'));
        $this->assertSame('TZS', $response->json('data.site.currency'));
        $this->assertSame('Tanzania', $response->json('data.company.country_name'));
    }

    public function test_the_branch_directory_is_public(): void
    {
        $response = $this->getJson('/api/v1/branches')->assertOk();

        $this->assertGreaterThanOrEqual(5, count($response->json('data.branches')));
        $this->assertSame('VIPURI Kariakoo', $response->json('data.branches.0.name'));
    }

    public function test_the_home_endpoint_returns_cms_sections(): void
    {
        $response = $this->getJson('/api/v1/home')->assertOk();

        $this->assertNotEmpty($response->json('data.section_order'));
        $this->assertArrayHasKey('banner.element', $response->json('data.sections'));
    }

    public function test_maintenance_mode_closes_the_storefront_but_not_the_admin(): void
    {
        $super = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->withHeaders($this->adminHeaders($super))
            ->postJson('/api/v1/admin/settings/maintenance-mode', ['maintenance_mode' => true])
            ->assertOk();

        $this->getJson('/api/v1/products')->assertStatus(503)->assertJsonPath('remark', 'maintenance_mode');

        $this->withHeaders($this->adminHeaders($super))->getJson('/api/v1/admin/dashboard')->assertOk();
    }

    public function test_the_contact_form_creates_a_support_ticket(): void
    {
        $this->postJson('/api/v1/contact', [
            'name' => 'Asha',
            'email' => 'asha@example.co.tz',
            'subject' => 'Do you stock Hilux mirrors?',
            'message' => 'Looking for a right-hand mirror.',
        ])->assertOk();

        $this->assertDatabaseHas('support_tickets', ['subject' => 'Do you stock Hilux mirrors?']);
        $this->assertDatabaseHas('support_messages', ['message' => 'Looking for a right-hand mirror.']);
    }

    public function test_newsletter_subscription_is_recorded_once(): void
    {
        $this->postJson('/api/v1/subscribe', ['email' => 'news@example.co.tz'])->assertOk();
        $this->postJson('/api/v1/subscribe', ['email' => 'news@example.co.tz'])->assertOk();

        $this->assertSame(1, \App\Models\Subscriber::where('email', 'news@example.co.tz')->count());
    }

    public function test_administrative_actions_are_written_to_the_audit_log(): void
    {
        $super = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->withHeaders($this->adminHeaders($super))
            ->postJson('/api/v1/admin/branches', [
                'name' => 'VIPURI Mbeya',
                'code' => 'MBY-01',
                'city' => 'Mbeya',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'branch.created',
            'actor_type' => 'admin',
            'actor_id' => $super->id,
        ]);
    }
}
