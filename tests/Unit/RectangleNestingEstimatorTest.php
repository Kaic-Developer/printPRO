<?php

namespace Tests\Unit;

use App\Services\RectangleNestingEstimator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RectangleNestingEstimatorTest extends TestCase
{
    public function test_sheet_500_by_700_fits_six_per_sheet_on_1220_by_2440(): void
    {
        $result = (new RectangleNestingEstimator)->estimate([
            'material_type' => 'sheet',
            'piece_width_mm' => 500,
            'piece_length_mm' => 700,
            'material_width_mm' => 1220,
            'material_length_mm' => 2440,
            'quantity' => 7,
        ]);

        self::assertTrue($result['fits']);
        self::assertSame('original', $result['orientation']);
        self::assertSame(2, $result['pieces_per_row']);
        self::assertSame(3, $result['rows_per_sheet']);
        self::assertSame(6, $result['pieces_per_sheet']);
        self::assertSame(2, $result['sheets_required']);
        self::assertSame(350_000, $result['piece_area_mm2']);
        self::assertSame(5_953_600, $result['consumed_area_mm2']);
    }

    public function test_sheet_rotation_is_evaluated_and_selects_higher_grid_yield(): void
    {
        $result = (new RectangleNestingEstimator)->estimate([
            'material_type' => 'sheet',
            'piece_width_mm' => 700,
            'piece_length_mm' => 500,
            'material_width_mm' => 1220,
            'material_length_mm' => 2440,
            'quantity' => 1,
        ]);

        self::assertSame('rotated', $result['orientation']);
        self::assertSame(2, $result['pieces_per_row']);
        self::assertSame(3, $result['rows_per_sheet']);
        self::assertSame(6, $result['pieces_per_sheet']);
    }

    public function test_roll_uses_configured_1520_mm_width_and_reports_consumed_length(): void
    {
        $result = (new RectangleNestingEstimator)->estimate([
            'material_type' => 'roll',
            'piece_width_mm' => 500,
            'piece_length_mm' => 700,
            'material_width_mm' => 1520,
            'quantity' => 7,
        ]);

        self::assertTrue($result['fits']);
        self::assertSame(3, $result['pieces_per_row']);
        self::assertSame(2_100, $result['roll_length_mm']);
        self::assertSame(700, $result['placed_length_mm']);
        self::assertSame(3_192_000, $result['consumed_area_mm2']);
    }

    public function test_roll_rotates_when_that_places_more_items_across_web(): void
    {
        $result = (new RectangleNestingEstimator)->estimate([
            'material_type' => 'roll',
            'piece_width_mm' => 700,
            'piece_length_mm' => 500,
            'material_width_mm' => 1520,
            'quantity' => 4,
        ]);

        self::assertSame('rotated', $result['orientation']);
        self::assertSame(3, $result['pieces_per_row']);
        self::assertSame(1_400, $result['roll_length_mm']);
    }

    public function test_gap_is_applied_between_items_and_not_after_last_item(): void
    {
        $result = (new RectangleNestingEstimator)->estimate([
            'material_type' => 'roll',
            'piece_width_mm' => 500,
            'piece_length_mm' => 700,
            'material_width_mm' => 1520,
            'quantity' => 4,
            'gap_mm' => 20,
        ]);

        self::assertSame(2, $result['pieces_per_row']);
        self::assertSame(1420, $result['roll_length_mm']);
        self::assertSame(2_158_400, $result['consumed_area_mm2']);
    }

    public function test_reports_when_piece_cannot_fit_in_either_sheet_orientation(): void
    {
        $result = (new RectangleNestingEstimator)->estimate([
            'material_type' => 'sheet',
            'piece_width_mm' => 1300,
            'piece_length_mm' => 2500,
            'material_width_mm' => 1220,
            'material_length_mm' => 2440,
            'quantity' => 1,
        ]);

        self::assertFalse($result['fits']);
        self::assertSame('none', $result['orientation']);
        self::assertSame(0, $result['sheets_required']);
    }

    #[DataProvider('invalidInputProvider')]
    public function test_rejects_invalid_dimensions_quantities_and_gap(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RectangleNestingEstimator)->estimate($input);
    }

    public static function invalidInputProvider(): array
    {
        $base = [
            'material_type' => 'sheet',
            'piece_width_mm' => 500,
            'piece_length_mm' => 700,
            'material_width_mm' => 1220,
            'material_length_mm' => 2440,
            'quantity' => 1,
        ];

        return [
            'zero dimension' => [['material_type' => 'roll', 'piece_width_mm' => 0, 'piece_length_mm' => 700, 'material_width_mm' => 1520, 'quantity' => 1]],
            'fractional dimension' => [['material_type' => 'roll', 'piece_width_mm' => 500.5, 'piece_length_mm' => 700, 'material_width_mm' => 1520, 'quantity' => 1]],
            'negative quantity' => [['material_type' => 'roll', 'piece_width_mm' => 500, 'piece_length_mm' => 700, 'material_width_mm' => 1520, 'quantity' => 0]],
            'unknown material' => [['material_type' => 'sheetish', 'piece_width_mm' => 500, 'piece_length_mm' => 700, 'material_width_mm' => 1220, 'material_length_mm' => 2440, 'quantity' => 1]],
            'negative gap' => [['material_type' => 'sheet', 'piece_width_mm' => 500, 'piece_length_mm' => 700, 'material_width_mm' => 1220, 'material_length_mm' => 2440, 'quantity' => 1, 'gap_mm' => -1]],
            'missing sheet length' => [['material_type' => 'sheet', 'piece_width_mm' => 500, 'piece_length_mm' => 700, 'material_width_mm' => 1220, 'quantity' => 1]],
            'dimension over safe maximum' => [array_replace($base, ['piece_width_mm' => 10_001])],
        ];
    }
}
