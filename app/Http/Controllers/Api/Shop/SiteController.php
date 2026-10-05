<?php

namespace App\Http\Controllers\Api\Shop;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Http\Resources\ProductCardResource;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Frontend;
use App\Models\Language;
use App\Models\Page;
use App\Models\Product;
use App\Models\Subscriber;
use App\Models\SupportTicket;
use App\Services\Captcha;
use App\Services\NotificationService;
use App\Services\SocialLogin;
use App\Support\CmsContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Site-wide storefront data: settings, CMS sections, home page, static pages,
 * blog, contact form and branch directory.
 */
class SiteController extends Controller
{
    /** Everything the Next.js shell needs on boot. */
    public function settings()
    {
        $settings = gs();
        $company = Company::query()->orderBy('id')->first();

        $contact = CmsContent::content('contact');
        $footer = CmsContent::content('footer');
        $socials = CmsContent::elements('social_icon');
        $logo = Frontend::where('data_keys', 'logo_icon.data')->first();

        return responseSuccess('settings', 'Settings fetched', [
            'site' => [
                'name' => $settings?->site_name ?? 'VIPURI',
                'currency' => currencyText(),
                'currency_symbol' => currencySymbol(),
                'currency_format' => (int) ($settings?->currency_format ?? 1),
                'base_color' => $settings?->base_color,
                'secondary_color' => $settings?->secondary_color,
                'registration_enabled' => (bool) ($settings?->registration ?? true),
                'email_verification' => (bool) ($settings?->ev ?? false),
                'mobile_verification' => (bool) ($settings?->sv ?? false),
                'maintenance_mode' => (bool) ($settings?->maintenance_mode ?? false),
                'has_cod' => (bool) ($settings?->has_cod ?? true),
                'ai_review_summary' => (bool) ($settings?->ai_review_summary ?? true),
                'ai_product_chat' => (bool) ($settings?->ai_product_chat ?? true),
                'paginate_number' => (int) ($settings?->paginate_number ?? 12),
                'logo' => fileUrl('logoIcon', $logo?->data_values?->logo ?? null),
                'logo_dark' => fileUrl('logoIcon', $logo?->data_values?->logo_dark ?? null),
                'favicon' => fileUrl('logoIcon', $logo?->data_values?->favicon ?? null),
            ],
            'company' => $company ? [
                'name' => $company->name,
                'legal_name' => $company->legal_name,
                'email' => $company->email,
                'phone' => $company->phone,
                'tin' => $company->tin,
                'address' => $company->address,
                'city' => $company->city,
                'region' => $company->region,
                'country_name' => $company->country_name,
            ] : null,
            'contact' => $contact,
            'footer' => $footer,
            // The accepted-payment logos strip in the footer bottom bar.
            'footer_payments' => CmsContent::elements('footer')->values(),
            'page_banner' => CmsContent::content('page_banner'),
            // GDPR notice: short text for the banner, full text for the policy
            // page. Absent entirely when an administrator has switched it off.
            'cookie' => (function () {
                $cookie = CmsContent::data('cookie');

                return ($cookie['status'] ?? false) ? $cookie : null;
            })(),
            'captcha' => app(Captcha::class)->config(),
            // Only while the shop is closed: the storefront layout renders the
            // maintenance page instead of the requested one.
            'maintenance' => $settings?->maintenance_mode ? CmsContent::data('maintenance') : null,
            'languages' => Language::orderByDesc('is_default')->orderBy('name')
                ->get(['code', 'name', 'is_default'])
                ->map(fn (Language $l) => [
                    'code' => $l->code,
                    'name' => $l->name,
                    'is_default' => (bool) $l->is_default,
                ])->values(),
            // Provider names only — no client ids reach the browser; the
            // redirect is built server-side.
            'social_logins' => app(SocialLogin::class)->enabled(),
            'socials' => $socials->values(),
            'pages' => Page::where('is_default', 0)->get(['id', 'name', 'slug'])->values(),
            'policy_pages' => Frontend::where('data_keys', 'policy_pages.element')
                ->get()
                ->map(fn ($p) => ['slug' => $p->slug, 'title' => $p->data_values?->title ?? 'Policy'])
                ->values(),
        ]);
    }

