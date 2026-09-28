<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Estimador determinístico de encaixe retangular em grade, sem dependência de banco.
 * O resultado é uma estimativa geométrica simples, não uma otimização de nesting.
 */
final class RectangleNestingEstimator
{
    // Limites mantêm todos os produtos usados nos cálculos de área e basis points
    // dentro da faixa segura de inteiros de 64 bits.
    private const MAX_DIMENSION_MM = 10_000;
    private const MAX_QUANTITY = 100_000;

    /**
     * Aceita material_type=sheet|roll e dimensões em milímetros inteiros.
     * Para chapa informe material_length_mm; para bobina, somente material_width_mm.
     * gap_mm é a folga entre peças, não uma margem externa do material.
     *
     * @return array<string, int|string|bool>
     */
    public function estimate(array $input): array
    {
        $type = $input['material_type'] ?? null;
        if (! in_array($type, ['sheet', 'roll'], true)) {
            throw new InvalidArgumentException('material_type deve ser sheet ou roll.');
        }

        $pieceWidth = $this->dimension($input, 'piece_width_mm');
        $pieceLength = $this->dimension($input, 'piece_length_mm');
        $materialWidth = $this->dimension($input, 'material_width_mm');
        $quantity = $this->integer($input, 'quantity', 1, self::MAX_QUANTITY);
        $gap = $this->integer($input, 'gap_mm', 0, self::MAX_DIMENSION_MM, 0);
        $pieceArea = $pieceWidth * $pieceLength;

        if ($type === 'sheet') {
            $materialLength = $this->dimension($input, 'material_length_mm');
            return $this->estimateSheet($pieceWidth, $pieceLength, $materialWidth, $materialLength, $quantity, $gap, $pieceArea);
        }

        return $this->estimateRoll($pieceWidth, $pieceLength, $materialWidth, $quantity, $gap, $pieceArea);
    }

    private function estimateSheet(int $pw, int $pl, int $mw, int $ml, int $quantity, int $gap, int $pieceArea): array
    {
        $normal = $this->sheetLayout($pw, $pl, $mw, $ml, $gap, false);
        $rotated = $this->sheetLayout($pl, $pw, $mw, $ml, $gap, true);
        // Desempate estável: manter a orientação original quando ambas rendem igual.
        $layout = $rotated['per_sheet'] > $normal['per_sheet'] ? $rotated : $normal;
        if ($layout['per_sheet'] === 0) {
            return $this->notFit($pw, $pl, $mw, $ml, $quantity, $gap, $pieceArea, 'sheet');
        }

        $sheets = $this->ceilDiv($quantity, $layout['per_sheet']);
        $consumedArea = $sheets * $mw * $ml;
        $itemArea = $quantity * $pieceArea;
        return [
            'fits' => true,
            'material_type' => 'sheet',
            'piece_width_mm' => $pw,
            'piece_length_mm' => $pl,
            'orientation' => $layout['rotated'] ? 'rotated' : 'original',
            'placed_width_mm' => $layout['width'],
            'placed_length_mm' => $layout['length'],
            'pieces_per_row' => $layout['across'],
            'rows_per_sheet' => $layout['rows'],
            'pieces_per_sheet' => $layout['per_sheet'],
            'sheets_required' => $sheets,
            'roll_length_mm' => 0,
            'piece_area_mm2' => $pieceArea,
            'items_area_mm2' => $itemArea,
            'consumed_area_mm2' => $consumedArea,
            'waste_area_mm2' => $consumedArea - $itemArea,
            'utilization_basis_points' => $this->basisPoints($itemArea, $consumedArea),
        ];
    }

    private function sheetLayout(int $pw, int $pl, int $mw, int $ml, int $gap, bool $rotated): array
    {
        $across = $this->fitCount($mw, $pw, $gap);
        $rows = $this->fitCount($ml, $pl, $gap);
        return [
            'rotated' => $rotated,
            'width' => $pw,
            'length' => $pl,
            'across' => $across,
            'rows' => $rows,
            'per_sheet' => $across * $rows,
        ];
    }

