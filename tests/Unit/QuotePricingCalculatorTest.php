<?php

namespace Tests\Unit;

use App\Services\QuotePricingCalculator;
use PHPUnit\Framework\TestCase;
use OverflowException;

class QuotePricingCalculatorTest extends TestCase
{
    public function test_money_and_quantities_are_parsed_without_floating_point(): void
    {
        $calculator = new QuotePricingCalculator;

        $this->assertSame(123456, $calculator->moneyCents('1.234,56'));
        $this->assertSame(123456, $calculator->moneyCents('1234.56'));
        $this->assertSame(123400, $calculator->moneyCents('1.234'));
        $this->assertSame(1250, $calculator->quantityMilli('1,25'));
        $this->assertSame(150, $calculator->componentCost(100, 1500));
    }

    public function test_total_applies_explicit_loss_and_markup_factors_with_half_up_rounding(): void
    {
        $calculator = new QuotePricingCalculator;

        $this->assertSame([
            'cost_total_cents' => 263,
            'adjusted_cost_cents' => 289,
            'sale_total_cents' => 578,
        ], $calculator->total(263, 1000, 20000));
        $this->assertSame(1, $calculator->total(1, 0, 5000)['sale_total_cents']);
    }

    public function test_invalid_or_overflowing_money_factors_are_rejected(): void
    {
        $calculator = new QuotePricingCalculator;
        $this->expectException(OverflowException::class);
        $calculator->total(PHP_INT_MAX, 1_000_000, 10_000_000);
    }
}