    /**
     * Departments for the home page's "Latest products" tabs.
     *
     * Top-level categories, each with its newest products (counting products
     * filed under any of its subcategories). Ordered so the menu leads with
     * what is moving: departments whose products changed most recently, then
     * those that sell most (delivered orders), then the rest in their admin
     * position — so departments with nothing in them yet still fill the menu.
     */
    private function latestByCategory(\Closure $withCounts, int $limit = 6): array
    {
        $categories = Category::active()->get(['id', 'parent_id', 'name', 'slug', 'icon', 'image', 'position'])->keyBy('id');

        $rootOf = function (int $id) use ($categories): ?int {
            for ($guard = 0; $guard < 10 && isset($categories[$id]); $guard++) {
                $parent = $categories[$id]->parent_id;
                if (! $parent || ! isset($categories[$parent])) {
                    return $id;
                }
                $id = $parent;
            }

            return null;
        };

        $active = Product::active()->select('products.id');
        $updated = Product::active()->pluck('updated_at', 'products.id');

        // Distinct products per department, however many levels they are filed under.
        $productsOf = [];
        foreach (DB::table('category_product')->whereIn('product_id', $active)->get(['product_id', 'category_id']) as $row) {
            if ($root = $rootOf((int) $row->category_id)) {
                $productsOf[$root][$row->product_id] = true;
            }
        }

        $sold = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', Status::ORDER_DELIVERED)
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as qty')
            ->pluck('qty', 'product_id');

        $ranked = $categories->filter(fn (Category $c) => ! $c->parent_id)
            ->map(function (Category $c) use ($productsOf, $updated, $sold) {
                $ids = array_keys($productsOf[$c->id] ?? []);

                return [
                    'category' => $c,
                    'ids' => $ids,
                    'last_activity' => collect($ids)->map(fn ($id) => $updated[$id] ?? null)->filter()->max(),
                    'sold' => array_sum(array_map(fn ($id) => (int) ($sold[$id] ?? 0), $ids)),
                ];
            })
            // Stocked before empty; then newest activity, best sellers, admin position.
            ->sort(fn ($a, $b) => (empty($a['ids']) <=> empty($b['ids']))
                ?: ((string) $b['last_activity'] <=> (string) $a['last_activity'])
                ?: ($b['sold'] <=> $a['sold'])
                ?: ($a['category']->position <=> $b['category']->position))
            ->take($limit)
            ->values();

        return $ranked->map(fn ($row) => [
            'id' => $row['category']->id,
            'name' => $row['category']->name,
            'slug' => $row['category']->slug,
            'image' => fileUrl('category', $row['category']->image),
            'products_count' => count($row['ids']),
            'products' => $row['ids']
                ? ProductCardResource::collection(
                    $withCounts(Product::active()->whereIn('products.id', $row['ids']))->latest('id')->limit(3)->get()
                )->resolve()
                : [],
        ])->all();
    }

    /** Home page: CMS sections plus the product collections they render. */
    public function home()
    {
        $page = Page::where('slug', 'home')->orWhere('is_default', 1)->first();

        // Section keys the home template renders. Content blocks and element
        // lists are resolved through CmsContent so image filenames arrive as
        // absolute URLs.
        $contentKeys = [
            'banner', 'about', 'feature', 'highlight', 'testimonial', 'client', 'cta',
            'video_feature', 'popular_categories', 'brand', 'latest_product', 'top_deals',
            'top_selling_product', 'limited_stock', 'special_offer', 'search', 'blog',
            // The sign-in and register screens read their artwork and copy from here.
            'login', 'register',
        ];

        $sections = CmsContent::sections($contentKeys);

        $withCounts = fn ($query) => $query
            ->with('media', 'brand', 'variations', 'stockUnit', 'categories:id,name,slug')
            ->withAvg(['reviews as reviews_avg_rating' => fn ($q) => $q->approved()], 'rating')
            ->withCount(['reviews as reviews_count' => fn ($q) => $q->approved()]);

        return responseSuccess('home', 'Home page fetched', [
            'sections' => $sections,
            'section_order' => $page?->secs ?? [],
            'seo' => $page?->seo_content,
            'popular_categories' => Category::active()->popular()->orderBy('position')->limit(12)->get()
                ->map(fn ($c) => [
                    'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug,
                    'icon' => fileUrl('category', $c->icon),
                    'image' => fileUrl('category', $c->image),
                ])->values(),
            'top_categories' => Category::active()->top()->orderBy('position')->limit(12)->get()
                ->map(fn ($c) => [
                    'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug,
                    'icon' => fileUrl('category', $c->icon),
                    'image' => fileUrl('category', $c->image),
                ])->values(),
            'popular_brands' => Brand::active()->popular()->orderBy('name')->limit(20)->get()
                ->map(fn ($b) => [
                    'id' => $b->id, 'name' => $b->name, 'slug' => $b->slug,
                    'logo' => fileUrl('brand', $b->logo),
                ])->values(),
            'latest_products' => ProductCardResource::collection(
                $withCounts(Product::active())->latest('id')->limit(10)->get()
            ),
            'latest_by_category' => $this->latestByCategory($withCounts),
            'top_deals' => ProductCardResource::collection(
                $withCounts(Product::active()->deals())->latest('id')->limit(10)->get()
            ),
            'limited_stock' => ProductCardResource::collection(
                $withCounts(Product::active()->limitedStock())->latest('id')->limit(10)->get()
            ),
            'featured_products' => ProductCardResource::collection(
                $withCounts(Product::active()->featured())->latest('id')->limit(10)->get()
            ),
            'top_selling' => ProductCardResource::collection(
                $withCounts(Product::active())
                    ->withSum(['orderItems as total_sold_quantity' => fn ($q) => $q->whereHas('order', fn ($o) => $o->delivered())], 'quantity')
                    ->orderByDesc('total_sold_quantity')
                    ->limit(10)
                    ->get()
            ),
        ]);
    }