    private function estimateRoll(int $pw, int $pl, int $mw, int $quantity, int $gap, int $pieceArea): array
    {
        $normalAcross = $this->fitCount($mw, $pw, $gap);
        $rotatedAcross = $this->fitCount($mw, $pl, $gap);
        // Na bobina, girar a peça troca a dimensão que ocupa a largura fixa.
        $normalRuns = $normalAcross > 0 ? $this->ceilDiv($quantity, $normalAcross) : null;
        $rotatedRuns = $rotatedAcross > 0 ? $this->ceilDiv($quantity, $rotatedAcross) : null;
        $normalLength = $normalRuns === null ? null : $normalRuns * $pl + ($normalRuns - 1) * $gap;
        $rotatedLength = $rotatedRuns === null ? null : $rotatedRuns * $pw + ($rotatedRuns - 1) * $gap;
        if ($normalLength === null && $rotatedLength === null) {
            return $this->notFit($pw, $pl, $mw, 0, $quantity, $gap, $pieceArea, 'roll');
        }
        // Menor comprimento total reduz a área de bobina estimada; empate mantém a orientação original.
        $rotated = $normalLength === null || ($rotatedLength !== null && $rotatedLength < $normalLength);
        $width = $rotated ? $pl : $pw;
        $length = $rotated ? $pw : $pl;
        $across = $rotated ? $rotatedAcross : $normalAcross;
        // Não adicionamos folga depois da última peça no sentido do comprimento.
        $rollLength = $rotated ? $rotatedLength : $normalLength;
        $consumedArea = $mw * $rollLength;
        $itemArea = $quantity * $pieceArea;
        return [
            'fits' => true,
            'material_type' => 'roll',
            'piece_width_mm' => $pw,
            'piece_length_mm' => $pl,
            'orientation' => $rotated ? 'rotated' : 'original',
            'placed_width_mm' => $width,
            'placed_length_mm' => $length,
            'pieces_per_row' => $across,
            'rows_per_sheet' => 0,
            'pieces_per_sheet' => 0,
            'sheets_required' => 0,
            'roll_length_mm' => $rollLength,
            'piece_area_mm2' => $pieceArea,
            'items_area_mm2' => $itemArea,
            'consumed_area_mm2' => $consumedArea,
            'waste_area_mm2' => $consumedArea - $itemArea,
            'utilization_basis_points' => $this->basisPoints($itemArea, $consumedArea),
        ];
    }

    private function notFit(int $pw, int $pl, int $mw, int $ml, int $quantity, int $gap, int $pieceArea, string $type): array
    {
        return [
            'fits' => false,
            'material_type' => $type,
            'piece_width_mm' => $pw,
            'piece_length_mm' => $pl,
            'orientation' => 'none',
            'placed_width_mm' => 0,
            'placed_length_mm' => 0,
            'pieces_per_row' => 0,
            'rows_per_sheet' => 0,
            'pieces_per_sheet' => 0,
            'sheets_required' => 0,
            'roll_length_mm' => 0,
            'piece_area_mm2' => $pieceArea,
            'items_area_mm2' => $quantity * $pieceArea,
            'consumed_area_mm2' => 0,
            'waste_area_mm2' => 0,
            'utilization_basis_points' => 0,
        ];
    }

    private function fitCount(int $span, int $piece, int $gap): int
    {
        // n peças ocupam n*peça + (n-1)*folga; esta forma evita arredondamento geométrico.
        return intdiv($span + $gap, $piece + $gap);
    }

    private function ceilDiv(int $value, int $divisor): int
    {
        return intdiv($value + $divisor - 1, $divisor);
    }

    private function basisPoints(int $part, int $whole): int
    {
        // Limites da classe garantem que a multiplicação por 10.000 cabe em inteiro.
        return $whole === 0 ? 0 : intdiv(($part * 10_000) + intdiv($whole, 2), $whole);
    }

    private function dimension(array $input, string $key): int
    {
        return $this->integer($input, $key, 1, self::MAX_DIMENSION_MM);
    }

    private function integer(array $input, string $key, int $min, int $max, ?int $default = null): int
    {
        $value = $input[$key] ?? $default;
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException("{$key} deve ser um inteiro entre {$min} e {$max}.");
        }
        return $value;
    }
}
