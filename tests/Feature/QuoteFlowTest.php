<?php

namespace Tests\Feature;

use App\Actions\RegisterOwner;
use App\Models\OrganizationQuoteSetting;
use App\Models\QuotePreset;
use App\Models\QuoteVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuoteFlowTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $email = 'owner@example.test'): User
    {
        return app(RegisterOwner::class)->execute(['name' => 'Proprietário', 'email' => $email, 'password' => 'secret123', 'organization_name' => 'Gráfica']);
    }

    private function fixture(User $user, bool $withCosts = true): array
    {
        $parent = QuotePreset::query()->where('code', 'visual-communication')->firstOrFail();
        $material = QuotePreset::create(['parent_id' => $parent->id, 'code' => 'test-material', 'kind' => 'material', 'name' => 'Material de teste', 'unit' => 'm²', 'production_sector' => 'serralheria', 'suggested_components' => []]);
        $process = QuotePreset::create(['parent_id' => $parent->id, 'code' => 'test-process', 'kind' => 'process', 'name' => 'Processo de teste', 'unit' => 'hora', 'production_sector' => 'impressao', 'suggested_components' => []]);
        $product = QuotePreset::create(['parent_id' => $parent->id, 'code' => 'test-product', 'kind' => 'product', 'name' => 'Produto de teste', 'unit' => 'unidade', 'production_sector' => 'costura', 'suggested_components' => [$material->code, $process->code], 'wizard_schema' => ['version' => 1, 'fields' => [['key' => 'quantity', 'label' => 'Quantidade', 'type' => 'integer', 'required' => true, 'min' => 1]]]]);

        foreach ([$material, $process, $product] as $index => $preset) {
            DB::table('organization_quote_presets')->insert(['organization_id' => $user->organization_id, 'quote_preset_id' => $preset->id, 'is_enabled' => true, 'unit_cost_cents' => $withCosts ? ($index === 0 ? 125 : ($index === 1 ? 75 : null)) : null, 'created_at' => now(), 'updated_at' => now()]);
        }
        OrganizationQuoteSetting::query()->updateOrCreate(['organization_id' => $user->organization_id], ['waste_basis_points' => 1000, 'markup_multiplier_basis_points' => 20000]);

        return [$product, $material, $process];
    }

    private function payload(array $presets, string $quantity = '3'): array
    {
        [$product, $material, $process] = $presets;
        return ['items' => [[
            'preset_code' => $product->code,
            'quantity' => $quantity,
            'answers' => [],
            'components' => [
                ['code' => $material->code, 'selected' => '1', 'quantity' => '1,5'],
                ['code' => $process->code, 'selected' => '1', 'quantity' => '1'],
            ],
        ]]];
    }

    public function test_quote_is_versioned_priced_and_generates_idempotent_sector_orders(): void
    {
        $user = $this->owner();
        $presets = $this->fixture($user);
        $this->actingAs($user)->post('/quotes', $this->payload($presets))->assertRedirect();
        $quote = \App\Models\Quote::query()->firstOrFail();
        $versionOne = QuoteVersion::query()->where('quote_id', $quote->id)->firstOrFail();
        $this->assertSame(788, $versionOne->cost_total_cents);
        $this->assertSame(1734, $versionOne->sale_total_cents);
        $this->assertTrue($versionOne->is_calculable);
        $materialComponent = $versionOne->items()->firstOrFail()->components()->where('preset_code', 'test-material')->firstOrFail();
        $this->assertSame(1500, $materialComponent->quantity_per_unit_milli);
        $this->assertSame(4500, $materialComponent->quantity_milli);

        $this->post('/quotes/'.$quote->id.'/versions', $this->payload($presets, '4'))->assertRedirect();
        $this->assertSame(2, $quote->fresh()->current_version);
        $this->assertSame(3_000, (int) $versionOne->items()->firstOrFail()->quantity_milli);
        $this->assertDatabaseCount('quote_versions', 2);

        $this->post('/quotes/'.$quote->id.'/approve')->assertRedirect();
        $this->post('/quotes/'.$quote->id.'/approve')->assertRedirect();
        $this->assertSame('approved', $quote->fresh()->status);
        $this->assertDatabaseCount('production_orders', 3);

        $this->postJson('/quotes/'.$quote->id.'/versions', $this->payload($presets, '5'))->assertUnprocessable();
        $this->assertDatabaseCount('production_orders', 3);
    }

    public function test_missing_component_cost_blocks_approval_and_tenant_cannot_read_another_quote(): void
    {
        $first = $this->owner();
        $second = $this->owner('second@example.test');
        $presets = $this->fixture($first, false);
        $this->actingAs($first)->post('/quotes', $this->payload($presets))->assertRedirect();
        $quote = \App\Models\Quote::query()->where('organization_id', $first->organization_id)->firstOrFail();
        $this->assertFalse($quote->versions()->firstOrFail()->is_calculable);
        $this->postJson('/quotes/'.$quote->id.'/approve')->assertUnprocessable();
        $this->actingAs($second)->get('/quotes/'.$quote->id)->assertNotFound();
    }

    public function test_catalog_sync_preserves_global_availability_and_expired_quotes_cannot_be_approved(): void
    {
        $user = $this->owner();
        $presets = $this->fixture($user);
        [$product] = $presets;
        $product->update(['is_available' => false]);
        app(\App\Actions\InitializeQuoteCatalog::class)->execute();
        $this->assertFalse($product->fresh()->is_available);

        $product->update(['is_available' => true]);
        $this->actingAs($user)->post('/quotes', $this->payload($presets))->assertRedirect();
        $quote = \App\Models\Quote::query()->firstOrFail();
        $quote->update(['expires_at' => today()->subDay()]);
        $this->postJson('/quotes/'.$quote->id.'/approve')->assertUnprocessable();
        $this->assertDatabaseCount('production_orders', 0);
    }

    public function test_wizard_choice_requires_its_matching_polo_material_and_processes(): void
    {
        $user = $this->owner();
        $product = QuotePreset::query()->where('code', 'uniform-polo')->firstOrFail();
        $components = collect(['material-piquet', 'process-silk-screen', 'material-silk-screen-screen', 'material-silk-screen-film', 'material-silk-screen-ink'])
            ->map(fn (string $code): array => ['code' => $code, 'selected' => true, 'quantity' => '1']);
        $response = $this->actingAs($user)->post('/quotes', ['items' => [[
            'preset_code' => $product->code,
            'answers' => ['size_grid' => ['P' => 1], 'fabric' => 'dryfit', 'personalization' => 'silk-screen', 'silk_front_colors' => 2, 'silk_back_colors' => 0, 'rib_knit_collar' => false, 'custom_piping' => false, 'individual_bag' => false, 'custom_label' => false],
            'components' => $components->all(),
        ]]]);
        $response->assertSessionHasErrors('items.0.components');
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_sanctum_api_issues_scoped_token_and_rejects_guests(): void
    {
        $user = $this->owner();
        $this->getJson('/api/v1/quotes')->assertUnauthorized();
        $response = $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'secret123', 'device_name' => 'android'])
            ->assertCreated()->assertJsonPath('token_type', 'Bearer');
        $token = $response->json('token');
        $this->withToken($token)->getJson('/api/v1/quote-settings')->assertOk();
        $presets = $this->fixture($user);
        $created = $this->withToken($token)->postJson('/api/v1/quotes', $this->payload($presets))->assertCreated();
        $quoteId = $created->json('data.id');
        $this->withToken($token)->postJson('/api/v1/quotes/'.$quoteId.'/versions', $this->payload($presets) + ['expected_version' => 1])->assertCreated()->assertJsonPath('data.version_number', 2);
        $this->withToken($token)->postJson('/api/v1/quotes/'.$quoteId.'/versions', $this->payload($presets) + ['expected_version' => 1])->assertUnprocessable();
        $this->withToken($token)->deleteJson('/api/v1/auth/token')->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/quotes')->assertUnauthorized();
    }

    public function test_settings_are_isolated_and_blank_prices_remain_unknown(): void
    {
        $first = $this->owner();
        $second = $this->owner('second@example.test');
        [$product, $material] = $this->fixture($first);
        $this->actingAs($first)->put('/quote-settings', ['waste_percentage' => '5,00', 'markup_multiplier' => '1,75'])->assertRedirect();
        $this->put('/quote-settings', ['items' => [$material->code => ['is_enabled' => '0', 'unit_cost' => '']]])->assertRedirect();
        $this->assertDatabaseHas('organization_quote_presets', ['organization_id' => $first->organization_id, 'quote_preset_id' => $material->id, 'is_enabled' => 0, 'unit_cost_cents' => null]);
        $this->assertDatabaseHas('organization_quote_presets', ['organization_id' => $second->organization_id, 'quote_preset_id' => QuotePreset::where('code', 'material-acm-3mm')->value('id'), 'is_enabled' => 1]);
        $this->assertDatabaseHas('organization_quote_settings', ['organization_id' => $first->organization_id, 'waste_basis_points' => 500, 'markup_multiplier_basis_points' => 17500]);
    }
}
