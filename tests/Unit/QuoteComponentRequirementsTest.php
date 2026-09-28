<?php

namespace Tests\Unit;

use App\Models\QuotePreset;
use App\Services\QuoteComponentRequirements;
use Tests\TestCase;

class QuoteComponentRequirementsTest extends TestCase
{
    public function test_basic_tshirt_requires_selected_fabric_and_all_supplies_for_its_printing_technique(): void
    {
        $requirements = new QuoteComponentRequirements;
        $preset = new QuotePreset(['code' => 'product-basic-tshirt']);
        $scenarios = [
            ['cotton', 'silk-screen', ['material-cotton-menegotti', 'process-silk-screen', 'material-silk-screen-screen', 'material-silk-screen-film', 'material-silk-screen-ink']],
            ['polyester', 'dtf', ['material-polyester', 'process-dtf-print-size', 'material-textile-dtf-transfer']],
            ['dryfit', 'dtg', ['material-dryfit', 'process-dtg-print-size', 'material-textile-dtg-ink']],
            ['polyester', 'sublimation', ['material-polyester', 'process-sublimation', 'material-sublimation-paper', 'material-sublimation-ink']],
            ['cotton', 'textile-vinyl', ['material-cotton-menegotti', 'process-textile-vinyl', 'material-textile-vinyl']],
        ];

        foreach ($scenarios as [$fabric, $personalization, $expected]) {
            $answers = ['fabric' => $fabric, 'personalization' => $personalization];
            $this->assertEqualsCanonicalizing($expected, $requirements->missing($preset, $answers, []));
            $this->assertSame([], $requirements->missing($preset, $answers, $expected));
        }
    }

    public function test_printed_items_require_the_selected_stock_and_production_steps(): void
    {
        $requirements = new QuoteComponentRequirements;
        $scenarios = [
            ['product-flyer', ['stock' => 'offset-90g'], ['material-offset-90g', 'process-sheet-print', 'process-cutting']],
            ['product-folder-print', ['stock' => 'couche-250g', 'folds' => '3'], ['material-cardstock-250g', 'process-sheet-print', 'process-folding']],
            ['product-envelopes', ['stock' => 'offset-90g'], ['material-offset-90g', 'process-sheet-print', 'process-die-cut-crease']],
            ['product-letterhead', ['stock' => 'offset-90g'], ['material-offset-90g', 'process-sheet-print']],
        ];

        foreach ($scenarios as [$code, $answers, $expected]) {
            $preset = new QuotePreset(['code' => $code]);
            $this->assertEqualsCanonicalizing($expected, $requirements->missing($preset, $answers, []));
            $this->assertSame([], $requirements->missing($preset, $answers, $expected));
        }
    }

    public function test_optional_print_finishes_and_selected_bindings_are_priced_components(): void
    {
        $requirements = new QuoteComponentRequirements;

        $banner = new QuotePreset(['code' => 'product-banner']);
        $this->assertEqualsCanonicalizing(
            ['material-frontlight-440g', 'process-large-format-print', 'finish-banner-rods-cord'],
            $requirements->missing($banner, ['rods_cord' => true], []),
        );
        $this->assertSame([], $requirements->missing($banner, ['rods_cord' => false], ['material-frontlight-440g', 'process-large-format-print']));

        $largeFormat = new QuotePreset(['code' => 'product-frontlight-banner']);
        $this->assertEqualsCanonicalizing(
            ['material-frontlight-440g', 'process-large-format-print', 'finish-banner-rods-cord'],
            $requirements->missing($largeFormat, ['material' => 'frontlight-440g', 'finishing' => ['rods-cord']], []),
        );

        $agenda = new QuotePreset(['code' => 'product-agenda-notebook']);
        $this->assertEqualsCanonicalizing(
            ['material-offset-90g', 'process-sheet-print', 'finish-binding-hardcover'],
            $requirements->missing($agenda, ['binding' => 'hardcover'], []),
        );

        $menu = new QuotePreset(['code' => 'product-menu']);
        $this->assertEqualsCanonicalizing(
            ['material-couche-300g', 'process-sheet-print', 'finish-card-lamination', 'finish-binding-wire-o'],
            $requirements->missing($menu, ['laminated' => true, 'binding' => 'wire-o'], []),
        );
    }

    public function test_carbonless_stock_and_numbering_follow_the_selected_configuration(): void
    {
        $requirements = new QuoteComponentRequirements;
        $preset = new QuotePreset(['code' => 'product-carbonless-pads']);
        $base = ['material-carbonless-3-part', 'process-sheet-print'];

        $this->assertEqualsCanonicalizing($base, $requirements->missing($preset, ['copies' => '3', 'sequential_numbering' => false], []));
        $this->assertEqualsCanonicalizing([...$base, 'process-sequential-numbering'], $requirements->missing($preset, ['copies' => '3', 'sequential_numbering' => true], []));
    }

    public function test_gift_and_label_materials_follow_the_wizard_selection(): void
    {
        $requirements = new QuoteComponentRequirements;
        $mug = new QuotePreset(['code' => 'product-mug']);
        $this->assertEqualsCanonicalizing(
            ['material-gift-mug-ceramic', 'process-sublimation'],
            $requirements->missing($mug, ['material' => 'ceramic', 'print_method' => 'sublimation'], []),
        );
        $this->assertEqualsCanonicalizing(
            ['material-gift-mug-polymer', 'process-gift-printing'],
            $requirements->missing($mug, ['material' => 'polymer', 'print_method' => 'other'], []),
        );

        $labels = new QuotePreset(['code' => 'product-labels-roll-sheet']);
        $this->assertEqualsCanonicalizing(
            ['material-label-roll-stock', 'process-label-printing', 'process-label-die-cut'],
            $requirements->missing($labels, ['format' => 'roll'], []),
        );
        $this->assertEqualsCanonicalizing(
            ['material-label-sheet-stock', 'process-label-printing', 'process-label-die-cut'],
            $requirements->missing($labels, ['format' => 'sheet'], []),
        );
    }

    public function test_mutually_exclusive_material_variants_cannot_both_be_charged(): void
    {
        $requirements = new QuoteComponentRequirements;
        $preset = new QuotePreset(['code' => 'product-mug']);
        $answers = ['material' => 'ceramic', 'print_method' => 'sublimation'];
        $components = ['material-gift-mug-ceramic', 'material-gift-mug-polymer', 'process-sublimation'];

        $this->assertSame(
            ['material-gift-mug-polymer'],
            $requirements->conflicting(
                $preset,
                $answers,
                $components,
            ),
        );
        $this->assertSame([], $requirements->conflicting(
            $preset,
            ['material' => 'ceramic', 'print_method' => 'sublimation'],
            ['material-gift-mug-ceramic', 'process-sublimation'],
        ));
    }
}
