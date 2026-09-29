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

    public function test_quote_rejects_multiple_mug_bases_and_printing_methods(): void
    {
        $user = $this->owner();
        $product = QuotePreset::query()->where('code', 'product-mug')->firstOrFail();

        $this->actingAs($user)->postJson('/quotes', ['items' => [[
            'preset_code' => $product->code,
            'quantity' => '10',
            'answers' => ['material' => 'ceramic', 'print_method' => 'sublimation'],
            'components' => collect([
                'material-gift-mug-ceramic', 'material-gift-mug-polymer', 'process-sublimation', 'process-gift-printing',
            ])->map(fn (string $code): array => ['code' => $code, 'selected' => true, 'quantity' => '1'])->all(),
        ]]])->assertUnprocessable()->assertJsonValidationErrors('items.0.components');

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_generic_gift_wizard_explains_descriptive_fields_and_cost_basis(): void
    {
        $user = $this->owner();

        $this->actingAs($user)->get('/quotes/create')
            ->assertOk()
            ->assertSee('Material (descritivo)')
            ->assertSee('Personalização (descritiva)')
            ->assertSee('O custo usa a base genérica configurada abaixo; confirme que ela representa este modelo.')
            ->assertSee('O custo usa o processo genérico por peça configurado abaixo.');

        $token = $user->createToken('wizard-help', ['catalog:read'])->plainTextToken;
        $catalogResponse = $this->withToken($token)->getJson('/api/v1/quote-presets')->assertOk();
        $squeeze = collect($catalogResponse->json('categories'))->flatMap(fn (array $category) => $category['items'])->firstWhere('code', 'product-squeeze');
        $this->assertSame('text', data_get($squeeze, 'wizard_schema.fields.1.type'));
        $this->assertSame('Informação da peça para a produção. O custo usa a base genérica configurada abaixo; confirme que ela representa este modelo.', data_get($squeeze, 'wizard_schema.fields.1.help'));
    }

    public function test_workwear_quote_can_reuse_an_existing_embroidery_matrix(): void
    {
        $user = $this->owner();
        $product = QuotePreset::query()->where('code', 'product-workwear')->firstOrFail();

        $this->actingAs($user)->post('/quotes', ['items' => [[
            'preset_code' => $product->code,
            'quantity' => '1',
            'answers' => [
                'garment' => 'lab_coat', 'size_grid' => ['M' => 1], 'personalization' => 'embroidery',
                'logo_width_cm' => '6', 'logo_height_cm' => '4', 'estimated_stitches' => '2500', 'embroidery_matrix' => false,
            ],
            'components' => [
                ['code' => 'material-brim', 'selected' => true, 'quantity' => '1'],
                ['code' => 'process-computerized-embroidery', 'selected' => true, 'quantity' => '1'],
            ],
        ]]])->assertRedirect();

        $this->assertDatabaseCount('quotes', 1);
        $this->assertDatabaseCount('quote_versions', 1);
    }

    public function test_silk_screen_setup_and_per_piece_cost_quantities_follow_color_counts(): void
    {
        $user = $this->owner();
        $product = QuotePreset::query()->where('code', 'product-workwear')->firstOrFail();
        $codes = ['material-brim', 'process-silk-screen', 'material-silk-screen-screen', 'material-silk-screen-film', 'material-silk-screen-ink'];
        $unitCosts = [100, 50, 200, 100, 25];
        foreach ($codes as $index => $code) {
            $component = QuotePreset::query()->where('code', $code)->firstOrFail();
            DB::table('organization_quote_presets')->where('organization_id', $user->organization_id)->where('quote_preset_id', $component->id)->update(['unit_cost_cents' => $unitCosts[$index]]);
        }

        $this->actingAs($user)->post('/quotes', ['items' => [[
            'preset_code' => $product->code,
            'quantity' => '5',
            'answers' => [
                'garment' => 'lab_coat', 'size_grid' => ['P' => 2, 'M' => 3], 'personalization' => 'silk-screen',
                'silk_front_colors' => 2, 'silk_back_colors' => 1,
            ],
            'components' => collect($codes)->map(fn (string $code): array => ['code' => $code, 'selected' => true, ...($code === 'material-brim' ? ['quantity' => '1'] : [])])->all(),
        ]]])->assertRedirect()->assertSessionHasNoErrors();

        $item = \App\Models\QuoteItem::query()->firstOrFail();
        $screen = $item->components()->where('preset_code', 'material-silk-screen-screen')->firstOrFail();
        $film = $item->components()->where('preset_code', 'material-silk-screen-film')->firstOrFail();
        $process = $item->components()->where('preset_code', 'process-silk-screen')->firstOrFail();
        $ink = $item->components()->where('preset_code', 'material-silk-screen-ink')->firstOrFail();

        $this->assertSame(3000, $screen->quantity_milli);
        $this->assertNull($screen->quantity_per_unit_milli);
        $this->assertSame(3000, $film->quantity_milli);
        $this->assertSame('wizard', $film->quantity_source);
        $this->assertSame(15000, $process->quantity_milli);
        $this->assertSame(15000, $ink->quantity_milli);
        $this->assertSame(3000, $ink->quantity_per_unit_milli);
        $this->assertSame(600, $screen->cost_cents);
        $this->assertSame(300, $film->cost_cents);
        $this->assertSame(750, $process->cost_cents);
        $this->assertSame(375, $ink->cost_cents);
    }

    public function test_embroidery_points_and_matrix_setup_are_priced_once_per_quote_line(): void
    {
        $user = $this->owner();
        $product = QuotePreset::query()->where('code', 'product-workwear')->firstOrFail();
        foreach (['process-computerized-embroidery' => 250, 'third-party-embroidery-matrix' => 5000] as $code => $unitCost) {
            $component = QuotePreset::query()->where('code', $code)->firstOrFail();
            DB::table('organization_quote_presets')->where('organization_id', $user->organization_id)->where('quote_preset_id', $component->id)->update(['unit_cost_cents' => $unitCost]);
        }

        $this->actingAs($user)->post('/quotes', ['items' => [[
            'preset_code' => $product->code,
            'quantity' => '4',
            'answers' => [
                'garment' => 'lab_coat', 'size_grid' => ['M' => 4], 'personalization' => 'embroidery',
                'logo_width_cm' => '6', 'logo_height_cm' => '4', 'estimated_stitches' => '2500', 'embroidery_matrix' => true,
            ],
            'components' => collect(['material-brim', 'process-computerized-embroidery', 'third-party-embroidery-matrix'])
                ->map(fn (string $code): array => ['code' => $code, 'selected' => true, 'quantity' => '1'])->all(),
        ]]])->assertRedirect();

        $item = \App\Models\QuoteItem::query()->firstOrFail();
        $process = $item->components()->where('preset_code', 'process-computerized-embroidery')->firstOrFail();
        $matrix = $item->components()->where('preset_code', 'third-party-embroidery-matrix')->firstOrFail();

        $this->assertSame(2500, $process->quantity_per_unit_milli);
        $this->assertSame(10000, $process->quantity_milli);
        $this->assertSame(2500, $process->cost_cents);
        $this->assertNull($matrix->quantity_per_unit_milli);
        $this->assertSame(1000, $matrix->quantity_milli);
        $this->assertSame(5000, $matrix->cost_cents);
        $this->assertSame('wizard', $matrix->quantity_source);
    }

    public function test_tenant_textile_presets_share_exact_silk_and_embroidery_quantity_rules(): void
    {
        $user = $this->owner();
        $silkCases = [
            'uniform-polo' => ['material-piquet', ['size_grid' => ['P' => 2], 'fabric' => 'piquet', 'rib_knit_collar' => false, 'custom_piping' => false, 'individual_bag' => false, 'custom_label' => false]],
            'product-basic-tshirt' => ['material-cotton-menegotti', ['size_grid' => ['P' => 2], 'fabric' => 'cotton']],
            'product-workwear' => ['material-brim', ['size_grid' => ['P' => 2], 'garment' => 'lab_coat']],
            'product-apron' => ['material-brim', ['quantity' => '2', 'fabric' => 'brim']],
        ];
        foreach ($silkCases as $productCode => [$materialCode, $details]) {
            $product = QuotePreset::query()->where('code', $productCode)->firstOrFail();
            $codes = [$materialCode, 'process-silk-screen', 'material-silk-screen-screen', 'material-silk-screen-film', 'material-silk-screen-ink'];
            $this->actingAs($user)->post('/quotes', ['items' => [[
                'preset_code' => $productCode,
                'quantity' => '2',
                'answers' => $details + ['personalization' => 'silk-screen', 'silk_front_colors' => 2, 'silk_back_colors' => 1],
                'components' => collect($codes)->map(fn (string $code): array => ['code' => $code, 'selected' => true, ...($code === $materialCode ? ['quantity' => '1'] : [])])->all(),
            ]]])->assertRedirect()->assertSessionHasNoErrors();

            $quoteId = \App\Models\Quote::query()->latest('id')->value('id');
            $versionId = QuoteVersion::query()->where('quote_id', $quoteId)->value('id');
            $item = \App\Models\QuoteItem::query()->where('quote_version_id', $versionId)->firstOrFail();
            $this->assertSame(3000, $item->components()->where('preset_code', 'material-silk-screen-screen')->value('quantity_milli'), $productCode);
            $this->assertSame(6000, $item->components()->where('preset_code', 'process-silk-screen')->value('quantity_milli'), $productCode);
        }

        $embroideryCases = [
            'uniform-polo' => ['material-piquet', ['size_grid' => ['P' => 2], 'fabric' => 'piquet', 'rib_knit_collar' => false, 'custom_piping' => false, 'individual_bag' => false, 'custom_label' => false]],
            'product-workwear' => ['material-brim', ['size_grid' => ['P' => 2], 'garment' => 'lab_coat']],
            'product-sweatshirt' => ['material-sweatshirt-fabric', ['size_grid' => ['P' => 2]]],
            'product-apron' => ['material-brim', ['quantity' => '2', 'fabric' => 'brim']],
            'product-cap' => ['material-cap-base', ['quantity' => '2', 'cap_model' => 'curved']],
        ];
        foreach ($embroideryCases as $productCode => [$materialCode, $details]) {
            $product = QuotePreset::query()->where('code', $productCode)->firstOrFail();
            $codes = [$materialCode, 'process-computerized-embroidery', 'third-party-embroidery-matrix'];
            $response = $this->post('/quotes', ['items' => [[
                'preset_code' => $productCode,
                'quantity' => '2',
                'answers' => $details + [
                    'personalization' => 'embroidery', 'logo_width_cm' => '6', 'logo_height_cm' => '4',
                    'estimated_stitches' => '2500', 'embroidery_matrix' => true,
                ],
                'components' => collect($codes)->map(fn (string $code): array => ['code' => $code, 'selected' => true, ...($code === $materialCode ? ['quantity' => '1'] : [])])->all(),
            ]]]);
            $response->assertRedirect()->assertSessionHasNoErrors();

            $quoteId = \App\Models\Quote::query()->latest('id')->value('id');
            $versionId = QuoteVersion::query()->where('quote_id', $quoteId)->value('id');
            $item = \App\Models\QuoteItem::query()->where('quote_version_id', $versionId)->firstOrFail();
            $this->assertSame(5000, $item->components()->where('preset_code', 'process-computerized-embroidery')->value('quantity_milli'), $productCode);
            $this->assertSame(1000, $item->components()->where('preset_code', 'third-party-embroidery-matrix')->value('quantity_milli'), $productCode);
        }
    }

    public function test_workwear_api_rejects_omitted_matrix_choice_and_negative_silk_colors(): void
    {
        $user = $this->owner();
        $product = QuotePreset::query()->where('code', 'product-workwear')->firstOrFail();
        $components = collect(['material-brim', 'process-computerized-embroidery'])
            ->map(fn (string $code): array => ['code' => $code, 'selected' => true, 'quantity' => '1'])->all();

        $this->actingAs($user)->postJson('/quotes', ['items' => [[
            'preset_code' => $product->code,
            'quantity' => '1',
            'answers' => [
                'garment' => 'lab_coat', 'size_grid' => ['M' => 1], 'personalization' => 'embroidery',
                'logo_width_cm' => '6', 'logo_height_cm' => '4', 'estimated_stitches' => '2500',
            ],
            'components' => $components,
        ]]])->assertUnprocessable();
        $this->assertDatabaseCount('quotes', 0);

        $this->postJson('/quotes', ['items' => [[
            'preset_code' => $product->code,
            'quantity' => '1',
            'answers' => [
                'garment' => 'lab_coat', 'size_grid' => ['M' => 1], 'personalization' => 'silk-screen',
                'silk_front_colors' => -1, 'silk_back_colors' => 2,
            ],
            'components' => collect(['material-brim', 'process-silk-screen', 'material-silk-screen-screen', 'material-silk-screen-film', 'material-silk-screen-ink'])
                ->map(fn (string $code): array => ['code' => $code, 'selected' => true, 'quantity' => '1'])->all(),
        ]]])->assertUnprocessable();

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_basic_tshirt_silk_api_requires_valid_front_or_back_color_counts(): void
    {
        $user = $this->owner();
        $product = QuotePreset::query()->where('code', 'product-basic-tshirt')->firstOrFail();
        $components = collect(['material-cotton-menegotti', 'process-silk-screen', 'material-silk-screen-screen', 'material-silk-screen-film', 'material-silk-screen-ink'])
            ->map(fn (string $code): array => ['code' => $code, 'selected' => true, 'quantity' => '1'])->all();
        $item = [
            'preset_code' => $product->code,
            'quantity' => '1',
            'answers' => ['size_grid' => ['M' => 1], 'fabric' => 'cotton', 'personalization' => 'silk-screen'],
            'components' => $components,
        ];

        $this->actingAs($user)->postJson('/quotes', ['items' => [$item]])->assertUnprocessable();
        $this->assertDatabaseCount('quotes', 0);

        $item['answers']['silk_front_colors'] = 2;
        $item['answers']['silk_back_colors'] = 0;
        $this->post('/quotes', ['items' => [$item]])->assertRedirect();
        $this->assertDatabaseCount('quotes', 1);
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
        $this->put('/quote-settings', ['items' => [$material->code => ['is_enabled' => '1', 'unit_cost' => '12,50', 'material_width_mm' => '1220', 'material_length_mm' => '2440']]])->assertRedirect();
        $this->put('/quote-settings', ['items' => [$material->code => ['is_enabled' => '0']]])->assertRedirect();
        $this->assertDatabaseHas('organization_quote_presets', ['organization_id' => $first->organization_id, 'quote_preset_id' => $material->id, 'is_enabled' => 0, 'unit_cost_cents' => 1250]);
        $this->assertDatabaseHas('organization_quote_presets', ['organization_id' => $first->organization_id, 'quote_preset_id' => $material->id, 'material_width_mm' => 1220, 'material_length_mm' => 2440]);
        $this->assertDatabaseHas('organization_quote_presets', ['organization_id' => $second->organization_id, 'quote_preset_id' => QuotePreset::where('code', 'material-acm-3mm')->value('id'), 'is_enabled' => 1]);
        $this->assertDatabaseMissing('organization_quote_presets', ['organization_id' => $second->organization_id, 'quote_preset_id' => $material->id]);
        $this->assertDatabaseHas('organization_quote_settings', ['organization_id' => $first->organization_id, 'waste_basis_points' => 500, 'markup_multiplier_basis_points' => 17500]);
        $this->actingAs($first)->get('/quote-settings')->assertOk()->assertSee('material_width_mm')->assertSee('1220');
        $token = $first->createToken('settings-partial', ['catalog:write'])->plainTextToken;
        $this->withToken($token)->patchJson('/api/v1/quote-settings', ['items' => [$material->code => ['material_width_mm' => '1000', 'material_length_mm' => '2000']]])->assertOk();
        $this->assertDatabaseHas('organization_quote_presets', ['organization_id' => $first->organization_id, 'quote_preset_id' => $material->id, 'is_enabled' => 0, 'unit_cost_cents' => 1250, 'material_width_mm' => 1000, 'material_length_mm' => 2000]);
        $this->actingAs($first)->put('/quote-settings', ['items' => [$material->code => ['is_enabled' => '0', 'unit_cost' => '']]])->assertRedirect();
        $this->assertDatabaseHas('organization_quote_presets', ['organization_id' => $first->organization_id, 'quote_preset_id' => $material->id, 'unit_cost_cents' => null]);
    }

    public function test_selected_agenda_and_menu_bindings_are_calculated_per_finished_copy(): void
    {
        $user = $this->owner();
        $lines = [
            [
                'preset_code' => 'product-agenda-notebook',
                'quantity' => '3',
                'answers' => ['quantity' => '3', 'format' => 'A5', 'pages' => '80', 'binding' => 'hardcover'],
                'components' => ['material-offset-90g', 'process-sheet-print', 'finish-binding-hardcover'],
            ],
            [
                'preset_code' => 'product-menu',
                'quantity' => '5',
                'answers' => ['quantity' => '5', 'format' => 'A4', 'pages' => '12', 'laminated' => false, 'binding' => 'wire-o'],
                'components' => ['material-couche-300g', 'process-sheet-print', 'finish-binding-wire-o'],
            ],
        ];
        $items = array_map(fn (array $line): array => [
            'preset_code' => $line['preset_code'],
            'quantity' => $line['quantity'],
            'answers' => $line['answers'],
            'components' => array_map(fn (string $code): array => ['code' => $code, 'selected' => true, 'quantity' => '99'], $line['components']),
        ], $lines);

        $this->actingAs($user)->post('/quotes', ['items' => $items])->assertRedirect();
        $version = QuoteVersion::query()->firstOrFail();
        foreach ([
            ['product-agenda-notebook', 'finish-binding-hardcover', 3_000],
            ['product-menu', 'finish-binding-wire-o', 5_000],
        ] as [$productCode, $bindingCode, $expectedQuantity]) {
            $component = $version->items()->where('preset_code', $productCode)->firstOrFail()
                ->components()->where('preset_code', $bindingCode)->firstOrFail();
            $this->assertSame(1000, $component->quantity_per_unit_milli);
            $this->assertSame($expectedQuantity, $component->quantity_milli);
            $this->assertSame('wizard', $component->quantity_source);
        }

        $invalidAgenda = $items[0];
        $invalidAgenda['components'][] = ['code' => 'finish-binding-spiral', 'selected' => true, 'quantity' => '99'];
        $this->postJson('/quotes', ['items' => [$invalidAgenda]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.components');

        $unboundMenu = $items[1];
        $unboundMenu['answers']['binding'] = 'none';
        $unboundMenu['components'][2]['code'] = 'finish-binding-hardcover';
        $this->postJson('/quotes', ['items' => [$unboundMenu]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.components');
    }
}
