<?php

namespace App\Http\Controllers\Api\Admin;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Models\Company;
use App\Models\Extension;
use App\Models\Frontend;
use App\Models\GeneralSetting;
use App\Models\Language;
use App\Models\NotificationTemplate;
use App\Models\Page;
use App\Services\AuditService;
use App\Services\SocialLogin;
use App\Services\FileManager;
use App\Traits\ScopesToBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * System settings, company profile, CMS content, pages, languages,
 * notification templates, extensions and the admin notification centre.
 */
class SettingController extends Controller
{
    use ScopesToBranch;

    public function __construct(
        private readonly FileManager $files,
        private readonly AuditService $audit,
    ) {}

    /* ------------------------------- General --------------------------- */

    public function general()
    {
        $settings = GeneralSetting::current();
        $logo = Frontend::where('data_keys', 'logo_icon.data')->first();

        return responseSuccess('general_setting', 'Settings fetched', [
            'settings' => $settings,
            'logo' => fileUrl('logoIcon', $logo?->data_values?->logo ?? null),
            'logo_dark' => fileUrl('logoIcon', $logo?->data_values?->logo_dark ?? null),
            'favicon' => fileUrl('logoIcon', $logo?->data_values?->favicon ?? null),
        ]);
    }

    public function updateGeneral(Request $request)
    {
        $settings = GeneralSetting::current();
        $before = $settings->getAttributes();

        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:120'],
            'cur_text' => ['required', 'string', 'max:20'],
            'cur_sym' => ['required', 'string', 'max:20'],
            'currency_format' => ['nullable', 'integer', Rule::in([Status::CUR_BOTH, Status::CUR_TEXT, Status::CUR_SYM])],
            'base_color' => ['nullable', 'string', 'max:20'],
            'secondary_color' => ['nullable', 'string', 'max:20'],
            'paginate_number' => ['nullable', 'integer', 'min:1', 'max:100'],
            'order_number_prefix' => ['nullable', 'string', 'max:20'],
            'registration' => ['nullable', 'boolean'],
            'ev' => ['nullable', 'boolean'],
            'sv' => ['nullable', 'boolean'],
            'en' => ['nullable', 'boolean'],
            'sn' => ['nullable', 'boolean'],
            'pn' => ['nullable', 'boolean'],
            'force_ssl' => ['nullable', 'boolean'],
            'secure_password' => ['nullable', 'boolean'],
            'agree' => ['nullable', 'boolean'],
            'multi_language' => ['nullable', 'boolean'],
            'has_cod' => ['nullable', 'boolean'],
            'in_app_payment' => ['nullable', 'boolean'],

            /*
             * Worker commission. The rate is a percentage of the order
             * subtotal and defaults to 0, so the scheme records nothing until
             * somebody in the business sets a figure. Capped at 100 because a
             * rate above that pays out more than the goods were sold for.
             */
            'commission_enabled' => ['nullable', 'boolean'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'commission_attribution' => ['nullable', 'string', Rule::in(['delivered_by', 'processed_by'])],
        ]);

        $settings->fill($data)->save();
        Cache::forget('GeneralSetting');

        $this->audit->logUpdate('setting.general_updated', $settings, $before, 'General settings updated');

