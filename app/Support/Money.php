<?php

namespace App\Support;

class Money
{
    // Mantém totais exatos em centavos e formata reais sem usar ponto flutuante.
    public static function format(int $cents): string
    {
        $negative = $cents < 0;
        $absoluteCents = abs($cents);
        $whole = (string) intdiv($absoluteCents, 100);
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole);
        $fraction = str_pad((string) ($absoluteCents % 100), 2, '0', STR_PAD_LEFT);

        return ($negative ? '-R$ ' : 'R$ ').$whole.','.$fraction;
    }
}