    public function branches()
    {
        $branches = Branch::active()->orderByDesc('is_default')->orderBy('name')->get();

        return responseSuccess('branches', 'Branches fetched', [
            'branches' => BranchResource::collection($branches),
        ]);
    }

    public function page(string $slug)
    {
        $page = Page::where('slug', $slug)->firstOrFail();

        // Same resolution as the home page, so image fields arrive as
        // absolute URLs rather than bare filenames.
        $sections = CmsContent::sections($page->secs ?? []);

        return responseSuccess('page', 'Page fetched', [
            'page' => ['name' => $page->name, 'slug' => $page->slug, 'seo' => $page->seo_content],
            'sections' => $sections,
            'section_order' => $page->secs ?? [],
        ]);
    }

    public function policy(string $slug)
    {
        $policy = Frontend::where('data_keys', 'policy_pages.element')->where('slug', $slug)->firstOrFail();

        return responseSuccess('policy', 'Policy page fetched', [
            'policy' => ['slug' => $policy->slug] + (array) $policy->data_values,
            'seo' => $policy->seo_content,
        ]);
    }

    public function blogs()
    {
        $blogs = Frontend::where('data_keys', 'blog.element')->orderByDesc('id')->paginate(getPaginate(9));

        return responseSuccess('blogs', 'Blogs fetched', [
            'blogs' => collect($blogs->items())->map(fn ($b) => [
                'id' => $b->id,
                'slug' => $b->slug,
                'title' => $b->data_values?->title,
                'description' => $b->data_values?->description,
                'image' => fileUrl('frontend', $b->data_values?->image ?? null),
                'created_at' => $b->created_at?->toIso8601String(),
            ])->values(),
            'pagination' => [
                'current_page' => $blogs->currentPage(),
                'last_page' => $blogs->lastPage(),
                'total' => $blogs->total(),
            ],
            'content' => Frontend::where('data_keys', 'blog.content')->first()?->data_values,
        ]);
    }

    public function blog(string $slug)
    {
        $blog = Frontend::where('data_keys', 'blog.element')->where('slug', $slug)->firstOrFail();

        $latest = Frontend::where('data_keys', 'blog.element')
            ->where('id', '!=', $blog->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn ($b) => [
                'slug' => $b->slug,
                'title' => $b->data_values?->title,
                'image' => fileUrl('frontend', $b->data_values?->image ?? null),
                'created_at' => $b->created_at?->toIso8601String(),
            ])->values();

        return responseSuccess('blog', 'Blog fetched', [
            'blog' => [
                'slug' => $blog->slug,
                'title' => $blog->data_values?->title,
                'description' => $blog->data_values?->description,
                'image' => fileUrl('frontend', $blog->data_values?->image ?? null),
                'created_at' => $blog->created_at?->toIso8601String(),
            ],
            'seo' => $blog->seo_content,
            'latest_blogs' => $latest,
        ]);
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:191']]);

        Subscriber::firstOrCreate(['email' => $data['email']]);

        return responseSuccess('subscribed', 'Thank you for subscribing to VIPURI updates');
    }

    /**
     * Translation strings for one language.
     *
     * Keys are the English source text, so anything an administrator has not
     * translated yet simply renders in English rather than breaking.
     */
    public function translations(string $code)
    {
        $language = Language::where('code', $code)->first();

        if (! $language) {
            return responseError('unknown_language', ['That language is not available']);
        }

        return responseSuccess('translations', 'Translations fetched', [
            'code' => $language->code,
            'name' => $language->name,
            'strings' => (object) $language->strings(),
        ]);
    }

    /** A fresh custom-captcha challenge, for the "reload" control on a form. */
    public function captcha(Captcha $captcha)
    {
        return responseSuccess('captcha', 'Captcha issued', [
            'captcha' => $captcha->config(),
        ]);
    }

    /** Contact form — persisted as a support ticket so nothing is lost. */
    public function contact(Request $request, NotificationService $notifications, Captcha $captcha)
    {
        if ($failure = $captcha->verify($request)) {
            return responseError('captcha_failed', [$failure]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $ticket = SupportTicket::create([
            'user_id' => auth('user')->id() ?? 0,
            'name' => $data['name'],
            'email' => $data['email'],
            'ticket' => strtoupper(Str::random(10)),
            'subject' => $data['subject'],
            'status' => \App\Constants\Status::TICKET_OPEN,
            'priority' => \App\Constants\Status::PRIORITY_MEDIUM,
            'last_reply' => now(),
        ]);

        $ticket->messages()->create(['message' => $data['message']]);

        $notifications->toStaff("New contact message: {$data['subject']}", "/admin/tickets/{$ticket->id}");

        return responseSuccess('contact_sent', 'Your message has been sent. We will reply shortly.', [
            'ticket_number' => $ticket->ticket,
        ]);
    }
}