        return responseSuccess('general_updated', 'Settings updated', ['settings' => $settings->fresh()]);
    }

    public function updateLogo(Request $request)
    {
        $request->validate([
            'logo' => ['nullable', 'image', 'max:5120'],
            'logo_dark' => ['nullable', 'image', 'max:5120'],
            'favicon' => ['nullable', 'image', 'max:2048'],
        ]);

        $row = Frontend::firstOrCreate(['data_keys' => 'logo_icon.data'], ['data_values' => []]);
        $values = (array) ($row->data_values ?? []);

        foreach (['logo', 'logo_dark', 'favicon'] as $field) {
            if ($request->hasFile($field)) {
                $values[$field] = $this->files->uploadImage($request->file($field), 'logoIcon', $values[$field] ?? null);
            }
        }

        $row->data_values = $values;
        $row->save();

        return responseSuccess('logo_updated', 'Branding updated', [
            'logo' => fileUrl('logoIcon', $values['logo'] ?? null),
            'logo_dark' => fileUrl('logoIcon', $values['logo_dark'] ?? null),
            'favicon' => fileUrl('logoIcon', $values['favicon'] ?? null),
        ]);
    }

    public function maintenanceMode(Request $request)
    {
        $data = $request->validate([
            'maintenance_mode' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $settings = GeneralSetting::current();
        $settings->maintenance_mode = $data['maintenance_mode'];
        $settings->save();
        Cache::forget('GeneralSetting');

        if (array_key_exists('description', $data)) {
            $row = Frontend::firstOrCreate(['data_keys' => 'maintenance.data'], ['data_values' => []]);
            $row->data_values = ['description' => $data['description']];
            $row->save();
        }

        $this->audit->log('setting.maintenance_mode', description: 'Maintenance mode ' . ($data['maintenance_mode'] ? 'enabled' : 'disabled'));

        return responseSuccess('maintenance_updated', 'Maintenance mode updated');
    }

    /* ------------------------------- Company --------------------------- */

    public function company()
    {
        $company = Company::current();

        return responseSuccess('company', 'Company profile fetched', [
            'company' => $company,
            'logo' => fileUrl('logoIcon', $company->logo),
        ]);
    }

    public function updateCompany(Request $request)
    {
        $company = Company::current();
        $before = $company->getAttributes();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:60'],
            'tin' => ['nullable', 'string', 'max:60'],
            'vrn' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:191'],
            'region' => ['nullable', 'string', 'max:191'],
            'currency_text' => ['nullable', 'string', 'max:10'],
            'currency_symbol' => ['nullable', 'string', 'max:10'],
            'logo' => ['nullable', 'image', 'max:5120'],
        ]);

        if ($request->hasFile('logo')) {
            $data['logo'] = $this->files->uploadImage($request->file('logo'), 'logoIcon', $company->logo);
        }

        $company->fill($data)->save();

        $this->audit->logUpdate('setting.company_updated', $company, $before, 'Company profile updated');

        return responseSuccess('company_updated', 'Company profile updated', ['company' => $company->fresh()]);
    }

    /* ---------------------------------- AI ----------------------------- */

    public function aiSettings()
    {
        $settings = GeneralSetting::current();

        return responseSuccess('ai_settings', 'AI settings fetched', [
            'default_engine' => (int) $settings->default_engine,
            'openai_api_model' => $settings->openai_api_model,
            'ai_review_summary' => (bool) $settings->ai_review_summary,
            'ai_product_chat' => (bool) $settings->ai_product_chat,
            // Keys are never echoed back; only whether one is present.
            'openai_key_set' => (bool) ($settings->openai_api_key ?: config('vipuri.ai.openai.key')),
            'gemini_key_set' => (bool) ($settings->gemini_api_key ?: config('vipuri.ai.gemini.key')),
            'engines' => [
                ['value' => Status::OPENAI_MODEL, 'label' => 'OpenAI'],
                ['value' => Status::GEMINI_MODEL, 'label' => 'Google Gemini'],
            ],
        ]);
    }

    public function updateAiSettings(Request $request)
    {
        $data = $request->validate([
            'default_engine' => ['required', 'integer', Rule::in([Status::OPENAI_MODEL, Status::GEMINI_MODEL])],
            'openai_api_key' => ['nullable', 'string', 'max:255'],
            'openai_api_model' => ['nullable', 'string', 'max:80'],
            'gemini_api_key' => ['nullable', 'string', 'max:255'],
            'ai_review_summary' => ['nullable', 'boolean'],
            'ai_product_chat' => ['nullable', 'boolean'],
        ]);

        $settings = GeneralSetting::current();
        $settings->default_engine = $data['default_engine'];
        $settings->openai_api_model = $data['openai_api_model'] ?? $settings->openai_api_model;
        $settings->ai_review_summary = $data['ai_review_summary'] ?? $settings->ai_review_summary;
        $settings->ai_product_chat = $data['ai_product_chat'] ?? $settings->ai_product_chat;

        // Empty means "leave the stored key alone" so the form never wipes it.
        if (! empty($data['openai_api_key'])) {
            $settings->openai_api_key = $data['openai_api_key'];
        }

        if (! empty($data['gemini_api_key'])) {
            $settings->gemini_api_key = $data['gemini_api_key'];
        }

        $settings->save();
        Cache::forget('GeneralSetting');

        $this->audit->log('setting.ai_updated', description: 'AI settings updated');

        return responseSuccess('ai_settings_updated', 'AI settings updated');
    }

    /* --------------------------- Social login -------------------------- */

    public function socialLogins()
    {
        $stored = GeneralSetting::current()->socialite_credentials;

        return responseSuccess('social_logins', 'Social login settings fetched', [
            'providers' => collect(SocialLogin::PROVIDERS)->map(function (string $provider) use ($stored) {
                $config = $stored?->$provider ?? null;

                return [
                    'provider' => $provider,
                    'label' => $provider === 'linkedin' ? 'LinkedIn' : ucfirst($provider),
                    'status' => (bool) ($config->status ?? false),
                    // Secrets are never echoed back; the form shows only
                    // whether one is on file.
                    'client_id' => (string) ($config->client_id ?? ''),
                    'secret_set' => trim((string) ($config->client_secret ?? '')) !== '',
                    'callback_url' => url("/social-login/{$provider}/callback"),
                ];
            })->values(),
            'enabled' => app(SocialLogin::class)->enabled(),
        ]);
    }

    public function updateSocialLogin(Request $request, string $provider)
    {
        if (! in_array($provider, SocialLogin::PROVIDERS, true)) {
            return responseError('unknown_provider', ['Unknown sign-in provider']);
        }

        $data = $request->validate([
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'boolean'],
        ]);

        $settings = GeneralSetting::current();
        $credentials = json_decode(json_encode($settings->socialite_credentials ?? []), true) ?: [];
        $current = $credentials[$provider] ?? [];

        $clientId = $data['client_id'] ?? ($current['client_id'] ?? '');
        // Empty means "leave the stored secret alone" so saving the form with
        // the field blank never wipes a working configuration.
        $clientSecret = ! empty($data['client_secret'])
            ? $data['client_secret']
            : ($current['client_secret'] ?? '');

        if ($data['status'] && (trim((string) $clientId) === '' || trim((string) $clientSecret) === '')) {
            return responseError('incomplete_credentials', [
                'Add both a client ID and a client secret before enabling ' . ucfirst($provider) . '.',
            ]);
        }

        $credentials[$provider] = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'status' => (bool) $data['status'],
        ];

        $settings->socialite_credentials = $credentials;
        $settings->save();
        GeneralSetting::flush();

        $this->audit->log(
            'setting.social_login_updated',
            newValues: ['provider' => $provider, 'status' => (bool) $data['status']],
            description: ucfirst($provider) . ' sign-in ' . ($data['status'] ? 'enabled' : 'disabled'),
        );

        return responseSuccess('social_login_updated', ucfirst($provider) . ' sign-in updated');
    }

    /* --------------------------- Notifications ------------------------- */

    public function notificationTemplates()
    {
        return responseSuccess('notification_templates', 'Templates fetched', [
            'templates' => NotificationTemplate::orderBy('name')->get(),
            'global' => [
                'email_template' => gs('email_template'),
                'sms_template' => gs('sms_template'),
                'push_template' => gs('push_template'),
                'push_title' => gs('push_title'),
                'email_from' => gs('email_from'),
                'email_from_name' => gs('email_from_name'),
                'sms_from' => gs('sms_from'),
            ],
        ]);
    }

    public function saveNotificationTemplate(Request $request, int $id)
    {
        $template = NotificationTemplate::findOrFail($id);

        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'email_body' => ['nullable', 'string'],
            'sms_body' => ['nullable', 'string'],
            'push_title' => ['nullable', 'string', 'max:255'],
            'push_body' => ['nullable', 'string'],
            'email_status' => ['nullable', 'boolean'],
            'sms_status' => ['nullable', 'boolean'],
            'push_status' => ['nullable', 'boolean'],
        ]);

        $template->fill($data)->save();

        return responseSuccess('template_saved', 'Template saved', ['template' => $template->fresh()]);
    }

    public function updateGlobalTemplates(Request $request)
    {
        $data = $request->validate([
            'email_template' => ['nullable', 'string'],
            'sms_template' => ['nullable', 'string', 'max:1000'],
            'push_template' => ['nullable', 'string', 'max:1000'],
            'push_title' => ['nullable', 'string', 'max:255'],
            'email_from' => ['nullable', 'email', 'max:191'],
            'email_from_name' => ['nullable', 'string', 'max:191'],
            'sms_from' => ['nullable', 'string', 'max:191'],
        ]);

        $settings = GeneralSetting::current();
        $settings->fill($data)->save();
        Cache::forget('GeneralSetting');

        return responseSuccess('global_templates_updated', 'Global templates updated');
    }

    /** Admin notification centre. */
    public function notifications(Request $request)
    {
        $query = AdminNotification::query()->latest('id');
        $branchIds = $this->visibleBranchIds();

        // Super admins see everything, including branch-less alerts.
        if ($branchIds !== null) {
            $query->whereIn('branch_id', $branchIds ?: [0]);
        }

        $notifications = $query->paginate(getPaginate(20));

        return responseSuccess('notifications', 'Notifications fetched', [
            'notifications' => $notifications->items(),
            'unread' => (clone $query)->where('is_read', 0)->count(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    public function readNotification(int $id)
    {
        $notification = AdminNotification::findOrFail($id);
        $this->authorizeNotification($notification);
        $notification->update(['is_read' => 1]);

        return responseSuccess('notification_read', 'Notification marked as read');
    }

    public function readAllNotifications()
    {
        $query = AdminNotification::query();
        $branchIds = $this->visibleBranchIds();

        if ($branchIds !== null) {
            $query->whereIn('branch_id', $branchIds ?: [0]);
        }

        $query->update(['is_read' => 1]);

        return responseSuccess('notifications_read', 'All notifications marked as read');
    }

    public function deleteNotification(int $id)
    {
        $notification = AdminNotification::findOrFail($id);
        $this->authorizeNotification($notification);
        $notification->delete();

        return responseSuccess('notification_deleted', 'Notification deleted');
    }

    /* ------------------------------ CMS content ------------------------ */

    public function frontendSections(?string $key = null)
    {
        if ($key) {
            return responseSuccess('frontend_section', 'Section fetched', [
                'content' => Frontend::where('data_keys', "$key.content")->first(),
                'elements' => Frontend::where('data_keys', "$key.element")->get(),
            ]);
        }

        return responseSuccess('frontend_sections', 'Sections fetched', [
            'sections' => Frontend::orderBy('data_keys')->get(['id', 'data_keys', 'slug', 'tempname']),
            'keys' => Frontend::distinct()->orderBy('data_keys')->pluck('data_keys'),
        ]);
    }

    public function saveFrontendContent(Request $request, string $key)
    {
        $data = $request->validate([
            'values' => ['required', 'array'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'max:8192'],
        ]);

        $row = Frontend::firstOrCreate(['data_keys' => "$key.content"], ['data_values' => []]);
        $values = array_merge((array) ($row->data_values ?? []), $data['values']);

        foreach ($request->file('images', []) as $field => $file) {
            $values[$field] = $this->files->uploadImage($file, 'frontend', $values[$field] ?? null);
        }

        // The model casts data_values to an object, so it encodes on save;
        // encoding here as well stored a JSON string inside JSON.
        $row->data_values = $values;
        $row->save();

        $this->audit->log('frontend.content_updated', $row, description: "Section $key content updated");

        return responseSuccess('content_saved', 'Content saved', ['content' => $row->fresh()]);
    }

    public function saveFrontendElement(Request $request, string $key, ?int $id = null)
    {
        $data = $request->validate([
            'values' => ['required', 'array'],
            'slug' => ['nullable', 'string', 'max:191'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'max:8192'],
        ]);

        $row = $id
            ? Frontend::where('data_keys', "$key.element")->findOrFail($id)
            : new Frontend(['data_keys' => "$key.element", 'data_values' => []]);

        $values = array_merge((array) ($row->data_values ?? []), $data['values']);

        foreach ($request->file('images', []) as $field => $file) {
            $values[$field] = $this->files->uploadImage($file, 'frontend', $values[$field] ?? null);
        }

        // The model casts data_values to an object, so it encodes on save;
        // encoding here as well stored a JSON string inside JSON.
        $row->data_values = $values;

        if (! empty($data['slug'])) {
            $row->slug = Str::slug($data['slug']);
        } elseif (! $row->slug && ! empty($values['title'])) {
            $row->slug = Str::slug($values['title']) . '-' . Str::random(4);
        }

        $row->save();

        return responseSuccess('element_saved', 'Item saved', ['element' => $row->fresh()]);
    }

    public function deleteFrontendElement(int $id)
    {
        $row = Frontend::findOrFail($id);

        if (! Str::endsWith($row->data_keys, '.element')) {
            return responseError('not_deletable', ['Only list items can be deleted']);
        }

        $row->delete();

        return responseSuccess('element_deleted', 'Item deleted');
    }

    public function saveSeo(Request $request, int $id)
    {
        $data = $request->validate([
            'seo_content' => ['required', 'array'],
            'image' => ['nullable', 'image', 'max:8192'],
        ]);

        $row = Frontend::findOrFail($id);
        $seo = $data['seo_content'];

        if ($request->hasFile('image')) {
            $seo['image'] = $this->files->uploadImage($request->file('image'), 'seo', $row->seo_content?->image ?? null);
        }

        $row->seo_content = $seo;
        $row->save();

        return responseSuccess('seo_saved', 'SEO content saved');
    }

    /* --------------------------------- Pages --------------------------- */

    public function pages()
    {
        return responseSuccess('pages', 'Pages fetched', ['pages' => Page::orderBy('id')->get()]);
    }

    public function savePage(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120'],
            'secs' => ['nullable', 'array'],
            'secs.*' => ['string', 'max:80'],
        ]);

        $page = $id ? Page::findOrFail($id) : new Page();

        $page->name = $data['name'];
        $page->slug = $page->is_default ? $page->slug : Str::slug($data['slug'] ?? $data['name']);
        $page->secs = $data['secs'] ?? $page->secs ?? [];
        $page->save();

        return responseSuccess('page_saved', 'Page saved', ['page' => $page->fresh()]);
    }

    public function deletePage(int $id)
    {
        $page = Page::findOrFail($id);

        if ($page->is_default) {
            return responseError('default_page', ['A default page cannot be deleted']);
        }

        $page->delete();

        return responseSuccess('page_deleted', 'Page deleted');
    }

    /* ------------------------------- Languages ------------------------- */

    public function languages()
    {
        return responseSuccess('languages', 'Languages fetched', ['languages' => Language::orderBy('name')->get()]);
    }

    public function saveLanguage(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:20'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $language = $id ? Language::findOrFail($id) : new Language();
        $language->fill($data)->save();

        if ($language->is_default) {
            Language::where('id', '!=', $language->id)->update(['is_default' => 0]);
        }

        return responseSuccess('language_saved', 'Language saved', ['language' => $language->fresh()]);
    }

    /** The full key/value map for one language, for the translation editor. */
    public function languageStrings(int $id)
    {
        $language = Language::findOrFail($id);

        // Every key any language already knows about, so a newly added language
        // starts from the full list instead of an empty box.
        $catalogue = Language::pluck('translations')
            ->filter()
            ->flatMap(fn ($map) => array_keys((array) $map))
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return responseSuccess('language_strings', 'Translations fetched', [
            'language' => ['id' => $language->id, 'name' => $language->name, 'code' => $language->code],
            'strings' => (object) ($language->translations ?? []),
            'keys' => $catalogue,
            // The source language needs no map: its keys are the strings.
            'is_source' => (bool) $language->is_default,
        ]);
    }

    /**
     * Rewrite the translation map for one language.
     *
     * Keys are the English source strings. An empty value is dropped rather
     * than stored, so the storefront falls back to English for it.
     */
    public function saveLanguageStrings(Request $request, int $id)
    {
        $data = $request->validate([
            'strings' => ['present', 'array'],
            'strings.*' => ['nullable', 'string', 'max:2000'],
        ]);

        $language = Language::findOrFail($id);

        $language->translations = collect($data['strings'])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => trim($value))
            ->all();

        $language->save();

        $this->audit->log(
            'setting.translations_updated',
            $language,
            newValues: ['code' => $language->code, 'count' => count($language->translations)],
            description: "Translations updated for {$language->name}",
        );

        return responseSuccess('language_strings_saved', "Translations saved for {$language->name}");
    }

    public function deleteLanguage(int $id)
    {
        $language = Language::findOrFail($id);

        if ($language->is_default) {
            return responseError('default_language', ['The default language cannot be deleted']);
        }

        $language->delete();

        return responseSuccess('language_deleted', 'Language deleted');
    }

    /* ------------------------------ Extensions ------------------------- */

    /** Field names whose stored value is a secret and is never sent back. */
    private const SECRET_FIELDS = ['secret_key', 'random_key', 'api_secret', 'access_token'];

    public function extensions()
    {
        $extensions = Extension::orderBy('name')->get()->map(function (Extension $extension) {
            $fields = collect((array) $extension->shortcode)->map(function ($field, $name) {
                $isSecret = in_array($name, self::SECRET_FIELDS, true);

                return [
                    'name' => $name,
                    'title' => $field->title ?? keyToTitle($name),
                    'value' => $isSecret ? '' : (string) ($field->value ?? ''),
                    'is_secret' => $isSecret,
                    'is_set' => trim((string) ($field->value ?? '')) !== '',
                ];
            })->values();

            return [
                'id' => $extension->id,
                'act' => $extension->act,
                'name' => $extension->name,
                'description' => $extension->description,
                'status' => (bool) $extension->status,
                'fields' => $fields,
            ];
        });

        return responseSuccess('extensions', 'Extensions fetched', ['extensions' => $extensions]);
    }

    public function saveExtension(Request $request, int $id)
    {
        $data = $request->validate([
            'shortcode' => ['nullable', 'array'],
            'shortcode.*' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'boolean'],
        ]);

        $extension = Extension::findOrFail($id);
        $shortcode = json_decode(json_encode($extension->shortcode ?? []), true) ?: [];

        foreach ($data['shortcode'] ?? [] as $name => $value) {
            if (! array_key_exists($name, $shortcode)) {
                continue;
            }

            // A blank secret means "keep what is stored", so saving the form
            // without retyping the secret never wipes a working setup.
            if (in_array($name, self::SECRET_FIELDS, true) && trim((string) $value) === '') {
                continue;
            }

            $shortcode[$name]['value'] = (string) $value;
        }

        if (array_key_exists('status', $data)) {
            // Refuse to advertise a challenge or widget that cannot work.
            if ($data['status']) {
                foreach ($shortcode as $name => $field) {
                    if (trim((string) ($field['value'] ?? '')) === '') {
                        return responseError('incomplete_extension', [
                            'Fill in every field before enabling ' . $extension->name . '.',
                        ]);
                    }
                }
            }

            $extension->status = $data['status'];
        }

        $extension->shortcode = $shortcode;
        $extension->save();

        $this->audit->log(
            'setting.extension_updated',
            $extension,
            newValues: ['act' => $extension->act, 'status' => (bool) $extension->status],
            description: $extension->name . ' ' . ($extension->status ? 'enabled' : 'disabled'),
        );

        return responseSuccess('extension_saved', $extension->name . ' saved');
    }

    /* -------------------------------- System --------------------------- */

    public function systemInfo()
    {
        return responseSuccess('system_info', 'System information fetched', [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'database' => config('database.default'),
            'timezone' => config('app.timezone'),
            'environment' => config('app.env'),
            'debug' => config('app.debug'),
            'storage_linked' => is_link(public_path('storage')),
            'currency' => currencyText(),
            'frontend_url' => config('vipuri.frontend_url'),
        ]);
    }

    public function clearCache()
    {
        Cache::flush();
        \Illuminate\Support\Facades\Artisan::call('optimize:clear');

        $this->audit->log('system.cache_cleared', description: 'Application cache cleared');

        return responseSuccess('cache_cleared', 'Cache cleared');
    }

    private function authorizeNotification(AdminNotification $notification): void
    {
        if ($this->admin()->isSuperAdmin()) {
            return;
        }

        if ((int) $notification->branch_id !== (int) $this->admin()->branch_id) {
            abort(403, 'This notification belongs to another branch');
        }
    }
}
