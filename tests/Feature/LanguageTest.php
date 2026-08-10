<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-language support — the `languages` feature and `/change/{lang}` switch
 * from the source system, adapted to a separate frontend.
 */
class LanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    public function test_configured_languages_are_published_with_the_default_first(): void
    {
        $languages = $this->getJson('/api/v1/settings')->assertOk()->json('data.languages');

        $this->assertNotEmpty($languages);
        $this->assertTrue($languages[0]['is_default'], 'The default language should come first');
        $this->assertContains('sw', array_column($languages, 'code'));
    }

    public function test_translations_are_served_for_a_configured_language(): void
    {
        $strings = $this->getJson('/api/v1/translations/sw')->assertOk()->json('data.strings');

        $this->assertSame('Kikapu', $strings['Cart']);
        $this->assertSame('Mwanzo', $strings['Home']);
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->getJson('/api/v1/translations/xx')
            ->assertStatus(422)
            ->assertJsonPath('remark', 'unknown_language');
    }

    public function test_blank_translations_are_dropped_so_the_source_string_shows(): void
    {
        $language = Language::where('code', 'sw')->firstOrFail();
        $language->translations = ['Cart' => 'Kikapu', 'Checkout' => '   ', 'Total' => null];
        $language->save();

        $strings = $this->getJson('/api/v1/translations/sw')->json('data.strings');

        $this->assertSame('Kikapu', $strings['Cart']);
        $this->assertArrayNotHasKey('Checkout', $strings);
        $this->assertArrayNotHasKey('Total', $strings);
    }

    public function test_staff_can_edit_translations(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $language = Language::where('code', 'sw')->firstOrFail();

        $this->withHeaders($this->adminHeaders($admin))
            ->postJson("/api/v1/admin/languages/{$language->id}/strings", [
                'strings' => ['Cart' => 'Kikapu Kipya', 'Home' => ''],
            ])
            ->assertOk();

        $strings = $this->getJson('/api/v1/translations/sw')->json('data.strings');

        $this->assertSame('Kikapu Kipya', $strings['Cart']);
        $this->assertArrayNotHasKey('Home', $strings, 'A cleared string should fall back to English');
    }

    public function test_the_editor_offers_every_known_key_for_a_new_language(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->withHeaders($this->adminHeaders($admin))
            ->postJson('/api/v1/admin/languages', ['name' => 'Français', 'code' => 'fr'])
            ->assertOk();

        $french = Language::where('code', 'fr')->firstOrFail();

        $payload = $this->withHeaders($this->adminHeaders($admin))
            ->getJson("/api/v1/admin/languages/{$french->id}/strings")
            ->assertOk();

        // Empty of its own strings, but pre-loaded with the whole catalogue.
        $this->assertSame([], (array) $payload->json('data.strings'));
        $this->assertContains('Cart', $payload->json('data.keys'));
        $this->assertFalse($payload->json('data.is_source'));
    }

    public function test_the_default_language_cannot_be_deleted(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $english = Language::where('code', 'en')->firstOrFail();

        $this->withHeaders($this->adminHeaders($admin))
            ->deleteJson("/api/v1/admin/languages/{$english->id}")
            ->assertStatus(422)
            ->assertJsonPath('remark', 'default_language');
    }

    public function test_a_branch_worker_cannot_edit_translations(): void
    {
        $worker = $this->makeStaff(Roles::BRANCH_WORKER);
        $language = Language::where('code', 'sw')->firstOrFail();

        $this->withHeaders($this->adminHeaders($worker))
            ->postJson("/api/v1/admin/languages/{$language->id}/strings", ['strings' => ['Cart' => 'X']])
            ->assertForbidden();
    }
}
