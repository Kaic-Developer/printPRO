<?php

namespace Tests\Unit;

use App\Models\QuotePreset;
use App\Services\QuoteComponentRequirements;
use PHPUnit\Framework\TestCase;

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
}
