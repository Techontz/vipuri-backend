<?php

namespace Database\Seeders;

use App\Constants\Status;
use App\Models\Extension;
use App\Models\Frontend;
use App\Models\GeneralSetting;
use App\Models\Language;
use App\Models\NotificationTemplate;
use App\Models\Page;
use App\Services\Captcha;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * System settings, CMS content, pages, languages, extensions and the
 * notification template catalogue.
 *
 * CMS rows are loaded from database/seeders/data/frontend_sections.json, which
 * mirrors the section keys and shapes of the source system so the replicated
 * frontend renders identically — with VIPURI branding and Tanzanian details.
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $this->generalSettings();
        $this->frontendSections();
        $this->pages();
        $this->languages();
        $this->extensions();
        $this->notificationTemplates();

        Cache::forget('GeneralSetting');
    }

    private function generalSettings(): void
    {
        GeneralSetting::updateOrCreate(['id' => 1], [
            'site_name' => 'VIPURI',
            'cur_text' => 'TZS',
            'cur_sym' => 'TSh',
            'currency_format' => Status::CUR_TEXT,
            'email_from' => 'no-reply@vipuri.co.tz',
            'email_from_name' => 'VIPURI',
            'email_template' => '<div style="font-family:Inter,Arial,sans-serif;background:#f6f7fb;padding:32px">'
                . '<div style="max-width:600px;margin:auto;background:#fff;border-radius:12px;padding:32px">'
                . '<h2 style="color:#ff7a00;margin:0 0 16px">VIPURI</h2>{{message}}'
                . '<p style="color:#8b95a5;font-size:12px;margin-top:28px">'
                . 'VIPURI Auto Parts Limited &middot; Dar es Salaam, Tanzania</p></div></div>',
            'sms_template' => '{{message}}',
            'sms_from' => 'VIPURI',
            'push_title' => 'VIPURI',
            'push_template' => '{{message}}',
            'base_color' => 'FF7A00',
            'secondary_color' => '6B7A99',
            'ev' => Status::DISABLE,
            'en' => Status::ENABLE,
            'sv' => Status::DISABLE,
            'sn' => Status::DISABLE,
            'pn' => Status::DISABLE,
            'force_ssl' => Status::DISABLE,
            'in_app_payment' => Status::ENABLE,
            'has_cod' => Status::ENABLE,
            'maintenance_mode' => Status::DISABLE,
            'secure_password' => Status::DISABLE,
            'agree' => Status::ENABLE,
            'multi_language' => Status::ENABLE,
            'registration' => Status::ENABLE,
            'active_template' => 'basic',
            'paginate_number' => 12,
            'order_number_prefix' => 'VP',
            'default_engine' => Status::OPENAI_MODEL,
            'openai_api_model' => 'gpt-4o-mini',
            'ai_review_summary' => Status::ENABLE,
            'ai_product_chat' => Status::ENABLE,
        ]);
    }

    private function frontendSections(): void
    {
        $path = database_path('seeders/data/frontend_sections.json');

        if (! file_exists($path)) {
            $this->command?->warn('frontend_sections.json is missing — skipping CMS content.');

            return;
        }

        $rows = json_decode(file_get_contents($path), true) ?: [];

        foreach ($rows as $row) {
            // The model casts these columns to objects, so hand it decoded
            // arrays — passing the raw JSON string would double-encode it.
            $values = json_decode($row['data_values'] ?: '{}', true) ?: [];
            $seo = $row['seo_content'] ? (json_decode($row['seo_content'], true) ?: null) : null;

            $lookup = ['data_keys' => $row['data_keys'], 'tempname' => 'basic'];

            // Element rows repeat per key, so match on the slug when there is
            // one and otherwise on a stable field inside the payload.
            if (! empty($row['slug'])) {
                $lookup['slug'] = $row['slug'];
            }

            $existing = Frontend::where($lookup)
                ->when(
                    str_ends_with($row['data_keys'], '.element') && empty($row['slug']),
                    fn ($q) => $q->whereJsonContains('data_values', $this->elementSignature($values))
                )
                ->first();

            $payload = [
                'data_values' => $values,
                'seo_content' => $seo,
                'slug' => $row['slug'] ?: null,
                'tempname' => 'basic',
            ];

            $existing
                ? $existing->update($payload)
                : Frontend::create($payload + ['data_keys' => $row['data_keys']]);
        }
    }

    /** A field that identifies a CMS list item, used to avoid duplicates on re-seed. */
    private function elementSignature(array $values): array
    {
        foreach (['title', 'name', 'image', 'brand_image', 'icon', 'heading'] as $field) {
            if (! empty($values[$field]) && is_string($values[$field])) {
                return [$field => $values[$field]];
            }
        }

        return $values;
    }

    private function pages(): void
    {
        $pages = [
            [
                'name' => 'Home',
                'slug' => 'home',
                'is_default' => 1,
                // Section order copied from the source system's home page, with
                // the vehicle finder ("search") kept where the theme renders it.
                'secs' => [
                    'search', 'highlight', 'popular_categories', 'top_selling_product', 'brand',
                    'latest_product', 'top_deals', 'limited_stock', 'about', 'testimonial',
                    'video_feature', 'special_offer', 'blog', 'cta', 'client',
                ],
            ],
            ['name' => 'Products', 'slug' => 'products', 'is_default' => 1, 'secs' => ['page_banner', 'search']],
            ['name' => 'Brands', 'slug' => 'brands', 'is_default' => 1, 'secs' => ['page_banner', 'brand']],
            ['name' => 'About Us', 'slug' => 'about-us', 'is_default' => 0, 'secs' => ['page_banner', 'about', 'highlight', 'testimonial', 'client']],
            ['name' => 'Contact', 'slug' => 'contact', 'is_default' => 1, 'secs' => ['page_banner', 'contact']],
        ];

        foreach ($pages as $page) {
            Page::updateOrCreate(
                ['slug' => $page['slug']],
                [
                    'name' => $page['name'],
                    'tempname' => 'basic',
                    'secs' => $page['secs'],
                    'is_default' => $page['is_default'],
                ],
            );
        }
    }

    private function languages(): void
    {
        // English is the source language: its keys are the strings themselves,
        // so it needs no translation map.
        Language::updateOrCreate(['code' => 'en'], ['name' => 'English', 'is_default' => 1]);

        Language::updateOrCreate(['code' => 'sw'], [
            'name' => 'Kiswahili',
            'is_default' => 0,
            'translations' => json_decode(
                file_get_contents(database_path('seeders/data/translations_sw.json')),
                true,
            ),
        ]);
    }

    private function extensions(): void
    {
        $extensions = [
            [
                'act' => 'google-recaptcha2',
                'name' => 'Google Recaptcha 2',
                'description' => 'Protects login, registration, password recovery and the contact form with Google reCAPTCHA v2.',
                'shortcode' => [
                    'site_key' => ['title' => 'Site Key', 'value' => ''],
                    'secret_key' => ['title' => 'Secret Key', 'value' => ''],
                ],
            ],
            [
                'act' => 'custom-captcha',
                'name' => 'Custom Captcha',
                'description' => 'A self-hosted six-digit captcha. Needs no third-party account.',
                'shortcode' => [
                    'random_key' => ['title' => 'Random String', 'value' => Captcha::randomKey()],
                ],
            ],
            [
                'act' => 'google-analytics',
                'name' => 'Google Analytics',
                'description' => 'Track storefront traffic with Google Analytics.',
                'shortcode' => ['tracking_id' => ['title' => 'Measurement ID', 'value' => '']],
            ],
            [
                'act' => 'tawk-chat',
                'name' => 'Tawk.to Live Chat',
                'description' => 'Live chat widget for customer support.',
                'shortcode' => ['property_id' => ['title' => 'Property ID', 'value' => ''], 'widget_id' => ['title' => 'Widget ID', 'value' => '']],
            ],
            [
                'act' => 'facebook-comment',
                'name' => 'Facebook Comments',
                'description' => 'Facebook comment box on blog posts.',
                'shortcode' => ['app_id' => ['title' => 'App ID', 'value' => '']],
            ],
            [
                'act' => 'facebook-messenger',
                'name' => 'Facebook Messenger',
                'description' => 'Messenger chat plugin.',
                'shortcode' => ['page_id' => ['title' => 'Page ID', 'value' => '']],
            ],
            [
                'act' => 'custom-script',
                'name' => 'Custom Script',
                'description' => 'Inject a custom analytics or marketing script.',
                'shortcode' => ['script' => ['title' => 'Script', 'value' => '']],
            ],
        ];

        foreach ($extensions as $extension) {
            Extension::updateOrCreate(
                ['act' => $extension['act']],
                [
                    'name' => $extension['name'],
                    'description' => $extension['description'],
                    'shortcode' => $extension['shortcode'],
                    'status' => Status::DISABLE,
                ],
            );
        }
    }

    private function notificationTemplates(): void
    {
        $templates = [
            [
                'act' => 'EVER_CODE',
                'name' => 'Email / SMS verification code',
                'subject' => 'Verify your VIPURI account',
                'email_body' => '<p>Hello {{fullname}},</p><p>Your VIPURI verification code is <strong>{{code}}</strong>. It expires in 15 minutes.</p>',
                'sms_body' => 'Your VIPURI verification code is {{code}}',
                'shortcodes' => ['fullname' => 'Customer name', 'code' => 'Verification code'],
            ],
            [
                'act' => 'PASS_RESET_CODE',
                'name' => 'Password reset code',
                'subject' => 'Reset your VIPURI password',
                'email_body' => '<p>Hello {{fullname}},</p><p>Your password reset code is <strong>{{code}}</strong>. It expires in one hour.</p><p>If you did not request this, you can safely ignore this e-mail.</p>',
                'sms_body' => 'Your VIPURI password reset code is {{code}}',
                'shortcodes' => ['fullname' => 'Customer name', 'code' => 'Reset code'],
            ],
            [
                'act' => 'PASS_RESET_DONE',
                'name' => 'Password changed',
                'subject' => 'Your VIPURI password was changed',
                'email_body' => '<p>Hello {{fullname}},</p><p>Your VIPURI password has just been changed. If this was not you, contact us immediately.</p>',
                'sms_body' => 'Your VIPURI password was changed.',
                'shortcodes' => ['fullname' => 'Customer name'],
            ],
            [
                'act' => 'ORDER_PLACED',
                'name' => 'Order placed',
                'subject' => 'We received your order {{order_number}}',
                'email_body' => '<p>Hello {{fullname}},</p><p>Thank you for shopping with VIPURI. Your order <strong>{{order_number}}</strong> for <strong>{{amount}}</strong> has been received and is <strong>{{status}}</strong>.</p>',
                'sms_body' => 'VIPURI: order {{order_number}} received, total {{amount}}.',
                'shortcodes' => ['fullname' => 'Customer name', 'order_number' => 'Order number', 'amount' => 'Order total', 'status' => 'Order status'],
            ],
            [
                'act' => 'ORDER_PAID',
                'name' => 'Payment received',
                'subject' => 'Payment received for {{order_number}}',
                'email_body' => '<p>Hello {{fullname}},</p><p>We have received your payment of <strong>{{amount}}</strong> for order <strong>{{order_number}}</strong>.</p>',
                'sms_body' => 'VIPURI: payment of {{amount}} received for {{order_number}}.',
                'shortcodes' => ['fullname' => 'Customer name', 'order_number' => 'Order number', 'amount' => 'Amount paid'],
            ],
            [
                'act' => 'ORDER_STATUS_CHANGED',
                'name' => 'Order status changed',
                'subject' => 'Your order {{order_number}} is now {{status}}',
                'email_body' => '<p>Hello {{fullname}},</p><p>Your order <strong>{{order_number}}</strong> is now <strong>{{status}}</strong>.</p>',
                'sms_body' => 'VIPURI: order {{order_number}} is now {{status}}.',
                'shortcodes' => ['fullname' => 'Customer name', 'order_number' => 'Order number', 'status' => 'New status'],
            ],

            /* One template per order transition, matching the source system so
               each stage can be worded for what actually happened. */
            [
                'act' => 'ORDER_ON_PROCESSING_CONFIRMATION',
                'name' => 'Order being prepared',
                'subject' => 'We are preparing order {{order_number}}',
                'email_body' => '<p>Hello {{fullname}},</p><p>Your order <strong>{{order_number}}</strong> is being picked and packed at your VIPURI branch. We will let you know the moment it leaves us.</p>',
                'sms_body' => 'VIPURI: we are preparing order {{order_number}}.',
                'shortcodes' => ['fullname' => 'Customer name', 'order_number' => 'Order number', 'status' => 'Order status', 'amount' => 'Order total'],
            ],
            [
                'act' => 'ORDER_DISPATCHED_CONFIRMATION',
                'name' => 'Order dispatched',
                'subject' => 'Order {{order_number}} is on its way',
                'email_body' => '<p>Hello {{fullname}},</p><p>Your order <strong>{{order_number}}</strong> has left our branch and is on its way to you.</p>',
                'sms_body' => 'VIPURI: order {{order_number}} has been dispatched.',
                'shortcodes' => ['fullname' => 'Customer name', 'order_number' => 'Order number', 'status' => 'Order status', 'amount' => 'Order total'],
            ],
            [
                'act' => 'ORDER_DELIVERY_CONFIRMATION',
                'name' => 'Order delivered',
                'subject' => 'Order {{order_number}} delivered',
                'email_body' => '<p>Hello {{fullname}},</p><p>Your order <strong>{{order_number}}</strong> has been delivered. If anything is not right, reply to this e-mail and we will sort it out.</p>',
                'sms_body' => 'VIPURI: order {{order_number}} has been delivered.',
                'shortcodes' => ['fullname' => 'Customer name', 'order_number' => 'Order number', 'status' => 'Order status', 'amount' => 'Order total'],
            ],
            [
                'act' => 'ORDER_CANCELLATION_CONFIRMATION',
                'name' => 'Order cancelled',
                'subject' => 'Order {{order_number}} cancelled',
                'email_body' => '<p>Hello {{fullname}},</p><p>Order <strong>{{order_number}}</strong> has been cancelled. Any payment already made will be refunded through the same method.</p>',
                'sms_body' => 'VIPURI: order {{order_number}} has been cancelled.',
                'shortcodes' => ['fullname' => 'Customer name', 'order_number' => 'Order number', 'status' => 'Order status', 'amount' => 'Order total'],
            ],
            [
                'act' => 'ORDER_RETURNED_CONFIRMATION',
                'name' => 'Order returned',
                'subject' => 'Return received for {{order_number}}',
                'email_body' => '<p>Hello {{fullname}},</p><p>We have received the return for order <strong>{{order_number}}</strong> and the items are back in stock at the branch.</p>',
                'sms_body' => 'VIPURI: return received for order {{order_number}}.',
                'shortcodes' => ['fullname' => 'Customer name', 'order_number' => 'Order number', 'status' => 'Order status', 'amount' => 'Order total'],
            ],

            /* Manual payments — submitted, then approved or rejected by staff. */
            [
                'act' => 'DEPOSIT_REQUEST',
                'name' => 'Manual payment submitted',
                'subject' => 'We received your payment details',
                'email_body' => '<p>Hello {{fullname}},</p><p>Thank you. We have your <strong>{{gateway}}</strong> payment of <strong>{{amount}}</strong> (reference {{trx}}) and will confirm it shortly.</p>',
                'sms_body' => 'VIPURI: payment details received for {{trx}}. We will confirm shortly.',
                'shortcodes' => ['fullname' => 'Customer name', 'trx' => 'Transaction reference', 'amount' => 'Amount', 'gateway' => 'Payment method', 'order_number' => 'Order number'],
            ],
            [
                'act' => 'DEPOSIT_APPROVE',
                'name' => 'Manual payment approved',
                'subject' => 'Payment {{trx}} confirmed',
                'email_body' => '<p>Hello {{fullname}},</p><p>Your <strong>{{gateway}}</strong> payment of <strong>{{amount}}</strong> has been confirmed and applied to order <strong>{{order_number}}</strong>.</p>',
                'sms_body' => 'VIPURI: payment {{trx}} of {{amount}} confirmed.',
                'shortcodes' => ['fullname' => 'Customer name', 'trx' => 'Transaction reference', 'amount' => 'Amount', 'gateway' => 'Payment method', 'order_number' => 'Order number'],
            ],
            [
                'act' => 'DEPOSIT_REJECT',
                'name' => 'Manual payment rejected',
                'subject' => 'We could not confirm payment {{trx}}',
                'email_body' => '<p>Hello {{fullname}},</p><p>We were unable to confirm your <strong>{{gateway}}</strong> payment of <strong>{{amount}}</strong>.</p><p>{{reason}}</p><p>Please check the reference and submit it again, or contact us.</p>',
                'sms_body' => 'VIPURI: we could not confirm payment {{trx}}. Please contact us.',
                'shortcodes' => ['fullname' => 'Customer name', 'trx' => 'Transaction reference', 'amount' => 'Amount', 'gateway' => 'Payment method', 'order_number' => 'Order number', 'reason' => 'Reason given by staff'],
            ],

            /* Review moderation and support. */
            [
                'act' => 'REVIEW_APPROVE',
                'name' => 'Review published',
                'subject' => 'Your review is live',
                'email_body' => '<p>Hello {{fullname}},</p><p>Thank you — your {{rating}}-star review of <strong>{{product}}</strong> is now published.</p>',
                'sms_body' => 'VIPURI: your review of {{product}} is now published.',
                'shortcodes' => ['fullname' => 'Customer name', 'product' => 'Product name', 'rating' => 'Star rating'],
            ],
            [
                'act' => 'REVIEW_REJECT',
                'name' => 'Review not published',
                'subject' => 'About your review of {{product}}',
                'email_body' => '<p>Hello {{fullname}},</p><p>Your review of <strong>{{product}}</strong> was not published because it did not meet our review guidelines. You are welcome to submit another.</p>',
                'sms_body' => 'VIPURI: your review of {{product}} was not published.',
                'shortcodes' => ['fullname' => 'Customer name', 'product' => 'Product name', 'rating' => 'Star rating'],
            ],
            [
                'act' => 'ADMIN_SUPPORT_REPLY',
                'name' => 'Support reply',
                'subject' => 'Re: {{subject}} [{{ticket}}]',
                'email_body' => '<p>Hello {{fullname}},</p><p>VIPURI support has replied to your ticket <strong>{{ticket}}</strong> about "{{subject}}". Sign in to read the reply.</p>',
                'sms_body' => 'VIPURI: support has replied to ticket {{ticket}}.',
                'shortcodes' => ['fullname' => 'Customer name', 'ticket' => 'Ticket number', 'subject' => 'Ticket subject'],
            ],
        ];

        foreach ($templates as $template) {
            NotificationTemplate::updateOrCreate(
                ['act' => $template['act']],
                [
                    'name' => $template['name'],
                    'subject' => $template['subject'],
                    'email_body' => $template['email_body'],
                    'sms_body' => $template['sms_body'],
                    'push_title' => 'VIPURI',
                    'push_body' => $template['sms_body'],
                    'shortcodes' => $template['shortcodes'],
                    'email_status' => Status::ENABLE,
                    'sms_status' => Status::ENABLE,
                    'push_status' => Status::DISABLE,
                ],
            );
        }
    }
}
