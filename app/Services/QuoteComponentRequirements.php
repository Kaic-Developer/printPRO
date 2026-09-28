<?php

namespace App\Services;

use App\Models\QuotePreset;
use Illuminate\Validation\ValidationException;

/** Liga escolhas críticas do wizard à ficha técnica sem presumir rendimento. */
final class QuoteComponentRequirements
{
    public function missing(QuotePreset $product, array $answers, array $selectedCodes): array
    {
        $required = match ($product->code) {
            'sign-facade' => $this->facade($answers),
            'product-frontlight-banner' => $this->banner($answers),
            'uniform-polo' => $this->polo($answers),
            'print-business-card' => $this->businessCard($answers),
            'product-presentation-folder' => $this->presentationFolder($answers),
            default => [],
        };

        return array_values(array_diff(array_unique($required), $selectedCodes));
    }

    private function facade(array $answers): array
    {
        $required = ['material-acm-'.($answers['acm_thickness'] ?? ''), 'material-metal-tube-'.($answers['structure_tube'] ?? ''), 'process-welding'];
        $required = [...$required, ...match ($answers['lighting'] ?? 'none') {
            'led-12v' => ['material-led-module', 'material-led-power-supply-12v'],
            'led-24v' => ['material-led-module', 'material-led-power-supply-24v'],
            'projector' => ['material-projector'], 'neon' => ['material-neon-led'], default => [],
        }];
        foreach ([
            'anti_rust_paint' => 'finish-structural-paint', 'requires_munk' => 'third-party-munk',
            'requires_scaffold' => 'third-party-scaffold', 'height_installation' => 'process-installation-height',
            'cnc_outsourced' => 'third-party-cnc', 'galvanizing_outsourced' => 'third-party-galvanizing',
        ] as $field => $code) {
            if ($this->isTrue($answers[$field] ?? false)) $required[] = $code;
        }
        return $required;
    }

    /** Vincula substrato, processo e acabamentos selecionados aos componentes precificáveis. */
    private function businessCard(array $answers): array
    {
        $selectedFinishes = (array) ($answers['finishes'] ?? []);
        if (in_array('matte', $selectedFinishes, true) && in_array('soft-touch', $selectedFinishes, true)) {
            throw ValidationException::withMessages(['answers.finishes' => 'Escolha laminação fosca ou soft touch, não as duas.']);
        }

        $stock = match ($answers['stock'] ?? '') {
            'couche-250g' => 'material-cardstock-250g',
            'couche-300g' => 'material-cardstock-300g',
            'pvc-075' => 'material-card-pvc-075',
            default => '',
        };
        $finishes = [];
        foreach ($selectedFinishes as $finish) {
            $code = match ($finish) {
                'matte', 'soft-touch' => 'finish-card-lamination',
                'uv-varnish' => 'finish-uv-varnish',
                'hot-stamping' => 'finish-hot-stamping',
                'rounded-corners' => 'finish-rounded-corners',
                default => '',
            };
            if ($code !== '') $finishes[] = $code;
        }
        if ($this->isTrue($answers['special_die'] ?? false)) $finishes[] = 'process-special-die';

        return [$stock, 'process-sheet-print', ...$finishes];
    }

    /** Uma pasta depende do estoque escolhido e dos acabamentos efetivamente marcados. */
    private function presentationFolder(array $answers): array
    {
        $stock = match ($answers['stock'] ?? '') {
            'couche-300g' => 'material-couche-300g',
            'offset-90g' => 'material-offset-90g',
            default => '',
        };
        if ($this->isTrue($answers['pocket_ear'] ?? false) && ! $this->isTrue($answers['pocket'] ?? false)) {
            throw ValidationException::withMessages(['answers.pocket_ear' => 'A orelha só pode ser incluída junto com a bolsa da pasta.']);
        }

        $required = [$stock, 'process-sheet-print'];
        if ($this->isTrue($answers['pocket'] ?? false)) $required[] = 'finish-folder-pocket';
        if ($this->isTrue($answers['die_cut'] ?? false)) $required[] = 'process-die-cut-crease';
        if ($this->isTrue($answers['lamination'] ?? false)) $required[] = 'finish-card-lamination';

        return $required;
    }

    private function banner(array $answers): array
    {
        return [match ($answers['material'] ?? '') {
            'frontlight-440g' => 'material-frontlight-440g', 'frontlight-500g' => 'material-frontlight-500g',
            'backlight' => 'material-backlight', 'mesh' => 'material-mesh',
            'sublimation-fabric' => 'material-sublimation-fabric', default => '',
        }, 'process-large-format-print'];
    }

    private function polo(array $answers): array
    {
        $fabric = match ($answers['fabric'] ?? '') {
            'piquet' => 'material-piquet', 'dryfit' => 'material-dryfit',
            'cotton' => 'material-cotton-menegotti', default => '',
        };
        $process = match ($answers['personalization'] ?? '') {
            'silk-screen' => ['process-silk-screen', 'material-silk-screen-screen', 'material-silk-screen-film', 'material-silk-screen-ink'],
            'embroidery' => ['process-computerized-embroidery', 'third-party-embroidery-matrix'],
            'dtf' => ['process-dtf-print-size', 'material-textile-dtf-transfer'],
            'textile-vinyl' => ['process-textile-vinyl', 'material-textile-vinyl'], default => [],
        };
        return [$fabric, ...$process];
    }

    private function isTrue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
