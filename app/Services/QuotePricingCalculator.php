<?php

namespace App\Services;

use InvalidArgumentException;
use OverflowException;

/** Dinheiro e quantidades permanecem inteiros escalados do início ao fim. */
final class QuotePricingCalculator
{
    public function quantityMilli(string|int $value): int
    {
        if (! preg_match('/\A(\d{1,9})(?:[.,](\d{1,3}))?\z/', (string) $value, $parts)) {
            throw new InvalidArgumentException('Informe uma quantidade com até três casas decimais.');
        }

        $whole = (int) $parts[1];
        $fraction = (int) str_pad($parts[2] ?? '', 3, '0');
        $quantity = $whole * 1000 + $fraction;
        if ($quantity < 1 || $quantity > 1_000_000_000) {
            throw new InvalidArgumentException('A quantidade precisa ser maior que zero e estar dentro do limite permitido.');
        }

        return $quantity;
    }

    public function moneyCents(string|int $value): int
    {
        $value = trim((string) $value);
        if (! preg_match('/\A(?:\d{1,15}|\d{1,3}(?:\.\d{3}){1,4})(?:[,.]\d{1,2})?\z/', $value)) {
            throw new InvalidArgumentException('Informe um valor em reais com até duas casas decimais.');
        }

        // Ponto sozinho com três casas é agrupamento de milhar no formato local, não fração.
        $normalized = str_contains($value, ',') || preg_match('/\A\d{1,3}(?:\.\d{3}){1,4}\z/', $value)
            ? str_replace('.', '', $value)
            : $value;
        [$whole, $fraction] = array_pad(preg_split('/[.,]/', $normalized, 2), 2, '0');
        $whole = ltrim($whole, '0') ?: '0';
        if (strlen($whole) > 13) {
            throw new InvalidArgumentException('O valor informado excede o limite permitido.');
        }

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public function scaledBasisPoints(string|int $value, int $max): int
    {
        $value = trim((string) $value);
        if (! preg_match('/\A\d{1,7}(?:[.,]\d{1,2})?\z/', $value)) {
            throw new InvalidArgumentException('Informe um percentual ou multiplicador com até duas casas decimais.');
        }

        [$whole, $fraction] = array_pad(preg_split('/[.,]/', $value, 2), 2, '0');
        $scaled = (int) $whole * 10_000 + (int) str_pad($fraction, 2, '0') * 100;
        if ($scaled > $max) {
            throw new InvalidArgumentException('O fator informado excede o limite permitido.');
        }

        return $scaled;
    }

    public function percentageBasisPoints(string|int $value, int $max): int
    {
        $value = trim((string) $value);
        if (! preg_match('/\A\d{1,7}(?:[.,]\d{1,2})?\z/', $value)) {
            throw new InvalidArgumentException('Informe um percentual com até duas casas decimais.');
        }
        [$whole, $fraction] = array_pad(preg_split('/[.,]/', $value, 2), 2, '0');
        $basisPoints = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($basisPoints > $max) {
            throw new InvalidArgumentException('O percentual informado excede o limite permitido.');
        }
        return $basisPoints;
    }

    public function componentCost(int $unitCostCents, int $quantityMilli): int
    {
        // Decompor a divisão evita multiplicar valores monetários grandes antes do arredondamento.
        $whole = intdiv($unitCostCents, 1000);
        $remainder = $unitCostCents % 1000;
        $this->assertMultiplicationSafe($whole, $quantityMilli);
        $base = $whole * $quantityMilli;
        $fractionNumerator = $remainder * $quantityMilli;
        return $this->safeAdd($base, intdiv($fractionNumerator + 500, 1000));
    }

    public function multiplyMilli(int $perUnitMilli, int $itemsMilli): int
    {
        $whole = intdiv($perUnitMilli, 1000);
        $remainder = $perUnitMilli % 1000;
        $this->assertMultiplicationSafe($whole, $itemsMilli);
        return $this->safeAdd($whole * $itemsMilli, intdiv($remainder * $itemsMilli + 500, 1000));
    }

    /**
     * Fórmula comercial solicitada: (soma dos custos × (1 + perda)) × multiplicador.
     * O arredondamento half-up ocorre em centavos após cada fator explícito.
     *
     * @return array{cost_total_cents:int, adjusted_cost_cents:int, sale_total_cents:int}
     */
    public function total(int $costCents, int $wasteBasisPoints, int $markupMultiplierBasisPoints): array
    {
        if ($costCents < 1 || $wasteBasisPoints < 0 || $markupMultiplierBasisPoints < 1) {
            throw new InvalidArgumentException('Custos, perda e multiplicador precisam estar configurados para calcular.');
        }

        $adjusted = $this->multiplyRatioRounded($costCents, 10_000 + $wasteBasisPoints, 10_000);
        $sale = $this->multiplyRatioRounded($adjusted, $markupMultiplierBasisPoints, 10_000);

        return ['cost_total_cents' => $costCents, 'adjusted_cost_cents' => $adjusted, 'sale_total_cents' => $sale];
    }

    public function addCents(int $left, int $right): int
    {
        if ($left < 0 || $right < 0) {
            throw new InvalidArgumentException('Os custos não podem ser negativos.');
        }
        return $this->safeAdd($left, $right);
    }

    private function multiplyRatioRounded(int $value, int $numerator, int $denominator): int
    {
        $whole = intdiv($value, $denominator);
        $remainder = $value % $denominator;
        $this->assertMultiplicationSafe($whole, $numerator);
        $base = $whole * $numerator;
        $fraction = intdiv($remainder * $numerator + intdiv($denominator, 2), $denominator);

        return $this->safeAdd($base, $fraction);
    }

    private function assertMultiplicationSafe(int $left, int $right): void
    {
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw new OverflowException('O total ultrapassa o limite de cálculo seguro.');
        }
    }

    private function safeAdd(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new OverflowException('O total ultrapassa o limite de cálculo seguro.');
        }

        return $left + $right;
    }
}
