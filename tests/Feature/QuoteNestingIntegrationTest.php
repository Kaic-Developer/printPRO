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
            'product-printed-adhesive' => ['material-vinyl-monomeric', 'process-large-format-print'],
            'product-presentation-folder' => ['material-couche-300g', 'process-sheet-print'],
            'sign-facade' => ['material-acm-3mm', 'process-welding'],
            'product-acrylic-cutout' => ['material-acrylic-cast-3mm', 'process-laser-router-cut'],
            'product-basic-tshirt' => ['material-cotton-menegotti', 'process-silk-screen'],
            'product-labels-roll-sheet' => ['material-label-roll-stock', 'process-label-printing'],
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
            'product-printed-adhesive' => ['width_m' => '0.5', 'height_m' => '0.25', 'material' => 'monomeric', 'lamination' => 'none', 'cut_type' => 'straight', ...$answers],
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
        $this->assertSame(201, $areaComponent->quantity_milli, json_encode(QuoteItem::query()->latest('id')->firstOrFail()->nesting)); // A orientação original consome 200,2 milésimos e arredonda para cima.
        $this->assertSame('m²', $areaComponent->unit);
        $this->assertSame(20, $areaComponent->cost_cents); // 0,201 m² × 100 centavos, arredondado em centavos.
        $this->assertSame(23, QuoteItem::query()->latest('id')->firstOrFail()->cost_cents); // inclui 3 centavos de impressão para 0,06 m².
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

    public function test_adhesive_quote_form_renders_nesting_for_enabled_configured_vinyl(): void
    {
        [$user] = $this->fixture('product-printed-adhesive');

        $this->actingAs($user)->get('/quotes/create')
            ->assertOk()
            ->assertSee('data-preset-panel="product-printed-adhesive"', false)
            ->assertSee('data-nesting-editor', false)
            ->assertSee('value="material-vinyl-monomeric"', false);
    }

    public function test_basic_tshirt_wizard_exposes_standard_and_custom_print_dimensions_and_calculates_dtf_and_dtg_area(): void
    {
        $fixture = $this->fixture('product-basic-tshirt');
        [$user] = $fixture;
        $this->actingAs($user)->get('/quotes/create')
            ->assertOk()
            ->assertSee('data-wizard-field="print_size"', false)
            ->assertSee('value="custom_area"', false)
            ->assertSee('data-wizard-managed-label', false);

        $dtf = $this->payload($fixture, '3', 3, [], [
            'size_grid' => ['P' => 2, 'M' => 1], 'fabric' => 'polyester', 'personalization' => 'dtf',
            'print_size' => 'a4', 'prints_per_piece' => '2', 'print_width_mm' => '210', 'print_height_mm' => '297',
        ]);
        unset($dtf['items'][0]['nesting']);
        $dtf['items'][0]['components'] = [
            ['code' => 'material-polyester', 'selected' => true, 'quantity' => '0.45'],
            ['code' => 'process-dtf-print-size', 'selected' => true, 'quantity' => '99'],
            ['code' => 'material-textile-dtf-transfer', 'selected' => true, 'quantity' => '99'],
        ];
        $this->actingAs($user)->post('/quotes', $dtf)->assertRedirect();
        $dtfItem = QuoteItem::query()->latest('id')->firstOrFail();
        foreach (['process-dtf-print-size', 'material-textile-dtf-transfer'] as $code) {
            $component = $dtfItem->components()->where('preset_code', $code)->firstOrFail();
            $this->assertSame(126, $component->quantity_per_unit_milli); // A4 arredondado para cima por posição, duas posições por camiseta.
            $this->assertSame(378, $component->quantity_milli); // três camisetas na grade.
            $this->assertSame('wizard', $component->quantity_source);
        }

        $dtg = $this->payload($fixture, '1', 1, [], [
            'size_grid' => ['P' => 1], 'fabric' => 'dryfit', 'personalization' => 'dtg',
            'print_size' => 'custom_area', 'prints_per_piece' => '2', 'print_width_mm' => '350', 'print_height_mm' => '200',
        ]);
        unset($dtg['items'][0]['nesting']);
        $dtg['items'][0]['components'] = [
            ['code' => 'material-dryfit', 'selected' => true, 'quantity' => '0.4'],
            ['code' => 'process-dtg-print-size', 'selected' => true, 'quantity' => '99'],
            ['code' => 'material-textile-dtg-ink', 'selected' => true, 'quantity' => '0.01'],
        ];
        $this->actingAs($user)->post('/quotes', $dtg)->assertRedirect();
        $dtgItem = QuoteItem::query()->latest('id')->firstOrFail();
        $dtgProcess = $dtgItem->components()->where('preset_code', 'process-dtg-print-size')->firstOrFail();
        $this->assertSame(140, $dtgProcess->quantity_per_unit_milli); // 350 × 200 mm, duas estampas por peça.
        $this->assertSame('wizard', $dtgProcess->quantity_source);
        $ink = $dtgItem->components()->where('preset_code', 'material-textile-dtg-ink')->firstOrFail();
        $this->assertSame('manual', $ink->quantity_source); // O consumo de tinta varia e não é deduzido da área.
        $this->assertSame(10, $ink->quantity_per_unit_milli);
    }

    public function test_dtf_area_is_calculated_for_polo_sweatshirt_apron_and_generic_applications(): void
    {
        $garments = [
            ['uniform-polo', ['size_grid' => ['P' => 1, 'M' => 2], 'fabric' => 'piquet', 'personalization' => 'dtf', 'print_size' => 'custom_area', 'prints_per_piece' => '2', 'print_width_cm' => '25', 'print_height_cm' => '30', 'rib_knit_collar' => false, 'custom_piping' => false, 'individual_bag' => false, 'custom_label' => false], ['material-piquet', 'process-dtf-print-size', 'material-textile-dtf-transfer'], 'process-dtf-print-size'],
            ['product-sweatshirt', ['size_grid' => ['P' => 1, 'M' => 2], 'personalization' => 'dtf', 'print_size' => 'custom_area', 'prints_per_piece' => '2', 'print_width_cm' => '25', 'print_height_cm' => '30'], ['material-sweatshirt-fabric', 'process-dtf', 'material-textile-dtf-transfer'], 'process-dtf'],
            ['product-apron', ['quantity' => '3', 'fabric' => 'brim', 'personalization' => 'dtf', 'print_size' => 'custom_area', 'prints_per_piece' => '2', 'print_width_cm' => '25', 'print_height_cm' => '30'], ['material-brim', 'process-dtf', 'material-textile-dtf-transfer'], 'process-dtf'],
        ];

        foreach ($garments as [$presetCode, $answers, $codes, $processCode]) {
            [$user] = $this->fixture($presetCode);
            $components = array_map(fn (string $code) => ['code' => $code, 'selected' => true, 'quantity' => '0.01'], $codes);
            $this->actingAs($user)->post('/quotes', ['items' => [[
                'preset_code' => $presetCode,
                'quantity' => '3',
                'answers' => $answers,
                'components' => $components,
            ]]])->assertRedirect();

            $item = QuoteItem::query()->latest('id')->firstOrFail();
            $process = $item->components()->where('preset_code', $processCode)->firstOrFail();
            $this->assertSame(150, $process->quantity_per_unit_milli); // 25 x 30 cm x duas posiÃ§Ãµes.
            $this->assertSame(450, $process->quantity_milli); // TrÃªs peÃ§as pela grade ou quantidade.
            $this->assertSame('wizard', $process->quantity_source);
        }
    }

    public function test_generic_dtf_dtg_application_uses_matching_area_process_and_keeps_ink_manual(): void
    {
        [$user] = $this->fixture('product-dtf-dtg-print');
        $this->actingAs($user)->post('/quotes', ['items' => [[
            'preset_code' => 'product-dtf-dtg-print',
            'quantity' => '1',
            'answers' => ['technique' => 'dtf', 'quantity' => '1', 'print_size' => 'custom_area', 'garment_supplied_by_customer' => true],
            'components' => [],
        ]]])->assertSessionHasErrors('custom_width_cm');

        $scenarios = [
            ['dtf', 'custom_area', ['custom_width_cm' => '20', 'custom_height_cm' => '20'], ['process-dtf-print-size', 'material-textile-dtf-transfer'], 'process-dtf-print-size', 40],
            ['dtg', 'a4', [], ['process-dtg-print-size', 'material-textile-dtg-ink'], 'process-dtg-print-size', 63],
        ];

        foreach ($scenarios as [$technique, $size, $dimensions, $codes, $calculatedCode, $expectedPerApplication]) {
            $this->actingAs($user)->post('/quotes', ['items' => [[
                'preset_code' => 'product-dtf-dtg-print',
                'quantity' => '2',
                'answers' => ['technique' => $technique, 'quantity' => '2', 'print_size' => $size, 'garment_supplied_by_customer' => true, ...$dimensions],
                'components' => array_map(fn (string $code) => ['code' => $code, 'selected' => true, 'quantity' => '0.01'], $codes),
            ]]])->assertRedirect();

            $item = QuoteItem::query()->latest('id')->firstOrFail();
            $calculated = $item->components()->where('preset_code', $calculatedCode)->firstOrFail();
            $this->assertSame($expectedPerApplication, $calculated->quantity_per_unit_milli);
            $this->assertSame($expectedPerApplication * 2, $calculated->quantity_milli);
            $this->assertSame('wizard', $calculated->quantity_source);
            if ($technique === 'dtg') {
                $ink = $item->components()->where('preset_code', 'material-textile-dtg-ink')->firstOrFail();
                $this->assertSame('manual', $ink->quantity_source);
            }
        }
    }

    public function test_label_nesting_links_roll_or_sheet_stock_to_the_selected_format_and_cost_consumption(): void
    {
        $fixture = $this->fixture('product-labels-roll-sheet');
        [$user] = $fixture;
        $sheetMaterial = QuotePreset::query()->where('code', 'material-label-sheet-stock')->firstOrFail();
        DB::table('organization_quote_presets')->updateOrInsert(
            ['organization_id' => $user->organization_id, 'quote_preset_id' => $sheetMaterial->id],
            ['is_enabled' => true, 'unit_cost_cents' => 200, 'material_width_mm' => 500, 'material_length_mm' => 700, 'created_at' => now(), 'updated_at' => now()],
        );

        $scenarios = [
            ['roll', 'material-label-roll-stock', 'roll', 1000, null, 30, 'm²'],
            ['sheet', 'material-label-sheet-stock', 'sheet', 500, 700, 1000, 'folha'],
        ];
        foreach ($scenarios as [$format, $materialCode, $materialType, $materialWidth, $materialLength, $expectedConsumption, $expectedUnit]) {
            $material = $this->enableMaterial($user, $materialCode);
            DB::table('organization_quote_presets')->where('organization_id', $user->organization_id)->where('quote_preset_id', $material->id)->update([
                'material_width_mm' => $materialWidth,
                'material_length_mm' => $materialLength,
            ]);
            $nesting = [
                'material_code' => $materialCode,
                'material_type' => $materialType,
                'quantity' => 20,
                'piece_width_mm' => 50,
                'piece_length_mm' => 30,
                'material_width_mm' => $materialWidth,
                'gap_mm' => 0,
            ];
            if ($materialType === 'sheet') $nesting['material_length_mm'] = $materialLength;

            $this->actingAs($user)->post('/quotes', ['items' => [[
                'preset_code' => 'product-labels-roll-sheet',
                'quantity' => '20',
                'answers' => ['quantity' => '20', 'format' => $format, 'width_mm' => '50', 'height_mm' => '30'],
                'components' => [
                    ['code' => $materialCode, 'selected' => true, 'quantity' => '99'],
                    ['code' => 'process-label-printing', 'selected' => true, 'quantity' => '1'],
                    ['code' => 'process-label-die-cut', 'selected' => true, 'quantity' => '1'],
                ],
                'nesting' => $nesting,
            ]]])->assertRedirect();

            $item = QuoteItem::query()->latest('id')->firstOrFail();
            $component = $item->components()->where('preset_code', $materialCode)->firstOrFail();
            $this->assertSame($expectedConsumption, $component->quantity_milli);
            $this->assertSame($expectedUnit, $component->unit);
            $this->assertSame('nesting', $component->quantity_source);
            $this->assertSame($materialCode, $item->nesting['material_code']);
        }

        foreach ([
            ['roll', 'material-label-roll-stock', 'sheet', 1000],
            ['sheet', 'material-label-sheet-stock', 'roll', 500],
        ] as [$format, $materialCode, $materialType, $materialWidth]) {
            $nesting = [
                'material_code' => $materialCode,
                'material_type' => $materialType,
                'quantity' => 1,
                'piece_width_mm' => 50,
                'piece_length_mm' => 30,
                'material_width_mm' => $materialWidth,
                'gap_mm' => 0,
            ];
            if ($materialType === 'sheet') $nesting['material_length_mm'] = 1000;
            $this->actingAs($user)->post('/quotes', ['items' => [[
                'preset_code' => 'product-labels-roll-sheet',
                'quantity' => '1',
                'answers' => ['quantity' => '1', 'format' => $format, 'width_mm' => '50', 'height_mm' => '30'],
                'components' => [
                    ['code' => $materialCode, 'selected' => true, 'quantity' => '1'],
                    ['code' => 'process-label-printing', 'selected' => true, 'quantity' => '1'],
                    ['code' => 'process-label-die-cut', 'selected' => true, 'quantity' => '1'],
                ],
                'nesting' => $nesting,
            ]]])->assertSessionHasErrors('items.0.nesting.material_type');
        }

        $this->actingAs($user)->get('/quotes/create')
            ->assertOk()
            ->assertSee('data-preset-panel="product-labels-roll-sheet"', false)
            ->assertSee('value="material-label-roll-stock"', false)
            ->assertSee('value="material-label-sheet-stock"', false);
    }

    private function enableMaterial(User $user, string $materialCode): QuotePreset
    {
        $material = QuotePreset::query()->where('code', $materialCode)->firstOrFail();
        DB::table('organization_quote_presets')->updateOrInsert(
            ['organization_id' => $user->organization_id, 'quote_preset_id' => $material->id],
            ['is_enabled' => true, 'unit_cost_cents' => 100, 'material_width_mm' => 1000, 'material_length_mm' => 1000, 'created_at' => now(), 'updated_at' => now()],
        );

        return $material;
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

    public function test_printed_adhesive_requires_selected_media_finishing_and_application_and_can_use_roll_nesting(): void
    {
        $fixture = $this->fixture('product-printed-adhesive');
        [$user, $product, $vinyl, $printing] = $fixture;
        $application = QuotePreset::query()->where('code', 'process-adhesive-application')->firstOrFail();
        $lamination = QuotePreset::query()->where('code', 'finish-vinyl-lamination')->firstOrFail();
        $laminationFilm = QuotePreset::query()->where('code', 'material-vinyl-matte-lamination')->firstOrFail();
        $plotter = QuotePreset::query()->where('code', 'process-plotter-cut')->firstOrFail();
        $base = $this->payload($fixture, '0.5', 4, [
            'material_type' => 'roll', 'piece_width_mm' => 500, 'piece_length_mm' => 250,
            'material_width_mm' => 1000, 'gap_mm' => 0,
        ], ['lamination' => 'matte', 'cut_type' => 'plotter']);

        // Cada opção marcada no wizard deve possuir a respectiva linha de custo.
        $base['items'][0]['components'] = array_merge($base['items'][0]['components'], [
            ['code' => $application->code, 'selected' => true, 'quantity' => '1'],
            ['code' => $lamination->code, 'selected' => true, 'quantity' => '1'],
            ['code' => $laminationFilm->code, 'selected' => true, 'quantity' => '1'],
            ['code' => $plotter->code, 'selected' => true, 'quantity' => '1'],
        ]);
        $omittedApplication = $base;
        $omittedApplication['items'][0]['components'] = array_values(array_filter(
            $omittedApplication['items'][0]['components'], fn (array $component): bool => $component['code'] !== $application->code,
        ));
        $this->actingAs($user)->postJson('/quotes', $omittedApplication)->assertUnprocessable()->assertJsonValidationErrors('items.0.components');

        foreach ([$lamination->code, $laminationFilm->code, $plotter->code] as $requiredOption) {
            $omission = $base;
            $omission['items'][0]['components'] = array_values(array_filter(
                $omission['items'][0]['components'], fn (array $component): bool => $component['code'] !== $requiredOption,
            ));
            $this->postJson('/quotes', $omission)->assertUnprocessable()->assertJsonValidationErrors('items.0.components');
        }

        $this->actingAs($user)->post('/quotes', $base)->assertRedirect();
        $item = QuoteItem::query()->firstOrFail();
        $consumedVinyl = $item->components()->where('preset_code', $vinyl->code)->firstOrFail();
        $this->assertSame('nesting', $consumedVinyl->quantity_source);
        $this->assertSame(500, $consumedVinyl->quantity_milli); // quatro impressões de 0,125 m² usam 0,5 m² de bobina.
        $this->assertSame('material-vinyl-monomeric', $item->nesting['material_code']);
        $this->assertSame(500, $item->nesting['roll_length_mm']);
        $this->assertSame('process-adhesive-application', $item->components()->where('preset_code', $application->code)->value('preset_code'));
        $this->assertSame('material-vinyl-matte-lamination', $item->components()->where('preset_code', $laminationFilm->code)->value('preset_code'));
        $this->assertSame('process-large-format-print', $item->components()->where('preset_code', $printing->code)->value('preset_code'));
    }
}
