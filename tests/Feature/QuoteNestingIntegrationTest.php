<?php

namespace Tests\Feature;

use App\Actions\RegisterOwner;
use App\Models\OrganizationQuoteSetting;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\QuotePreset;
use App\Models\QuoteVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuoteNestingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $productCode = 'print-business-card', ?string $materialUnitOverride = null): array
    {
        $user = app(RegisterOwner::class)->execute([
            'name' => 'Dono', 'email' => uniqid('nesting-').'@example.test', 'password' => 'secret123', 'organization_name' => 'Gráfica',
        ]);
        $product = QuotePreset::query()->where('code', $productCode)->firstOrFail();
        [$materialCode, $processCode] = match ($productCode) {
            'product-frontlight-banner' => ['material-frontlight-440g', 'process-large-format-print'],
            'product-presentation-folder' => ['material-couche-300g', 'process-sheet-print'],
            'sign-facade' => ['material-acm-3mm', 'process-welding'],
            'product-acrylic-cutout' => ['material-acrylic-cast-3mm', 'process-laser-router-cut'],
            'product-basic-tshirt' => ['material-cotton-menegotti', 'process-silk-screen'],
            default => ['material-cardstock-300g', 'process-sheet-print'],
        };
        $material = QuotePreset::query()->where('code', $materialCode)->firstOrFail();
        $process = QuotePreset::query()->where('code', $processCode)->firstOrFail();
        if ($materialUnitOverride !== null) $material->update(['unit' => $materialUnitOverride]);
        DB::table('organization_quote_presets')->where('organization_id', $user->organization_id)->where('quote_preset_id', $material->id)->update([
            'unit_cost_cents' => 100,
            // Perfil inicial conhecido para os fixtures; cada cenário pode ajustá-lo por tenant.
            'material_width_mm' => 1000,
            'material_length_mm' => 1000,
        ]);
        DB::table('organization_quote_presets')->where('organization_id', $user->organization_id)->where('quote_preset_id', $process->id)->update(['unit_cost_cents' => 50]);
        OrganizationQuoteSetting::query()->updateOrCreate(['organization_id' => $user->organization_id], ['waste_basis_points' => 0, 'markup_multiplier_basis_points' => 10_000]);

        return [$user, $product, $material, $process];
    }

    private function payload(array $fixture, string $lineQuantity, int $nestingQuantity, array $nesting, array $answers): array
    {
        [, $product, $material, $process] = $fixture;
        $answers = match ($product->code) {
            'product-frontlight-banner' => ['media_width_m' => '1', 'material' => 'frontlight-440g', 'finishing' => [], ...$answers],
            'product-presentation-folder' => ['sheet_format' => 'a3', 'stock' => 'couche-300g', 'print_colors' => '4x0', 'pocket' => false, 'pocket_ear' => false, 'die_cut' => false, 'lamination' => false, ...$answers],
            'sign-facade' => ['structure_tube' => '20x20', 'reinforcement' => false, 'anti_rust_paint' => false, 'acm_thickness' => '3mm', 'lighting' => 'none', 'requires_munk' => false, 'requires_scaffold' => false, 'height_installation' => false, 'cnc_outsourced' => false, 'galvanizing_outsourced' => false, ...$answers],
            'product-acrylic-cutout' => ['thickness_mm' => '3', 'plastic_type' => 'acrylic-crystal', 'cut_process' => 'laser', 'thermal_bend' => false, ...$answers],
            'product-basic-tshirt' => ['size_grid' => ['P' => 1, 'M' => 1], 'fabric' => 'cotton', 'personalization' => 'silk-screen', ...$answers],
            default => ['stock' => 'couche-300g', 'print_colors' => '4x0', 'special_die' => false, 'finishes' => [], ...$answers],
        };
        return ['items' => [[
            'preset_code' => $product->code,
            'quantity' => $lineQuantity,
            'answers' => $answers,
            'components' => [
                ['code' => $material->code, 'selected' => true],
                ['code' => $process->code, 'selected' => true, 'quantity' => '1'],
            ],
            'nesting' => ['material_code' => $material->code, 'quantity' => $nestingQuantity, ...$nesting],
        ]]];
    }

    public function test_sheet_consumption_rounds_to_whole_sheets_and_survives_snapshot_and_production_order(): void
    {
        $fixture = $this->fixture();
        [$user, , $material] = $fixture;
        $payload = $this->payload($fixture, '4', 4, [
            'material_type' => 'sheet', 'piece_width_mm' => '600', 'piece_length_mm' => '400',
            'material_width_mm' => '1000', 'material_length_mm' => '1000', 'gap_mm' => '0',
        ], ['width_mm' => '600', 'height_mm' => '400']);

        $this->actingAs($user)->post('/quotes', $payload)->assertRedirect();
        $quote = Quote::query()->firstOrFail();
        $version = QuoteVersion::query()->where('quote_id', $quote->id)->firstOrFail();
        $item = QuoteItem::query()->where('quote_version_id', $version->id)->firstOrFail();
        $component = $item->components()->where('preset_code', $material->code)->firstOrFail();

        $this->assertSame(2_000, $component->quantity_milli); // duas chapas totais; não multiplica outra vez pelas quatro peças.
        $this->assertNull($component->quantity_per_unit_milli);
        $this->assertSame('nesting', $component->quantity_source);
        $this->assertSame('folha', $component->unit);
        $this->assertSame(200, $component->cost_cents); // 2 chapas × 100 centavos por chapa.
        $this->assertSame(400, $item->cost_cents); // inclui 200 centavos do processo para quatro unidades.
        $this->assertSame(400, $version->sale_total_cents); // sem perda e com multiplicador 1× no fixture.
        $this->assertSame(2, $item->nesting['sheets_required']);
        $this->assertSame('operator_confirmed_dimensions_and_rectangular_grid_estimate', $item->nesting['estimation_basis']);
        $this->assertSame(2_000, $version->snapshot['items'][0]['components'][0]['quantity_milli']);

        $this->post('/quotes/'.$quote->id.'/approve')->assertRedirect();
        $order = $quote->productionOrders()->where('sector', 'impressao')->firstOrFail();
        $this->assertSame(2, $order->snapshot['items'][0]['nesting']['sheets_required']);
    }

    public function test_roll_consumption_uses_exact_millimeters_as_milli_meters_and_area_rounds_up(): void
    {
        $fixture = $this->fixture('product-frontlight-banner', 'm');
        [$user, , $material] = $fixture;
        $payload = $this->payload($fixture, '0.5', 4, [
            'material_type' => 'roll', 'piece_width_mm' => 500, 'piece_length_mm' => 250,
            'material_width_mm' => 1000, 'gap_mm' => 0,
        ], ['width_m' => '0.5', 'height_m' => '0.25']);
        $this->actingAs($user)->post('/quotes', $payload)->assertRedirect();
        $item = QuoteItem::query()->firstOrFail();
        $component = $item->components()->where('preset_code', $material->code)->firstOrFail();
        $this->assertSame(500, $component->quantity_milli);
        $this->assertSame('m', $component->unit);
        $this->assertSame(50, $component->cost_cents); // 0,5 m × 100 centavos por metro.
        $this->assertSame(500, $item->nesting['roll_length_mm']);
        $this->assertSame(75, $item->cost_cents); // soma mais 25 centavos de impressão para 0,5 m².

        $areaFixture = $this->fixture('product-frontlight-banner');
        [$areaUser, , $areaMaterial] = $areaFixture;
        // O cenário anterior testa consumo linear em metros; este valida área em m².
        $areaMaterial->update(['unit' => 'm²']);
        DB::table('organization_quote_presets')->where('organization_id', $areaUser->organization_id)->where('quote_preset_id', $areaMaterial->id)->update(['material_width_mm' => 1001]);
        $areaPayload = $this->payload($areaFixture, '0.06', 1, [
            'material_type' => 'roll', 'piece_width_mm' => 300, 'piece_length_mm' => 200,
            'material_width_mm' => 1001, 'gap_mm' => 0,
        ], ['width_m' => '0.3', 'height_m' => '0.2']);
        $this->actingAs($areaUser)->post('/quotes', $areaPayload)->assertRedirect();
        $areaComponent = QuoteItem::query()->latest('id')->firstOrFail()->components()->where('preset_code', $areaMaterial->code)->firstOrFail();
        $this->assertSame(301, $areaComponent->quantity_milli, json_encode(QuoteItem::query()->latest('id')->firstOrFail()->nesting)); // 300,3 milésimos de m² são arredondados para cima.
        $this->assertSame('m²', $areaComponent->unit);
        $this->assertSame(30, $areaComponent->cost_cents); // 0,301 m² × 100 centavos, arredondado em centavos.
        $this->assertSame(33, QuoteItem::query()->latest('id')->firstOrFail()->cost_cents); // inclui 3 centavos de impressão para 0,06 m².
    }

    public function test_fractional_or_mismatched_line_quantity_and_unverifiable_dimensions_are_rejected(): void
    {
        $fixture = $this->fixture();
        [$user] = $fixture;
        $payload = $this->payload($fixture, '4.5', 4, [
            'material_type' => 'sheet', 'piece_width_mm' => 600, 'piece_length_mm' => 400,
            'material_width_mm' => 1000, 'material_length_mm' => 1000,
        ], ['width_mm' => '600', 'height_mm' => '400']);
        $this->actingAs($user)->postJson('/quotes', $payload)->assertUnprocessable();

        $payload = $this->payload($fixture, '4', 4, [
            'material_type' => 'sheet', 'piece_width_mm' => 500, 'piece_length_mm' => 500,
            'material_width_mm' => 1000, 'material_length_mm' => 1000,
        ], ['width_mm' => '600', 'height_mm' => '400']);
        $this->postJson('/quotes', $payload)->assertUnprocessable();

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_area_quantity_is_checked_even_when_the_operator_uses_manual_consumption(): void
    {
        $fixture = $this->fixture('product-frontlight-banner', 'm');
        [$user, $product, $material, $process] = $fixture;
        $payload = ['items' => [[
            'preset_code' => $product->code,
            'quantity' => '0.6', // a peça mede 0,5 × 0,25 m; 0,6 m² não fecha em cópias inteiras.
            'answers' => ['width_m' => '0.5', 'height_m' => '0.25', 'media_width_m' => '1', 'material' => 'frontlight-440g'],
            'components' => [
                ['code' => $material->code, 'selected' => true, 'quantity' => '1'],
                ['code' => $process->code, 'selected' => true, 'quantity' => '1'],
            ],
        ]]];

        $this->actingAs($user)->postJson('/quotes', $payload)->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_incompatible_units_non_fitting_pieces_and_mixed_size_grids_are_rejected(): void
    {
        $incompatible = $this->fixture('print-business-card', 'm');
        [$user] = $incompatible;
        $payload = $this->payload($incompatible, '2', 2, [
            'material_type' => 'sheet', 'piece_width_mm' => 100, 'piece_length_mm' => 100,
            'material_width_mm' => 500, 'material_length_mm' => 500,
        ], ['width_mm' => '100', 'height_mm' => '100']);
        $this->actingAs($user)->postJson('/quotes', $payload)->assertUnprocessable();

        $nonFit = $this->fixture();
        [$nonFitUser] = $nonFit;
        $payload = $this->payload($nonFit, '2', 2, [
            'material_type' => 'sheet', 'piece_width_mm' => 600, 'piece_length_mm' => 800,
            'material_width_mm' => 500, 'material_length_mm' => 500,
        ], ['width_mm' => '600', 'height_mm' => '800']);
        $this->actingAs($nonFitUser)->postJson('/quotes', $payload)->assertUnprocessable();

        $grid = $this->fixture('product-basic-tshirt');
        [$gridUser] = $grid;
        $payload = $this->payload($grid, '2', 2, [
            'material_type' => 'sheet', 'piece_width_mm' => 100, 'piece_length_mm' => 100,
            'material_width_mm' => 500, 'material_length_mm' => 500,
        ], ['size_grid' => ['P' => 1, 'M' => 1], 'fabric' => 'cotton', 'personalization' => 'silk-screen']);
        $this->actingAs($gridUser)->postJson('/quotes', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_api_returns_persisted_nesting_details(): void
    {
        $fixture = $this->fixture();
        [$user] = $fixture;
        $token = $user->createToken('nesting-test', ['quotes:read', 'quotes:write'])->plainTextToken;
        $payload = $this->payload($fixture, '2', 2, [
            'material_type' => 'sheet', 'piece_width_mm' => 100, 'piece_length_mm' => 100,
            'material_width_mm' => 500, 'material_length_mm' => 500,
        ], ['width_mm' => '100', 'height_mm' => '100']);
        DB::table('organization_quote_presets')->where('organization_id', $user->organization_id)->where('quote_preset_id', $fixture[2]->id)->update(['material_width_mm' => 500, 'material_length_mm' => 500]);
        $created = $this->withToken($token)->postJson('/api/v1/quotes', $payload)->assertCreated();
        $this->assertSame($fixture[2]->code, $created->json('data.versions.0.items.0.nesting.material_code'));
        $this->assertSame(1_000, $created->json('data.versions.0.items.0.components.0.quantity_milli'));
        $this->assertSame('nesting', $created->json('data.versions.0.items.0.components.0.quantity_source'));
    }

    public function test_quote_form_uses_registered_material_dimensions_as_read_only_values(): void
    {
        [$user] = $this->fixture();

        $this->actingAs($user)->get('/quotes/create')
            ->assertOk()
            ->assertSee('data-stock-width="1000"', false)
            ->assertSee('data-stock-length="1000"', false)
            ->assertSee('readonly', false)
            ->assertSee('As medidas nominais são configuradas no catálogo da empresa.');
    }

    public function test_acrylic_and_plastic_nesting_uses_the_selected_material_and_cast_thickness(): void
    {
        $fixture = $this->fixture('product-acrylic-cutout');
        [$user] = $fixture;
        $scenarios = [
            ['acrylic-crystal', '2', 'material-acrylic-cast-2mm'],
            ['acrylic-color', '10', 'material-acrylic-cast-10mm'],
            ['ps', '3', 'material-ps-sheet'],
            ['expanded-pvc', '5', 'material-expanded-pvc-sheet'],
            ['polycarbonate', '6', 'material-polycarbonate-sheet'],
        ];

        foreach ($scenarios as [$plasticType, $thickness, $materialCode]) {
            $material = QuotePreset::query()->where('code', $materialCode)->firstOrFail();
            DB::table('organization_quote_presets')->updateOrInsert(
                ['organization_id' => $user->organization_id, 'quote_preset_id' => $material->id],
                ['is_enabled' => true, 'unit_cost_cents' => 100, 'material_width_mm' => 500, 'material_length_mm' => 500, 'created_at' => now(), 'updated_at' => now()],
            );
            $payload = $this->payload($fixture, '1', 1, [
                'material_type' => 'sheet', 'piece_width_mm' => 100, 'piece_length_mm' => 80,
                'material_width_mm' => 500, 'material_length_mm' => 500,
            ], [
                'width_mm' => '100', 'height_mm' => '80', 'thickness_mm' => $thickness,
                'plastic_type' => $plasticType, 'cut_process' => 'laser', 'thermal_bend' => false,
            ]);
            $payload['items'][0]['nesting']['material_code'] = $materialCode;
            $payload['items'][0]['components'][0]['code'] = $materialCode;

            $this->actingAs($user)->post('/quotes', $payload)->assertRedirect();
            $item = QuoteItem::query()->latest('id')->firstOrFail();
            $this->assertSame($materialCode, $item->nesting['material_code']);
            $this->assertSame('nesting', $item->components()->where('preset_code', $materialCode)->value('quantity_source'));
        }

        $invalidThickness = $this->payload($fixture, '1', 1, [
            'material_type' => 'sheet', 'piece_width_mm' => 100, 'piece_length_mm' => 80,
            'material_width_mm' => 500, 'material_length_mm' => 500,
        ], [
            'width_mm' => '100', 'height_mm' => '80', 'thickness_mm' => '10',
            'plastic_type' => 'acrylic-crystal', 'cut_process' => 'laser', 'thermal_bend' => false,
        ]);
        $invalidThickness['items'][0]['nesting']['material_code'] = 'material-acrylic-cast-2mm';
        $invalidThickness['items'][0]['components'][0]['code'] = 'material-acrylic-cast-2mm';
        $this->actingAs($user)->postJson('/quotes', $invalidThickness)->assertUnprocessable();
    }

    public function test_selected_stock_must_match_the_product_wizard_and_manual_components_are_labeled(): void
    {
        $fixture = $this->fixture();
        [$user, , $material] = $fixture;
        $payload = $this->payload($fixture, '4', 4, [
            'material_type' => 'sheet', 'piece_width_mm' => 600, 'piece_length_mm' => 400,
            'material_width_mm' => 1000, 'material_length_mm' => 1000,
        ], ['width_mm' => '600', 'height_mm' => '400']);
        $wrongStock = QuotePreset::query()->where('code', 'material-cardstock-250g')->firstOrFail();
        $payload['items'][0]['nesting']['material_code'] = $wrongStock->code;
        $payload['items'][0]['components'][0]['code'] = $wrongStock->code;
        $this->actingAs($user)->postJson('/quotes', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('quotes', 0);

        $payload = $this->payload($fixture, '4', 4, [
            'material_type' => 'sheet', 'piece_width_mm' => 600, 'piece_length_mm' => 400,
            'material_width_mm' => 1000, 'material_length_mm' => 1000,
        ], ['width_mm' => '600', 'height_mm' => '400']);
        unset($payload['items'][0]['nesting']);
        $payload['items'][0]['components'][0]['quantity'] = '1';
        $this->actingAs($user)->post('/quotes', $payload)->assertRedirect();
        $manual = QuoteItem::query()->latest('id')->firstOrFail()->components()->where('preset_code', $material->code)->firstOrFail();
        $this->assertSame('manual', $manual->quantity_source);
        $this->assertNull(QuoteItem::query()->latest('id')->firstOrFail()->nesting);
    }

    public function test_area_quantity_is_checked_without_nesting_and_unsupported_products_cannot_use_integrated_nesting(): void
    {
        $fixture = $this->fixture('product-frontlight-banner');
        [$user] = $fixture;
        $payload = $this->payload($fixture, '0.13', 1, [
            'material_type' => 'roll', 'piece_width_mm' => 500, 'piece_length_mm' => 250,
            'material_width_mm' => 1000,
        ], ['width_m' => '0.5', 'height_m' => '0.25']);
        unset($payload['items'][0]['nesting']);
        $payload['items'][0]['components'][0]['quantity'] = '1';
        $this->actingAs($user)->postJson('/quotes', $payload)->assertUnprocessable();

        $unsupported = $this->fixture('product-basic-tshirt');
        [$unsupportedUser] = $unsupported;
        $payload = $this->payload($unsupported, '2', 2, [
            'material_type' => 'sheet', 'piece_width_mm' => 100, 'piece_length_mm' => 100,
            'material_width_mm' => 500, 'material_length_mm' => 500,
        ], ['size_grid' => ['P' => 1, 'M' => 1], 'fabric' => 'cotton', 'personalization' => 'silk-screen']);
        $this->actingAs($unsupportedUser)->postJson('/quotes', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_printed_card_and_folder_answers_require_matching_stock_and_selected_finishes(): void
    {
        $card = $this->fixture('print-business-card');
        [$cardUser] = $card;
        $wrongStock = $this->payload($card, '4', 4, [
            'material_type' => 'sheet', 'piece_width_mm' => 90, 'piece_length_mm' => 50,
            'material_width_mm' => 1000, 'material_length_mm' => 1000,
        ], ['width_mm' => '90', 'height_mm' => '50', 'stock' => 'couche-250g']);
        $this->actingAs($cardUser)->postJson('/quotes', $wrongStock)->assertUnprocessable()->assertJsonValidationErrors('items.0.components');

        $conflictingLamination = $this->payload($card, '4', 4, [
            'material_type' => 'sheet', 'piece_width_mm' => 90, 'piece_length_mm' => 50,
            'material_width_mm' => 1000, 'material_length_mm' => 1000,
        ], ['width_mm' => '90', 'height_mm' => '50', 'finishes' => ['matte', 'soft-touch']]);
        unset($conflictingLamination['items'][0]['nesting']);
        $this->postJson('/quotes', $conflictingLamination)->assertUnprocessable()->assertJsonValidationErrors('answers.finishes');

        $missingFinishes = $this->payload($card, '4', 4, [
            'material_type' => 'sheet', 'piece_width_mm' => 90, 'piece_length_mm' => 50,
            'material_width_mm' => 1000, 'material_length_mm' => 1000,
        ], ['width_mm' => '90', 'height_mm' => '50', 'finishes' => ['matte', 'uv-varnish'], 'special_die' => '1']);
        unset($missingFinishes['items'][0]['nesting']);
        $this->postJson('/quotes', $missingFinishes)->assertUnprocessable()->assertJsonValidationErrors('items.0.components');

        $folder = $this->fixture('product-presentation-folder');
        [$folderUser] = $folder;
        $wrongFolderStock = $this->payload($folder, '4', 4, [
            'material_type' => 'sheet', 'piece_width_mm' => 400, 'piece_length_mm' => 300,
            'material_width_mm' => 1000, 'material_length_mm' => 1000,
        ], ['quantity' => '4', 'open_width_mm' => '400', 'open_height_mm' => '300', 'closed_width_mm' => '200', 'closed_height_mm' => '150', 'stock' => 'offset-90g']);
        unset($wrongFolderStock['items'][0]['nesting']);
        $this->actingAs($folderUser)->postJson('/quotes', $wrongFolderStock)->assertUnprocessable()->assertJsonValidationErrors('items.0.components');

        $invalidPocket = $this->payload($folder, '4', 4, [
            'material_type' => 'sheet', 'piece_width_mm' => 400, 'piece_length_mm' => 300,
            'material_width_mm' => 1000, 'material_length_mm' => 1000,
        ], ['quantity' => '4', 'open_width_mm' => '400', 'open_height_mm' => '300', 'closed_width_mm' => '200', 'closed_height_mm' => '150', 'pocket' => '0', 'pocket_ear' => '1']);
        unset($invalidPocket['items'][0]['nesting']);
        $this->postJson('/quotes', $invalidPocket)->assertUnprocessable()->assertJsonValidationErrors('answers.pocket_ear');
    }

    public function test_checked_facade_third_party_option_cannot_be_omitted_from_the_cost_sheet(): void
    {
        $fixture = $this->fixture('sign-facade');
        [$user, $product, $acm, $welding] = $fixture;
        $tube = QuotePreset::query()->where('code', 'material-metal-tube-20x20')->firstOrFail();
        $payload = ['items' => [[
            'preset_code' => $product->code,
            'quantity' => '0.125',
            'answers' => [
                'width_m' => '0.5', 'height_m' => '0.25', 'structure_tube' => '20x20',
                'reinforcement' => '0', 'anti_rust_paint' => '0', 'acm_thickness' => '3mm',
                'lighting' => 'none', 'requires_munk' => '1', 'requires_scaffold' => '0',
                'height_installation' => '0', 'cnc_outsourced' => '0', 'galvanizing_outsourced' => '0',
            ],
            'components' => [
                ['code' => $acm->code, 'selected' => true, 'quantity' => '1'],
                ['code' => $tube->code, 'selected' => true, 'quantity' => '1'],
                ['code' => $welding->code, 'selected' => true, 'quantity' => '1'],
            ],
        ]]];

        $this->actingAs($user)->postJson('/quotes', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.components');
    }
}
